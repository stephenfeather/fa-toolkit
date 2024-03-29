<?php
/**
 * Find WooCommerce products that don't have a thumbnail and move them to drafts.
 *
 * @package FA-Toolkit
 * @since 1.0
 * 
 * TODO: Refactor this into a class.
 */

namespace FAToolkit\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

use \WP_CLI;
use \WP_Query;

/**
 * Class to find WooCommerce products that don't have a thumbnail and move them to drafts.
 */
class ProductThumbnailChecker {

	/**
	 * Constructor.
	 */
	public function __construct() {
		WP_CLI::add_command( 'fa:media product-thumbnail-check', array( $this, 'wp_cli_product_thumbnail_check' ) );
	}

	/**
	 * Find WooCommerce products that don't have a thumbnail and move them to drafts.
	 *
	 * ## OPTIONS
	 *
	 *  [--vendor=<vendor>]
	 * : The vendor name to filter the results by.
	 *
	 * [--result_count=<result_count>]
	 * : The number of records to process (default is 100)
	 *
	 * [--order=<ASC,DESC>]
	 * : Change the order of records
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:media product-thumbnail-check --vendor=ACME
	 *     wp fa:media product-thumbnail-check --result_count=5
	 *
	 * @when   after_wp_load
	 * @param  array $args Arguments passed to the WP-CLI command.
	 * @param  array $assoc_args Associated arguments passed to the WP-CLI command.
	 * @return void
	 */
	public function wp_cli_product_thumbnail_check( $args, $assoc_args ) {
		$order        = isset( $assoc_args['order'] ) ? $assoc_args['order'] : 'ASC';
		$vendor       = isset( $assoc_args['vendor'] ) ? $assoc_args['vendor'] : '';
		$result_count = isset( $assoc_args['result_count'] ) ? absint( $assoc_args['result_count'] ) : 100;

		$processed_count = 0;
		$error_count     = 0;

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_thumbnail_id',
					'value'   => '',
					'compare' => '=',
				),
			),
			'posts_per_page' => $result_count,
			'orderby'        => 'ID',
			'order'          => $order,
			'fields'         => 'ids',
			'cache_results'  => false,
		);
		if ( isset( $assoc_args['vendor'] ) ) {
			$args['meta_key']   = 'dealer'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $vendor; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				WP_CLI::debug( 'Product ID ' . $post_id . ' does not have a thumbnail.' );
				$status = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);

				if ( 0 === $status ) {
					WP_CLI::warning( "Product {$post_id} NOT moved to drafts." );
					$error_count++;
				} else {
					WP_CLI::success( "Product {$post_id} moved to drafts." );
					$processed_count++;
				}
			}
		}
	}
}

new ProductThumbnailChecker();
