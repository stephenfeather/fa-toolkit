<?php
/**
 * Class to add a custom column for vendor to the product edit list table.
 *
 * @package FA-Toolkit
 * @since 1.0.6
 */

namespace FAToolkit\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class to add a custom column for vendor to the product list table.
 */
class Product_Display_Vendor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_vendor_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'add_vendor_column_content' ), 20, 2 );
	}

	/**
	 * Adds the vendor column to the product list table.
	 *
	 * @param array $columns The existing columns.
	 * @return array $columns The updated columns.
	 */
	public function add_vendor_column( $columns ) {
		$columns['vendor'] = 'Vendor';
		return $columns;
	}

	/**
	 * Adds the vendor column content to the product list table.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public function add_vendor_column_content( $column, $post_id ) {
		if ( 'vendor' === $column ) {
			$vendor_url = $this->generate_vendor_url( $post_id );
			$vendor     = get_field( 'dealer', $post_id );
			echo "<a href='" . esc_url( $vendor_url ) . "' target='_blank' rel='noopener noreferrer'>" . esc_html( $vendor ) . '</a>';
		}
	}

	/**
	 * Generates the vendor URL.
	 *
	 * @param int $post_id The post ID.
	 * @return string $url The vendor URL.
	 */
	public function generate_vendor_url( $post_id ) {
		$url     = 'https://foobar.baz';
		$product = wc_get_product( $post_id );
		$vendor  = get_field( 'vendor', $post_id );
		$sku     = $product->get_sku();
		if ( 'CSSI' === $vendor ) {
			$url = 'https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=' . $sku;
			return $url;
		}

		if ( 'Davidsons' === $vendor ) {
			$url = 'https://foobar.com' . $sku;
			return $url;
		}
		return $url;
	}
}

new Product_Display_Vendor();
