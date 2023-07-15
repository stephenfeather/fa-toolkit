<?php
/**
 * Plugin Name: Delete Schema WP-CLI Command
 * Description: Deletes 'rank_math_rich_snippet' meta key and runs delete_schema function for WooCommerce products with 'rank_math_schema_Off' value.
 * Version: 1.0.0
 * Author: Stephen Feather
 * Copywrite: 2023 Stephen Feather
 * License: GPL2
 *
 * @package FA-Toolkit
 * @since 1.0.4
 *
 * @comment As a Rank Math Pro customer we were pretty pissed to find over 60k products with schema disabled.  This plugin is a one-off to fix that.
 */

namespace FAToolkit\Utilities;

use \WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Fix Schema Command.
 */
class FixRankMathSchemas {

	/**
	 * Deletes 'rank_math_rich_snippet' meta key and runs delete_schema function for WooCommerce products with 'rank_math_schema_Off' value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:utilities fix-rank-math-schemas
	 *
	 * @when after_wp_load
	 */
	public function __construct() {
		WP_CLI::add_command( 'fa:utilities fix-rank-math-schemas', array( $this, 'fix_schemas' ) );
	}

	/**
	 * Deletes 'rank_math_rich_snippet' meta key and runs delete_schema function for WooCommerce products with 'rank_math_schema_Off' value.
	 */
	public function fix_schemas() {
		global $wpdb;

		// Get product IDs with 'rank_math_schema_Off' meta value.
		$product_ids = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC",
				'rank_math_schema_Off'
			)
		);

		$current_count = 1;
		$deleted_count = 0;
		$failed_count  = 0;
		// Delete 'rank_math_rich_snippet' meta key and run delete_schema function for each product.
		foreach ( $product_ids as $product_id ) {
			WP_CLI::log( sprintf( 'Deleting rank_math_rich_snippet and rank_math_schema_Off for product %d of %d.', $current_count, count( $product_ids ) ) );
			$current_count++;
			// Delete 'rank_math_rich_snippet' meta key.
			delete_post_meta( $product_id, 'rank_math_rich_snippet' );
			$delete_schema_status = $this->delete_schema( $product_id );
			// Run delete_schema function.
			if ( $delete_schema_status ) {
				$deleted_count++;
			} else {
				$failed_count++;
			}
		}

		$total_count     = count( $product_ids );
		$summary_message = sprintf(
			'rank_math_schema_Off removed for %d out of %d WooCommerce products. %d deletions failed.',
			$deleted_count,
			$total_count,
			$failed_count
		);

		WP_CLI::success( $summary_message );
	}

	/**
	 * Runs the delete_schema function for a given product ID.
	 *
	 * @param int $product_id The ID of the product to run delete_schema for.
	 * @return bool True if successful, false if failed.
	 */
	public function delete_schema( $product_id ) {
		// Perform actions in the delete_schema function.
		global $wpdb;
		$where  = $wpdb->prepare( 'WHERE post_id = %d AND meta_key LIKE %s', $product_id, $wpdb->esc_like( 'rank_math_schema_' ) . '%' );
		$result = $wpdb->query( "DELETE FROM {$wpdb->postmeta} {$where}" ); // phpcs:ignore
		return false !== $result;
	}
}

new FixRankMathSchemas();
