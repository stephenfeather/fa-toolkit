<?php
/**
 * Splits the cart into one shipping package per shipping class.
 *
 * @package    fa-toolkit
 * @since 1.2.7
 */

namespace FAToolkit\Shipping;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * One shipment per class (operations #650, "Where restriction lives").
 *
 * A handgun and a box of ammunition cannot travel together: the handgun goes
 * 2-Day Air, the ammunition Ground, and every distributor ships them as two
 * parcels. Splitting the cart by class lets WooCommerce rate each parcel on
 * its own, so the customer is charged for both.
 *
 * Each package is tagged `fa_shipping_class` with the class name ('' for
 * items with no usable class), and PackageRateRules reads the tag. Items
 * with no class are isolated in a last package so the hold on them does not
 * take the classified shipments down with it.
 */
class CartPackageSplitter {

	/**
	 * The tag PackageRateRules reads.
	 *
	 * @var string
	 */
	public const PACKAGE_KEY = 'fa_shipping_class';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'split' ), 20, 1 );
	}

	/**
	 * Split every package by the class of its contents.
	 *
	 * @param array $packages WooCommerce cart packages.
	 * @return array
	 */
	public function split( $packages ) {
		if ( true !== is_array( $packages ) ) {
			return $packages;
		}

		$split = array();

		foreach ( $packages as $package ) {
			foreach ( $this->split_one( $package ) as $part ) {
				$split[] = $part;
			}
		}

		return $split;
	}

	/**
	 * One package as one package per class, ranked, unclassified last.
	 *
	 * @param mixed $package One cart package.
	 * @return array<int, mixed>
	 */
	private function split_one( $package ) {
		if ( true !== is_array( $package ) || true !== is_array( $package['contents'] ?? null ) || array() === $package['contents'] ) {
			return array( $package );
		}

		$groups = self::group_by_class( $package['contents'] );

		if ( 1 === count( $groups ) ) {
			$package[ self::PACKAGE_KEY ] = (string) array_key_first( $groups );
			return array( $package );
		}

		$parts = array();

		foreach ( $groups as $class_name => $contents ) {
			$part                      = $package;
			$part['contents']          = $contents;
			$part['contents_cost']     = self::contents_cost( $contents );
			$part[ self::PACKAGE_KEY ] = (string) $class_name;
			$parts[]                   = $part;
		}

		return $parts;
	}

	/**
	 * Cart lines grouped by class name, in the ranked order, '' last.
	 *
	 * @param array $contents Cart lines keyed by cart item key.
	 * @return array<string, array>
	 */
	private static function group_by_class( array $contents ) {
		$groups     = array_fill_keys( ShippingClasses::names(), array() );
		$groups[''] = array();

		foreach ( $contents as $key => $item ) {
			$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;
			$name    = is_object( $product ) ? ShippingClasses::name_for_product( $product ) : '';

			$groups[ $name ][ $key ] = $item;
		}

		return array_filter( $groups );
	}

	/**
	 * The sum of the lines' totals, as WooCommerce computes contents_cost.
	 *
	 * @param array $contents Cart lines.
	 * @return float
	 */
	private static function contents_cost( array $contents ) {
		return (float) array_sum( array_map( fn( $item ) => (float) ( $item['line_total'] ?? 0 ), $contents ) );
	}
}
