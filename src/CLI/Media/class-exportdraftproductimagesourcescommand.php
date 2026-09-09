<?php
/**
 * CLI command to export image sources from draft products.
 *
 * @package FA-Toolkit
 * @since 1.2.0
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Utilities\Helpers;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Exports image source URLs from draft WooCommerce products.
 */
class ExportDraftProductImageSourcesCommand {

	/**
	 * Constructor - Register WP-CLI command.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fa:media export-draft-product-image-sources', array( $this, 'execute' ) );
		}
	}

	/**
	 * Export the contents of an Advanced Custom Field called image_source from all products with a draft status to a file.
	 *
	 * ## OPTIONS
	 *
	 * [<output_file>]
	 * : Output filename (default: draft-product-image-sources.txt).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:media export-draft-product-image-sources
	 *     wp fa:media export-draft-product-image-sources images.txt
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function execute( $args, $assoc_args ) {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		if ( ! class_exists( 'acf' ) ) {
			\WP_CLI::error( 'Advanced Custom Fields is not installed or active.' );
		}

		$output_file = $args[0] ?? 'draft-product-image-sources.txt';

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		$products = get_posts( $query_args );
		$output   = '';

		foreach ( $products as $product_id ) {
			$image_source = get_field( 'image_source', $product_id );
			\WP_CLI::debug( "Image Source for {$product_id}: {$image_source}" );

			if ( false === Helpers::is_empty( $image_source ) ) {
				$output .= $image_source . "\n";
			}
		}

		if ( ! empty( $output ) ) {
			$result = $wp_filesystem->put_contents( $output_file, $output );

			if ( false !== $result ) {
				\WP_CLI::success( 'Draft product image sources exported to ' . $output_file . '.' );
			} else {
				\WP_CLI::error( 'Error exporting draft product image sources to ' . $output_file . '.' );
			}
		} else {
			\WP_CLI::error( 'No draft product image sources found.' );
		}

		wp_reset_postdata();
	}
}
