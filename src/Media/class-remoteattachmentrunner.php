<?php
/**
 * Runs RemoteAttachmentCreator over a set of products.
 *
 * @package    fa-toolkit
 * @since 1.2.1
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The product loop shared by `wp fa:media create-remote-attachments` and the
 * after-import listener.
 *
 * Owns product selection, the reachability probe with its suspect-shape
 * shortcut, the (product, sha256) finder, and the run totals. Per-product
 * attachment logic stays in RemoteAttachmentCreator. Neither caller carries
 * logic the other lacks (issue #24 ruling).
 */
class RemoteAttachmentRunner {

	/**
	 * URL shapes known to contain dead links, and therefore worth probing.
	 *
	 * Measured over the whole catalogue: `s3/files/Products` and
	 * `s3/files/<yyyy>` returned 160/160 reachable, while `s3/<yyyy>/<n>`
	 * returned 68/80. Probing only the suspect shape turns a 28,786-request
	 * pre-flight into roughly 3,300.
	 *
	 * @var string
	 */
	private const SUSPECT_SHAPE = '#/s3/20\d{2}/\d#';

	/**
	 * Reachability results for this run, successes only.
	 *
	 * Failures are deliberately NOT cached. A cached failure would outlive the
	 * upstream URL repair, so the re-run that is supposed to heal a product
	 * would skip it instead.
	 *
	 * @var array<string, bool>
	 */
	private $reachable = array();

	/**
	 * Products carrying a non-empty `_fa_media` cell, ascending by id.
	 *
	 * Selected by the PRESENCE OF `_fa_media`, deliberately, and never by an
	 * Akeneo uuid. The content import writes `_fa_media` keyed on
	 * `_fa_akeneo_uuid` and aborts rather than guess when that is blank, so a
	 * product carrying the cell is by construction a product the import
	 * positively identified. A uuid check is exactly the place someone later
	 * adds an `_akeneo_uuid` fallback (60 legacy duplicates carry only that
	 * key) and starts writing attachments onto both halves of every pair.
	 *
	 * @param int $limit Maximum products, 0 for all.
	 * @return array<int, int>
	 */
	public function products_with_media( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fa_media' AND meta_value <> '' ORDER BY post_id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql . $this->limit_clause( $limit ) ) );
	}

	/**
	 * Products carrying media that have no pointer attachment yet.
	 *
	 * This is the after-import selection: a content import only pays for
	 * products whose media has never produced an attachment. Products already
	 * processed are left to the operator's CLI run, which also does the
	 * dead-URL healing.
	 *
	 * @param int $limit Maximum products, 0 for all.
	 * @return array<int, int>
	 */
	public function unattached_products_with_media( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT m.post_id FROM {$wpdb->postmeta} m
			WHERE m.meta_key = '_fa_media' AND m.meta_value <> ''
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->posts} a
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = a.ID AND s.meta_key = '_fa_media_sha256'
				WHERE a.post_type = 'attachment' AND a.post_parent = m.post_id
			)
			ORDER BY m.post_id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql . $this->limit_clause( $limit ) ) );
	}

	/**
	 * Count products with no `_fa_media` key at all.
	 *
	 * An unmapped import profile leaves the key absent rather than empty, so
	 * this number is how "the column never landed" becomes visible instead of
	 * reading as "no media for these products".
	 *
	 * @return int
	 */
	public function products_without_media_cell() {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private', 'pending')
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_fa_media'
			)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Find an attachment already created for a (product, sha) pair.
	 *
	 * @param int    $product_id Product post id.
	 * @param string $sha        Image sha256.
	 * @return int Attachment id, or 0.
	 */
	public function find_existing( $product_id, $sha ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fa_media_sha256'
				 WHERE p.post_type = 'attachment' AND p.post_parent = %d AND m.meta_value = %s
				 LIMIT 1",
				$product_id,
				$sha
			)
		);
	}

	/**
	 * Run the creator over the given products.
	 *
	 * @param array<int, int> $product_ids Products to process.
	 * @param bool            $dry_run     Report without writing.
	 * @param bool            $recheck_all Probe every URL, not only suspect shapes.
	 * @param callable|null   $tick        Called once after each product.
	 * @return array{products:int,created:int,existing:int,unreachable:int,failed:int,no_media:int,stranded:array<int,int>}
	 */
	public function run( array $product_ids, $dry_run = false, $recheck_all = false, $tick = null ) {
		$creator = new RemoteAttachmentCreator(
			array( $this, 'find_existing' ),
			function ( $url ) use ( $recheck_all ) {
				return $this->is_reachable( $url, $recheck_all );
			}
		);
		$creator->set_dry_run( (bool) $dry_run );

		$totals = array(
			'products'    => count( $product_ids ),
			'created'     => 0,
			'existing'    => 0,
			'unreachable' => 0,
			'failed'      => 0,
			'no_media'    => 0,
			'stranded'    => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$raw    = get_post_meta( $product_id, '_fa_media', true );
			$result = $creator->create_for_product( $product_id, (string) $raw );

			$totals['created']     += $result['created'];
			$totals['existing']    += $result['existing'];
			$totals['unreachable'] += $result['unreachable'];
			$totals['failed']      += $result['failed'];

			if ( true === $result['no_usable_image'] ) {
				$totals['stranded'][] = $product_id;
			} elseif ( 0 === $result['created'] + $result['existing'] + $result['unreachable'] + $result['failed'] ) {
				// Nothing to do and nothing wrong: the cell parsed to no images.
				++$totals['no_media'];
			}

			if ( null !== $tick ) {
				call_user_func( $tick, $product_id, $result );
			}
		}

		return $totals;
	}

	/**
	 * Whether a URL currently resolves.
	 *
	 * @param string $url         URL to check.
	 * @param bool   $recheck_all Probe every shape, not only suspect ones.
	 * @return bool
	 */
	private function is_reachable( $url, $recheck_all ) {
		if ( true !== $recheck_all && 1 !== preg_match( self::SUSPECT_SHAPE, $url ) ) {
			return true;
		}

		if ( isset( $this->reachable[ $url ] ) ) {
			return true;
		}

		// A tiny transform rather than the original: ImageKit 404s on a missing
		// source whatever the transform, so this answers the same question for
		// a fraction of the bytes. The separator is chosen, not assumed: a
		// second "?" on a URL that already carries a query string is a 4xx
		// that would read as a dead image.
		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		$response = wp_remote_get(
			$url . $separator . 'tr=w-10',
			array( 'timeout' => 20 )
		);

		$ok = 200 === (int) wp_remote_retrieve_response_code( $response );

		if ( true === $ok ) {
			$this->reachable[ $url ] = true;
		}

		return $ok;
	}

	/**
	 * A prepared LIMIT clause, or nothing.
	 *
	 * @param int $limit Maximum rows, 0 for no limit.
	 * @return string
	 */
	private function limit_clause( $limit ) {
		global $wpdb;

		$limit = (int) $limit;

		return $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
	}
}
