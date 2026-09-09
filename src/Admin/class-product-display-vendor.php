<?php
/**
 * Class to add a custom column for vendor to the product edit list table.
 *
 * @package FA-Toolkit
 * @since 1.0.6
 */

namespace FAToolkit\Admin;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

use FAToolkit\Product\Product_Meta;

/**
 * Class to add a custom column for vendor to the product list table.
 */
class Product_Display_Vendor {

	/**
	 * Vendor slugs the import writes to _fa_vendor, with their display label and
	 * catalogue lookup URL prefix (the SKU is appended).
	 *
	 * A slug with an empty 'url' renders as its label without a link. A slug
	 * not listed here renders as the raw slug without a link; add it here.
	 *
	 * @since 1.2.1
	 *
	 * @var array<string, array{label: string, url: string}>
	 */
	private const VENDORS = array(
		'cssi'      => array(
			'label' => 'CSSI',
			'url'   => 'https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=',
		),
		'davidsons' => array(
			'label' => 'Davidsons',
			'url'   => 'https://www.davidsonsinc.com/catalogsearch/result/?q=',
		),
	);

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
	 * Renders nothing when the product has no vendor meta (out-of-stock products
	 * may not carry it), and the bare name when the product cannot be loaded.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public function add_vendor_column_content( $column, $post_id ) {
		if ( 'vendor' !== $column ) {
			return;
		}

		$slug = Product_Meta::vendor( $post_id );
		if ( '' === $slug ) {
			return;
		}

		$label      = isset( self::VENDORS[ $slug ] ) ? self::VENDORS[ $slug ]['label'] : $slug;
		$vendor_url = $this->build_vendor_url( $slug, $this->get_sku( $post_id ) );
		if ( '' === $vendor_url ) {
			echo esc_html( $label );
			return;
		}

		echo '<a href="' . esc_url( $vendor_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Generates the vendor URL.
	 *
	 * @param int $post_id The post ID.
	 * @return string $url The vendor URL, or '' when the vendor is unknown or the product is missing.
	 */
	public function generate_vendor_url( $post_id ) {
		$sku = $this->get_sku( $post_id );
		if ( '' === $sku ) {
			return '';
		}

		return $this->build_vendor_url( Product_Meta::vendor( $post_id ), $sku );
	}

	/**
	 * Reads the product SKU, tolerating a missing product.
	 *
	 * WooCommerce returns false from wc_get_product() for a deleted or non-product id.
	 *
	 * @since 1.2.1
	 *
	 * @param int $post_id The post ID.
	 * @return string SKU, or '' when the product cannot be loaded.
	 */
	private function get_sku( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( false === $product || null === $product ) {
			return '';
		}

		return (string) $product->get_sku();
	}

	/**
	 * Builds the vendor catalogue URL for a vendor slug.
	 *
	 * Slugs are matched exactly against the VENDORS table.
	 *
	 * @since 1.2.1
	 *
	 * @param string $slug Vendor slug as stored in postmeta.
	 * @param string $sku  Product SKU.
	 * @return string URL, or '' for an unlisted slug, a slug without a URL, or an empty SKU.
	 */
	private function build_vendor_url( $slug, $sku ) {
		if ( '' === $sku || ! isset( self::VENDORS[ $slug ] ) || '' === self::VENDORS[ $slug ]['url'] ) {
			return '';
		}

		return self::VENDORS[ $slug ]['url'] . $sku;
	}
}
