<?php
/**
 * WP-CLI command creating pointer attachments from `_fa_media`.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Media\RemoteAttachmentCreator;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Creates WooCommerce attachments for images hosted on s3.
 */
class CreateRemoteAttachmentsCommand {

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
	 * would skip it instead — silently, and only for the images that matter.
	 *
	 * @var array<string, bool>
	 */
	private $reachable = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		\WP_CLI::add_command( 'fa:media create-remote-attachments', array( $this, 'create' ) );
	}

	/**
	 * Create attachments for products carrying `_fa_media`.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after this many products.
	 *
	 * [--product=<id>]
	 * : Only this product id. Repeatable as a comma-separated list.
	 *
	 * [--dry-run]
	 * : Report without writing.
	 *
	 * [--recheck-all]
	 * : Probe every URL, not only the shapes known to contain dead links.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		global $wpdb;

		$limit       = (int) ( $assoc_args['limit'] ?? 0 );
		$dry_run     = isset( $assoc_args['dry-run'] );
		$recheck_all = isset( $assoc_args['recheck-all'] );

		$product_ids = $this->target_products( $assoc_args, $limit );

		if ( array() === $product_ids ) {
			\WP_CLI::warning( 'No products carry _fa_media. Has Import A run with the media column?' );
			return;
		}

		$creator = new RemoteAttachmentCreator(
			array( $this, 'find_existing' ),
			function ( $url ) use ( $recheck_all ) {
				return $this->is_reachable( $url, $recheck_all );
			}
		);
		$creator->set_dry_run( $dry_run );

		$totals  = array( 'created' => 0, 'existing' => 0, 'unreachable' => 0 );
		$stranded = array();

		$progress = \WP_CLI\Utils\make_progress_bar( 'Creating attachments', count( $product_ids ) );

		foreach ( $product_ids as $product_id ) {
			$raw    = get_post_meta( $product_id, '_fa_media', true );
			$result = $creator->create_for_product( $product_id, (string) $raw );

			$totals['created']     += $result['created'];
			$totals['existing']    += $result['existing'];
			$totals['unreachable'] += $result['unreachable'];

			if ( true === $result['no_usable_image'] ) {
				$stranded[] = $product_id;
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::log(
			sprintf(
				'products %d | attachments created %d | already present %d | unreachable urls %d',
				count( $product_ids ),
				$totals['created'],
				$totals['existing'],
				$totals['unreachable']
			)
		);

		// Reported, never silent. These products show WooCommerce's
		// placeholder and stay that way until the URLs are repaired upstream,
		// at which point a re-run picks them up.
		if ( array() !== $stranded ) {
			\WP_CLI::warning( sprintf( '%d products have no reachable image:', count( $stranded ) ) );
			\WP_CLI::log( implode( ',', $stranded ) );
		}

		if ( true === $dry_run ) {
			\WP_CLI::success( 'Dry run: nothing was written.' );
			return;
		}

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Products to process.
	 *
	 * @param array $assoc_args Flags.
	 * @param int   $limit      Maximum products, 0 for all.
	 * @return array<int, int>
	 */
	private function target_products( array $assoc_args, $limit ) {
		global $wpdb;

		if ( isset( $assoc_args['product'] ) ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['product'] ) ) ) );
		}

		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_fa_media' AND meta_value <> '' ORDER BY post_id ASC";

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql ) );
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
		// a fraction of the bytes.
		// Separator chosen, not assumed: appending "?tr=" to a URL that already
		// carries a query string produces a second "?" and a 4xx, which would
		// read as a dead image rather than a malformed request.
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
}
