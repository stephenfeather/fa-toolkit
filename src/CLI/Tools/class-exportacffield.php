<?php
/**
 * Adds a WP-CLI command fa:tools export-acf-field.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

namespace FAToolkit\CLI\Tools;

// Dont load directly.
if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

// Dont load if WP_CLI is not defined.
if ( false === \FAToolkit\Utilities\Helpers::is_wp_cli() ) {
	return;
}

use WP_CLI;
use WP_Query;
use FAToolkit\Utilities\Helpers;

/**
 * Export a postmeta value (formerly an ACF field) from all WooCommerce products.
 */
class ExportACFField {
	/**
	 * Class Export_ACF_Field
	 *
	 * This class represents a tool for exporting ACF fields.
	 */
	public function __construct() {
		\WP_CLI::add_command( 'fa:tools export-acf-field', array( $this, 'export_fields' ) );
	}


	/**
	 * Export a postmeta value from all WooCommerce products.
	 *
	 * The command keeps its historical name. ACF is no longer installed, so
	 * <field_key> is a postmeta key read with get_post_meta(). See issue #77.
	 *
	 * ## OPTIONS
	 *
	 * <field_key>
	 * : The postmeta key to export.
	 *
	 * // EXAMPLES
	 *
	 * wp fa:tools export-acf-field _global_unique_id
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 * @when after_wp_load
	 */
	public function export_fields( $args, $assoc_args ) {
		list( $acf_field_key ) = $args;
		// Prepare CSV data.
		$csv_data   = array();
		$csv_data[] = array( 'Product ID', 'ACF Field Value' );
		$products   = $this->fetch_all_products();
		$progress   = \WP_CLI\Utils\make_progress_bar( 'Processing products', count( $products ) );
		foreach ( $products as $product ) {
			$product_id = $product->ID;
			$acf_value  = get_post_meta( $product_id, $acf_field_key, true );
			if ( false === Helpers::is_empty( $acf_value ) ) {
				$csv_data[] = array( $product_id, $acf_value );
			}
			$progress->tick();
		}
		$progress->finish();
		// Output CSV data to file.
		$foo = $this->output_csv_to_file( $csv_data, $acf_field_key );
		\WP_CLI::success( "Exported ACF field values for {{$acf_field_key}}" );
	}

	/**
	 * Fetches all products from the WordPress database.
	 *
	 * @return array The array of product objects.
	 */
	private function fetch_all_products() {
		$args     = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'order'          => 'DESC',
			'orderby'        => 'ID',
		);
		$products = get_posts( $args );
		return $products;
	}

	/**
	 * Outputs the provided CSV data to a file and displays a success message.
	 *
	 * @param array $csv_data The data to be written to the CSV file.
	 * @return void
	 */
	private function output_csv_to_file( $csv_data, $acf_field_key ) {

		$upload_dir = wp_upload_dir();
		$filename   = 'acf_field_export_' . $acf_field_key . '_' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';
		$filepath   = $upload_dir['path'] . '/' . $filename;
		\WP_CLI::line( "Exported ACF field values to {$filepath}" );

		// Open the file for writing.
		$output = fopen( $filepath, 'w' );
		foreach ( $csv_data as $row ) {
			fputcsv( $output, $row );
		}
		fclose( $output );
	}
}
