<?php
/**
 * Postmeta keys written by the product import, and readers for them.
 *
 * @package FA-Toolkit
 * @since 1.2.1
 */

namespace FAToolkit\Product;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The single place the plugin names import-written product meta keys.
 *
 * Issue #77: these values used to be ACF fields read through get_field().
 * ACF is not installed, so every such call fatals when its hook fires. The
 * import (inventory-feeds-management → wp-import) now writes plain postmeta
 * under the keys below. Change a key here and nowhere else.
 */
class Product_Meta {

	/**
	 * Vendor the product was priced from, as a lowercase slug. Closed
	 * vocabulary: cssi, davidsons, rsrgroup, zanders, pawholesale. May be
	 * absent on out-of-stock products, so readers treat '' as "no vendor",
	 * not as an error.
	 *
	 * @var string
	 */
	const VENDOR = '_fa_vendor';

	/**
	 * WooCommerce's own GTIN field (UPC/EAN/ISBN alike), populated by the import.
	 *
	 * @var string
	 */
	const GTIN = '_global_unique_id';

	/**
	 * Read the vendor name for a product.
	 *
	 * @param int $post_id Product post ID.
	 * @return string Vendor slug as stored, or '' when unset.
	 */
	public static function vendor( $post_id ): string {
		$value = get_post_meta( $post_id, self::VENDOR, true );
		return is_string( $value ) ? $value : '';
	}
}
