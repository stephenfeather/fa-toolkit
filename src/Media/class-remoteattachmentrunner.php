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
	 * Product postmeta holding the sha256 of the last `_fa_media` cell applied
	 * with no insert failures.
	 *
	 * @var string
	 */
	public const APPLIED_MARKER = '_fa_media_applied_sha256';

	/**
	 * Product postmeta holding when (UTC, `Y-m-d H:i:s`) the product's last
	 * real pass had a probe with no definite answer. Cleared by the next pass
	 * without one. Only orders the after-import selection (issue #97).
	 *
	 * @var string
	 */
	public const PROBE_FAILED_AT = '_fa_media_probe_failed_at';

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
	 * Products whose current `_fa_media` cell has not been applied, ascending by id.
	 *
	 * This is the after-import selection (issue #93). A product is selected
	 * when it carries no applied marker, or when its marker no longer matches
	 * the sha256 of its cell. So later changes such as added dimensions or a
	 * new gallery entry reach WordPress without an operator run, and a
	 * re-import of an unchanged cell costs nothing. Selecting on "has no
	 * pointer attachment" instead left every processed product frozen at its
	 * first cell.
	 *
	 * A product is skipped only when a marker row exists whose value equals
	 * the cell's sha256: no row (never applied) and a different value (stale)
	 * both select it. The comparison and the cap run in MySQL, so a capped run
	 * never materialises every product carrying media.
	 *
	 * Order: products with no recorded probe failure first, by id; then those
	 * whose last pass failed a probe, oldest failure first (issue #97). A URL
	 * that fails on every attempt keeps its product unmarked (#95), and under
	 * a plain id order it would take a slot at the head of every capped run.
	 * Sorted behind fresh work it is still retried whenever the cap leaves
	 * room, so it heals without ever being given up on.
	 *
	 * @param int $limit Maximum products, 0 for all.
	 * @return array<int, int>
	 */
	public function products_with_unapplied_media( $limit = 0 ) {
		global $wpdb;

		$sql = "SELECT m.post_id FROM {$wpdb->postmeta} m
			LEFT JOIN {$wpdb->postmeta} pf ON pf.post_id = m.post_id AND pf.meta_key = '" . self::PROBE_FAILED_AT . "'
			WHERE m.meta_key = '_fa_media' AND m.meta_value <> ''
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} a
				WHERE a.post_id = m.post_id AND a.meta_key = '" . self::APPLIED_MARKER . "'
				AND a.meta_value = SHA2(m.meta_value, 256)
			)
			ORDER BY pf.meta_value IS NOT NULL ASC, pf.meta_value ASC, m.post_id ASC";

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
	 * @return array{products:int,created:int,existing:int,unreachable:int,probe_failed:int,failed:int,write_failed:int,no_media:int,stranded:array<int,int>}
	 */
	public function run( array $product_ids, $dry_run = false, $recheck_all = false, $tick = null ) {
		$creator = new RemoteAttachmentCreator(
			array( $this, 'find_existing' ),
			function ( $url ) use ( $recheck_all ) {
				return $this->probe( $url, $recheck_all );
			}
		);
		$creator->set_dry_run( (bool) $dry_run );

		$totals = array(
			'products'     => count( $product_ids ),
			'created'      => 0,
			'existing'     => 0,
			'unreachable'  => 0,
			'probe_failed' => 0,
			'failed'       => 0,
			'write_failed' => 0,
			'no_media'     => 0,
			'stranded'     => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$raw    = get_post_meta( $product_id, '_fa_media', true );
			$result = $creator->create_for_product( $product_id, (string) $raw );

			$totals['created']      += $result['created'];
			$totals['existing']     += $result['existing'];
			$totals['unreachable']  += $result['unreachable'];
			$totals['probe_failed'] += $result['probe_failed'];
			$totals['failed']       += $result['failed'];
			$totals['write_failed'] += $result['write_failed'];

			// Recorded only when nothing failed. An insert, meta write or probe
			// failure is about this run and retryable, so that product must stay
			// selectable: a marker over a half-applied cell would skip it for
			// good (issues #93, #95). A dead URL is not a failure: marking it
			// keeps the listener from re-probing it on every import. Healing it
			// stays with the operator's CLI run.
			if ( true !== $dry_run && 0 === $result['failed'] + $result['write_failed'] + $result['probe_failed'] ) {
				update_post_meta( $product_id, self::APPLIED_MARKER, hash( 'sha256', (string) $raw ) );
			}

			// When a probe went unanswered, note when, so the next selection sorts
			// this product behind fresh work; any pass without one clears it
			// (issue #97). Neither touches wiring or the marker.
			if ( true !== $dry_run && 0 < $result['probe_failed'] ) {
				update_post_meta( $product_id, self::PROBE_FAILED_AT, current_time( 'mysql', true ) );
			} elseif ( true !== $dry_run ) {
				delete_post_meta( $product_id, self::PROBE_FAILED_AT );
			}

			if ( true === $result['no_usable_image'] ) {
				$totals['stranded'][] = $product_id;
			} elseif ( 0 === $result['created'] + $result['existing'] + $result['unreachable'] + $result['probe_failed'] + $result['failed'] ) {
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
	 * What a URL currently answers: ok, dead, or no definite answer.
	 *
	 * Only a 404 or 410 is dead. A timeout, a refused connection, a 429 or a
	 * 5xx says nothing about the image. Treating those as dead cleared a valid
	 * thumbnail on local staging and marked the product applied, so nothing
	 * retried it (issue #95, products 865 and 6994).
	 *
	 * @param string $url         URL to check.
	 * @param bool   $recheck_all Probe every shape, not only suspect ones.
	 * @return string One of RemoteAttachmentCreator::PROBE_*.
	 */
	private function probe( $url, $recheck_all ) {
		if ( true !== $recheck_all && 1 !== preg_match( self::SUSPECT_SHAPE, $url ) ) {
			return RemoteAttachmentCreator::PROBE_OK;
		}

		if ( isset( $this->reachable[ $url ] ) ) {
			return RemoteAttachmentCreator::PROBE_OK;
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

		// A WP_Error (timeout, refused connection) yields '' here, which casts
		// to 0 and lands with the other non-definite answers.
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$this->reachable[ $url ] = true;
			return RemoteAttachmentCreator::PROBE_OK;
		}

		// Neither answer is cached: a dead URL may be repaired upstream, and a
		// failed probe may answer next time.
		return in_array( $code, array( 404, 410 ), true ) ? RemoteAttachmentCreator::PROBE_DEAD : RemoteAttachmentCreator::PROBE_FAILED;
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
