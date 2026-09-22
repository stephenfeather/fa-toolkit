<?php
/**
 * The five shipping classes and the method each may ship by.
 *
 * @package    fa-toolkit
 * @since 1.2.7
 */

namespace FAToolkit\Shipping;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The class table from operations issue #650, Revision 2.
 *
 * Classes are matched by term NAME, exactly as ruled, and never created here:
 * the terms are made in wp-admin (WD-4 B1) and assigned by the content import.
 * A product whose term is not one of the five reads as having no class, which
 * the rates filter turns into "no rate", never into Standard.
 *
 * Methods are matched by the flat-rate title the operator typed in the zone
 * (WD-4 B4/B5), so no instance id is hard-coded and staging and production
 * need no separate configuration.
 */
final class ShippingClasses {

	public const HANDGUNS   = 'Handguns';
	public const LONG_GUNS  = 'Long Guns';
	public const AMMUNITION = 'Ammunition';
	public const HAZMAT     = 'Hazmat';
	public const STANDARD   = 'Standard';

	public const GROUND      = 'Ground';
	public const TWO_DAY_AIR = '2-Day Air';

	/**
	 * Class name => the one method it may ship by.
	 *
	 * Handguns go by air because neither carrier takes them by ground (WD-1);
	 * everything else goes Ground. The order is the ranking, and the order
	 * packages come out of the cart split.
	 *
	 * @var array<string, string>
	 */
	private const METHODS = array(
		self::HANDGUNS   => self::TWO_DAY_AIR,
		self::LONG_GUNS  => self::GROUND,
		self::AMMUNITION => self::GROUND,
		self::HAZMAT     => self::GROUND,
		self::STANDARD   => self::GROUND,
	);

	/**
	 * The five class names, ranked.
	 *
	 * @return array<int, string>
	 */
	public static function names() {
		return array_keys( self::METHODS );
	}

	/**
	 * The method a class may ship by, null for a name outside the table.
	 *
	 * @param string $class_name Class name.
	 * @return string|null
	 */
	public static function method_for( $class_name ) {
		return self::METHODS[ $class_name ] ?? null;
	}

	/**
	 * The class name a product carries, '' when it has none or the term is
	 * not one of the five.
	 *
	 * Read through `get_shipping_class_id()`, which for a variation falls back
	 * to the parent's class.
	 *
	 * @param object $product A WC_Product.
	 * @return string
	 */
	public static function name_for_product( $product ) {
		$class_id = (int) $product->get_shipping_class_id();

		if ( $class_id < 1 ) {
			return '';
		}

		$term = get_term_by( 'id', $class_id, 'product_shipping_class' );
		$name = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';

		return isset( self::METHODS[ $name ] ) ? $name : '';
	}
}
