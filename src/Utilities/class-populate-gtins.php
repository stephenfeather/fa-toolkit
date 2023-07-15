<?php
/**
 * Plugin Name: Populate GTIN Command
 * Description: A custom WP-CLI command to populate _rank_math_gtin_code field with upc_code value in a specific category.
 * Version: 1.0
 * Author: Stephen Feather
 * Author URI: https://stephenfeather.com
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

namespace FAToolkit\Utilities;

use \WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Populates _rank_math_gtin_code field with upc_code value in a specific category.
 */
class PopulateGTINS {

	/**
	 * Register the populateGTINS command.
	 */
	public function __construct() {
		WP_CLI::add_command( 'fa:utilities populateGTINS', array( $this, 'populateGTIN' ) );
	}

	/**
	 * Populates _rank_math_gtin_code field with upc_code value in a specific category.
	 *
	 * ## OPTIONS
	 *
	 * <category_id>
	 * : The ID of the category.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:utilities populateGTINS 123
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The associative arguments.
	 */
	public function populateGTIN( $args, $assoc_args ) {
		$category_id = isset( $args[0] ) ? intval( $args[0] ) : 0;

		if ( ! $category_id ) {
			WP_CLI::error( 'Invalid category ID.' );
		}

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_id,
					),
				),
			)
		);

		if ( empty( $product_ids ) ) {
			WP_CLI::error( 'No products found in the specified category.' );
		}

		foreach ( $product_ids as $product_id ) {
			$upc_code = get_post_meta( $product_id, 'upc_code', true );

			if ( ! empty( $upc_code ) ) {
				update_post_meta( $product_id, '_rank_math_gtin_code', $upc_code );
			}
		}

		WP_CLI::success( 'GTIN codes populated successfully.' );
	}
}

new PopulateGTINS();
