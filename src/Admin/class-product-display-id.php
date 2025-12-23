<?php
/**
 * Class to add a custom column for ID to the product edit list table.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

namespace FAToolkit\Admin;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
}

/**
 * Class to add a custom column for id to the product list table.
 */
class Product_Display_Id {


	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_id_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'add_id_column_content' ), 20, 2 );
	}

	/**
	 * Adds the id column to the product list table.
	 *
	 * @param array $columns The existing columns.
	 * @return array $columns The updated columns.
	 */
	public function add_id_column( $columns ) {
		global $wpdb;
		$columns['ID'] = 'ID';
		ray( $columns );
		ray( $wpdb->queries );
		return $columns;
	}

	/**
	 * Adds the id column content to the product list table.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public function add_id_column_content( $column, $post_id ) {
		if ( 'ID' === $column ) {
			printf( '%s', esc_html( $post_id ) );
		}
	}
}

new Product_Display_Id();
