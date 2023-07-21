<?php
/**
 * Export the contents of an Advanced Custom Field called image_source from all products with a draft status to a file.
 *
 * @package FA-Toolkit
 * @since 1.0
 *
 * TODO: Refactor this into a class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! function_exists( 'wp_cli_export_draft_product_image_sources' ) ) {

	/**
	 * Export the contents of an Advanced Custom Field called image_source from all products with a draft status to a file.
	 *
	 * [--output_file=<filename>]
	 * : Allows alternate output file
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Arguments passed to the WP-CLI command.
	 */
	function wp_cli_export_draft_product_image_sources( $args ) {
		if ( ! class_exists( 'acf' ) ) {
			WP_CLI::error( 'Advanced Custom Fields is not installed or active.' );
		}

		$output_file = isset( $args[0] ) ? $args[0] : 'draft-product-image-sources.txt';

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		$products = get_posts( $args );

			$output = '';
		foreach ( $products as $product_id ) {
			$image_source = get_field( 'image_source', $product_id );
			WP_CLI::debug( "Image Source for {$product_id}: {$image_source}" );
			if ( $image_source ) {
				WP_CLI::debug( "Image Source for {$product_id}: " );
				$output .= $image_source . "\n";
			}
		}

		if ( ! empty( $output ) ) {
			$result = $wp_filesystem->put_contents( $output_file, $output );

			if ( false !== $result ) {
				WP_CLI::success( 'Draft product image sources exported to ' . $output_file . '.' );
			} else {
				WP_CLI::error( 'Error exporting draft product image sources to ' . $output_file . '.' );
			}
		} else {
			WP_CLI::error( 'No draft product image sources found.' );
		}

		wp_reset_postdata();
	}

	WP_CLI::add_command( 'fa:media export-draft-product-image-sources', 'wp_cli_export_draft_product_image_sources' );
}
