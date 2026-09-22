<?php
/**
 * Which rates a shipping package may see, and the holds that remove all of them.
 *
 * @package    fa-toolkit
 * @since 1.2.7
 */

namespace FAToolkit\Shipping;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The restriction lives on the method, not the zone (operations #650,
 * Findings §3): a zone is chosen by address, so it cannot tell a handgun
 * from a rifle. This filter can.
 *
 * For each package:
 *
 * - Handguns keep 2-Day Air only; every other class keeps Ground only.
 *   Methods are matched by their title in the zone (WD-4 B4/B5).
 * - Hazmat's cost is per started 50 lb: the configured cost times
 *   ceil( package weight / 50 ). A missing weight counts as one unit.
 * - Four holds remove EVERY rate and are logged, because the failure to
 *   prevent is a product shipping wrong or free (Findings §8, WD-4):
 *   `unclassified` (no class, or a term outside the five), `firearms_in_standard`
 *   (a Firearms-tree product that resolved to Standard), `no_method` (no rate
 *   carries the allowed method's title) and `no_cost` (the allowed method has
 *   no cost for this class, which WooCommerce would otherwise offer at $0).
 */
class PackageRateRules {

	/**
	 * Pounds per hazmat unit; a distributor's per-50-lb fee.
	 *
	 * @var float
	 */
	private const HAZMAT_UNIT_LB = 50.0;

	/**
	 * Category slugs whose tree is "firearms" for the Standard hold.
	 *
	 * @var array<int, string>
	 */
	private const FIREARMS_SLUGS = array( 'firearms' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_package_rates', array( $this, 'filter' ), 20, 2 );
	}

	/**
	 * Keep only the rates this package may ship by.
	 *
	 * @param array $rates   Rates keyed by rate id.
	 * @param array $package The cart package.
	 * @return array
	 */
	public function filter( $rates, $package ) {
		if ( true !== is_array( $rates ) || true !== is_array( $package ) ) {
			return $rates;
		}

		$class_name = $this->class_of( $package );

		if ( '' === $class_name ) {
			return $this->hold( 'unclassified', $package );
		}

		if ( ShippingClasses::STANDARD === $class_name && true === $this->holds_a_firearm( $package ) ) {
			return $this->hold( 'firearms_in_standard', $package );
		}

		$method = ShippingClasses::method_for( $class_name );
		$kept   = array_filter( $rates, fn( $rate ) => self::label_of( $rate ) === self::normalize( $method ) );

		if ( array() === $kept ) {
			return $this->hold( 'no_method', $package, array( 'method' => $method ) );
		}

		$units = ShippingClasses::HAZMAT === $class_name ? $this->hazmat_units( $package ) : 1;

		foreach ( $kept as $id => $rate ) {
			$cost = (float) $rate->get_cost() * $units;

			if ( $cost <= 0 ) {
				return $this->hold( 'no_cost', $package, array( 'method' => $method ) );
			}

			if ( 1 !== $units ) {
				self::scale( $rate, $cost, $units );
			}
		}

		return $kept;
	}

	/**
	 * The package's class: the splitter's tag, else the one class every
	 * item carries, else '' (mixed or absent).
	 *
	 * @param array $package The cart package.
	 * @return string
	 */
	private function class_of( array $package ) {
		if ( isset( $package[ CartPackageSplitter::PACKAGE_KEY ] ) ) {
			return (string) $package[ CartPackageSplitter::PACKAGE_KEY ];
		}

		$names = array();

		foreach ( self::products( $package ) as $product ) {
			$names[ ShippingClasses::name_for_product( $product ) ] = true;
		}

		return 1 === count( $names ) ? (string) array_key_first( $names ) : '';
	}

	/**
	 * Whether any product in the package is filed under a Firearms category.
	 *
	 * A variation's categories are its parent's. The tree is walked upward
	 * from each leaf term, so a product filed only in a subcategory counts.
	 *
	 * @param array $package The cart package.
	 * @return bool
	 */
	private function holds_a_firearm( array $package ) {
		$roots = $this->firearms_root_ids();

		if ( array() === $roots ) {
			return false;
		}

		foreach ( self::products( $package ) as $product ) {
			$post_id = (int) $product->get_parent_id();
			$post_id = $post_id > 0 ? $post_id : (int) $product->get_id();
			$terms   = get_the_terms( $post_id, 'product_cat' );

			if ( true !== is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$term_id = (int) ( $term->term_id ?? 0 );
				$lineage = array_merge( array( $term_id ), array_map( 'intval', (array) get_ancestors( $term_id, 'product_cat' ) ) );

				if ( array() !== array_intersect( $lineage, $roots ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Term ids of the configured Firearms root categories that exist.
	 *
	 * @return array<int, int>
	 */
	private function firearms_root_ids() {
		$slugs = (array) apply_filters( 'fa_toolkit_shipping_firearms_categories', self::FIREARMS_SLUGS );
		$ids   = array();

		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, 'product_cat' );

			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}

	/**
	 * How many started hazmat units the package weighs.
	 *
	 * The weighed lines give ceil( pounds / unit ). Every line with no weight
	 * adds one unit of its own on top, never zero: it cannot be weighed, so it
	 * is charged as a parcel and logged (WD-4; PR #125 review).
	 *
	 * @param array $package The cart package.
	 * @return int At least 1.
	 */
	private function hazmat_units( array $package ) {
		$unit    = (float) apply_filters( 'fa_toolkit_shipping_hazmat_unit_lb', self::HAZMAT_UNIT_LB );
		$pounds  = 0.0;
		$missing = array();

		foreach ( $package['contents'] as $item ) {
			$product = is_array( $item ) ? ( $item['data'] ?? null ) : null;

			if ( true !== is_object( $product ) ) {
				continue;
			}

			$weight = (float) $product->get_weight();

			if ( $weight <= 0 ) {
				$missing[] = (int) $product->get_id();
				continue;
			}

			$pounds += (float) wc_get_weight( $weight * (int) ( $item['quantity'] ?? 1 ), 'lbs' );
		}

		if ( array() !== $missing ) {
			$this->log( 'hazmat item missing weight, counted as one unit each', array( 'products' => $missing ) );
		}

		return max( 1, (int) ceil( $pounds / max( $unit, 0.001 ) ) + count( $missing ) );
	}

	/**
	 * Re-cost a rate, scaling its taxes with it.
	 *
	 * @param object $rate  A WC_Shipping_Rate.
	 * @param float  $cost  New cost.
	 * @param int    $units The multiplier applied.
	 * @return void
	 */
	private static function scale( $rate, $cost, $units ) {
		$rate->set_cost( $cost );
		$rate->set_taxes( array_map( fn( $tax ) => (float) $tax * $units, (array) $rate->get_taxes() ) );
	}

	/**
	 * Remove every rate, and say why where the operator will see it.
	 *
	 * @param string $reason  One of unclassified, firearms_in_standard, no_method, no_cost.
	 * @param array  $package The cart package.
	 * @param array  $extra   More context for the log line.
	 * @return array Always empty.
	 */
	private function hold( $reason, array $package, array $extra = array() ) {
		$this->log(
			'held package',
			$extra + array(
				'reason'   => $reason,
				'class'    => (string) ( $package[ CartPackageSplitter::PACKAGE_KEY ] ?? '' ),
				'products' => array_map( fn( $product ) => (int) $product->get_id(), self::products( $package ) ),
			)
		);

		/**
		 * Fires when the rates filter removes every rate from a package.
		 *
		 * @param string $reason  Why: unclassified, firearms_in_standard, no_method or no_cost.
		 * @param array  $package The cart package.
		 */
		do_action( 'fa_toolkit_shipping_package_held', $reason, $package );

		return array();
	}

	/**
	 * One line to the error log.
	 *
	 * @param string $what    What happened.
	 * @param array  $context Context.
	 * @return void
	 */
	private function log( $what, array $context ) {
		error_log( 'fa-toolkit shipping: ' . $what . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * The product objects in a package.
	 *
	 * @param array $package The cart package.
	 * @return array<int, object>
	 */
	private static function products( array $package ) {
		$products = array();

		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			if ( is_array( $item ) && is_object( $item['data'] ?? null ) ) {
				$products[] = $item['data'];
			}
		}

		return $products;
	}

	/**
	 * A rate's method title, normalized for comparison.
	 *
	 * @param object $rate A WC_Shipping_Rate.
	 * @return string
	 */
	private static function label_of( $rate ) {
		return self::normalize( is_object( $rate ) ? $rate->get_label() : '' );
	}

	/**
	 * Lower-cased and trimmed, since titles are typed by hand.
	 *
	 * @param mixed $label A method title.
	 * @return string
	 */
	private static function normalize( $label ) {
		return strtolower( trim( (string) $label ) );
	}
}
