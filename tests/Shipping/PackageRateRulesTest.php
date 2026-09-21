<?php
/**
 * Tests for PackageRateRules.
 *
 * @package FAToolkit\Tests\Shipping
 */

namespace FAToolkit\Tests\Shipping;

use Brain\Monkey\Functions;
use FAToolkit\Shipping\PackageRateRules;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * PackageRateRules: which methods a package may ship by, and the holds that
 * remove every rate rather than let a product ship wrong or free. Issue #650.
 */
class PackageRateRulesTest extends TestCase {

	/**
	 * Holds the filter reported, as [reason, package].
	 *
	 * @var array<int, array{0:string,1:array}>
	 */
	private $held = array();

	/**
	 * Pounds per store weight unit, as wc_get_weight() converts.
	 *
	 * @var float
	 */
	private $pounds_per_unit = 1.0;

	/**
	 * Lines written to the error log.
	 *
	 * @var array<int, string>
	 */
	private $logged = array();

	/**
	 * Stub WordPress: no firearms category unless a test says so, weights in
	 * pounds already, and holds recorded.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->held            = array();
		$this->logged          = array();
		$this->pounds_per_unit = 1.0;
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( array() );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'wc_get_weight' )->alias( fn( $weight ) => (float) $weight * $this->pounds_per_unit );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'error_log' )->alias(
			function ( $line ) {
				$this->logged[] = $line;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) {
				if ( 'fa_toolkit_shipping_package_held' === $hook ) {
					$this->held[] = $args;
				}
			}
		);
	}

	/**
	 * A shipping rate mock.
	 *
	 * @param string $label Method title as configured in the zone.
	 * @param float  $cost  Cost.
	 * @return \Mockery\MockInterface
	 */
	private function rate( $label, $cost ) {
		$rate = Mockery::mock( 'WC_Shipping_Rate' );
		$rate->cost = $cost;
		$rate->shouldReceive( 'get_label' )->andReturn( $label );
		$rate->shouldReceive( 'get_cost' )->andReturnUsing( fn() => (string) $rate->cost );
		$rate->shouldReceive( 'set_cost' )->andReturnUsing(
			function ( $cost ) use ( $rate ) {
				$rate->cost = $cost;
			}
		);
		$rate->shouldReceive( 'get_taxes' )->andReturn( array() );
		$rate->shouldReceive( 'set_taxes' )->andReturn( null );

		return $rate;
	}

	/**
	 * The two configured methods, as the zone offers them for a package.
	 *
	 * @param float $ground Ground cost for this package's class.
	 * @param float $air    2-Day Air cost for this package's class.
	 * @return array<string, \Mockery\MockInterface>
	 */
	private function rates( $ground, $air ) {
		return array(
			'flat_rate:7' => $this->rate( 'Ground', $ground ),
			'flat_rate:8' => $this->rate( '2-Day Air', $air ),
		);
	}

	/**
	 * A cart line.
	 *
	 * @param int    $product_id Product id.
	 * @param string $weight     Product weight, '' for none.
	 * @param int    $quantity   Quantity.
	 * @param int    $parent_id  Parent id for a variation.
	 * @return array
	 */
	private function item( $product_id, $weight = '1', $quantity = 1, $parent_id = 0 ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $product_id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( $parent_id );
		$product->shouldReceive( 'get_weight' )->andReturn( $weight );

		return array(
			'data'     => $product,
			'quantity' => $quantity,
		);
	}

	/**
	 * A package as the splitter tags it.
	 *
	 * @param string $class_name Class name, '' for unclassified.
	 * @param array  $contents   Cart lines.
	 * @return array
	 */
	private function package( $class_name, ?array $contents = null ) {
		return array(
			'fa_shipping_class' => $class_name,
			'contents'          => $contents ?? array( $this->item( 1 ) ),
			'destination'       => array( 'country' => 'US' ),
		);
	}

	/**
	 * Put products 1..n in the Firearms category tree: a root term with slug
	 * `firearms` (id 100) and a child (id 101) the products are filed under.
	 *
	 * @return void
	 */
	private function products_are_firearms() {
		Functions\when( 'get_term_by' )->alias(
			fn( $field, $value, $taxonomy ) => 'firearms' === $value ? (object) array( 'term_id' => 100, 'slug' => 'firearms' ) : false
		);
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'term_id' => 101, 'slug' => 'rifles' ) ) );
		Functions\when( 'get_ancestors' )->justReturn( array( 100 ) );
	}

	/**
	 * The constructor hooks the rules onto the package rates filter.
	 */
	public function test_constructor_registers_on_package_rates() {
		$rules = new PackageRateRules();

		$this->assertNotFalse( has_filter( 'woocommerce_package_rates', array( $rules, 'filter' ) ) );
	}

	/**
	 * A Handguns package sees 2-Day Air only: neither carrier takes a handgun
	 * by ground (WD-1).
	 */
	public function test_handguns_see_only_two_day_air() {
		$result = ( new PackageRateRules() )->filter( $this->rates( 0, 25 ), $this->package( 'Handguns' ) );

		$this->assertSame( array( 'flat_rate:8' ), array_keys( $result ) );
		$this->assertSame( array(), $this->held );
	}

	/**
	 * Every other class sees Ground only.
	 *
	 * @return array<string, array{0:string,1:float}>
	 */
	public static function ground_classes() {
		return array(
			'long guns'  => array( 'Long Guns', 15.0 ),
			'ammunition' => array( 'Ammunition', 20.0 ),
			'standard'   => array( 'Standard', 15.0 ),
		);
	}

	/**
	 * Long Guns, Ammunition and Standard see Ground only, at the configured cost.
	 *
	 * @param string $class_name Class name.
	 * @param float  $cost       Configured Ground cost.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ground_classes' )]
	public function test_ground_classes_see_only_ground( $class_name, $cost ) {
		$result = ( new PackageRateRules() )->filter( $this->rates( $cost, 0 ), $this->package( $class_name ) );

		$this->assertSame( array( 'flat_rate:7' ), array_keys( $result ) );
		$this->assertSame( $cost, $result['flat_rate:7']->cost );
		$this->assertSame( array(), $this->held );
	}

	/**
	 * Method labels are matched case-insensitively and trimmed, since they are
	 * typed by hand in the zone settings.
	 */
	public function test_method_labels_match_loosely() {
		$rates = array( 'flat_rate:7' => $this->rate( ' ground ', 15 ) );

		$result = ( new PackageRateRules() )->filter( $rates, $this->package( 'Standard' ) );

		$this->assertSame( array( 'flat_rate:7' ), array_keys( $result ) );
	}

	/**
	 * A package with no class gets no rate at all and is reported, so an
	 * unclassified product never falls through to Standard (Findings §8).
	 */
	public function test_an_unclassified_package_gets_no_rate() {
		$result = ( new PackageRateRules() )->filter( $this->rates( 15, 25 ), $this->package( '' ) );

		$this->assertSame( array(), $result );
		$this->assertSame( 'unclassified', $this->held[0][0] );
		$this->assertCount( 1, $this->logged );
		$this->assertStringStartsWith( 'fa-toolkit shipping: held package', $this->logged[0] );
	}

	/**
	 * A package the splitter did not tag is classified from its contents, and
	 * mixed contents count as unclassified.
	 */
	public function test_an_untagged_package_is_classified_from_its_contents() {
		Functions\when( 'get_term_by' )->alias(
			fn( $field, $id ) => 30 === $id ? (object) array( 'name' => 'Handguns' ) : false
		);
		$item = $this->item( 1 );
		$item['data']->shouldReceive( 'get_shipping_class_id' )->andReturn( 30 );
		$package = array( 'contents' => array( $item ), 'destination' => array() );

		$result = ( new PackageRateRules() )->filter( $this->rates( 0, 25 ), $package );

		$this->assertSame( array( 'flat_rate:8' ), array_keys( $result ) );
	}

	/**
	 * A Standard package holding anything filed under the Firearms category
	 * tree is held and logged: a firearm that resolved to Standard is a
	 * misclassification, not a $15 ground shipment (Findings §8).
	 */
	public function test_a_firearm_in_standard_is_held_and_logged() {
		$this->products_are_firearms();

		$result = ( new PackageRateRules() )->filter( $this->rates( 15, 0 ), $this->package( 'Standard' ) );

		$this->assertSame( array(), $result );
		$this->assertSame( 'firearms_in_standard', $this->held[0][0] );
		$this->assertStringContainsString( 'firearms_in_standard', $this->logged[0] );
	}

	/**
	 * The Firearms-tree check walks up from the product's leaf category, and
	 * reads a variation's categories from its parent.
	 */
	public function test_the_firearms_check_reads_a_variations_parent() {
		$this->products_are_firearms();
		$seen = array();
		Functions\when( 'get_the_terms' )->alias(
			function ( $id ) use ( &$seen ) {
				$seen[] = $id;
				return array( (object) array( 'term_id' => 101, 'slug' => 'rifles' ) );
			}
		);
		$package = $this->package( 'Standard', array( $this->item( 55, '1', 1, 12 ) ) );

		( new PackageRateRules() )->filter( $this->rates( 15, 0 ), $package );

		$this->assertSame( array( 12 ), $seen );
	}

	/**
	 * A firearm in Long Guns is not held: the tree check applies to Standard only.
	 */
	public function test_a_firearm_in_long_guns_is_not_held() {
		$this->products_are_firearms();

		$result = ( new PackageRateRules() )->filter( $this->rates( 15, 0 ), $this->package( 'Long Guns' ) );

		$this->assertSame( array( 'flat_rate:7' ), array_keys( $result ) );
		$this->assertSame( array(), $this->held );
	}

	/**
	 * A class whose allowed method has no cost configured is held rather than
	 * offered free: WooCommerce's flat rate charges $0 for a blank class cost
	 * (WD-4), and silent free shipping is the failure to prevent.
	 */
	public function test_a_zero_cost_rate_is_held_not_offered_free() {
		$result = ( new PackageRateRules() )->filter( $this->rates( 0, 0 ), $this->package( 'Ammunition' ) );

		$this->assertSame( array(), $result );
		$this->assertSame( 'no_cost', $this->held[0][0] );
	}

	/**
	 * With no rate carrying the allowed method's label, nothing is offered and
	 * the hold says so, so a renamed method shows up in the log rather than as
	 * a handgun shipping Ground.
	 */
	public function test_a_missing_method_is_held() {
		$rates = array( 'flat_rate:7' => $this->rate( 'Ground', 15 ) );

		$result = ( new PackageRateRules() )->filter( $rates, $this->package( 'Handguns' ) );

		$this->assertSame( array(), $result );
		$this->assertSame( 'no_method', $this->held[0][0] );
	}

	/**
	 * Hazmat is charged per started 50 lb: the configured cost is the unit
	 * price and is multiplied by ceil( package weight / 50 ).
	 */
	public function test_hazmat_is_charged_per_started_fifty_pounds() {
		$package = $this->package( 'Hazmat', array( $this->item( 1, '8', 5 ), $this->item( 2, '12.5', 2 ) ) ); // 40 + 25 = 65 lb.

		$result = ( new PackageRateRules() )->filter( $this->rates( 40.0, 0 ), $package );

		$this->assertSame( 80.0, $result['flat_rate:7']->cost );
	}

	/**
	 * Up to 50 lb is one unit.
	 */
	public function test_hazmat_under_fifty_pounds_is_one_unit() {
		$package = $this->package( 'Hazmat', array( $this->item( 1, '50', 1 ) ) );

		$result = ( new PackageRateRules() )->filter( $this->rates( 40.0, 0 ), $package );

		$this->assertSame( 40.0, $result['flat_rate:7']->cost );
	}

	/**
	 * Weights are converted to pounds through wc_get_weight(), whatever the
	 * store's weight unit.
	 */
	public function test_hazmat_converts_weights_to_pounds() {
		$this->pounds_per_unit = 2.2046; // The store weighs in kg.
		$package               = $this->package( 'Hazmat', array( $this->item( 1, '30', 1 ) ) );

		$result = ( new PackageRateRules() )->filter( $this->rates( 40.0, 0 ), $package );

		$this->assertSame( 80.0, $result['flat_rate:7']->cost );
	}

	/**
	 * A hazmat item with no weight counts as one unit and is logged, never
	 * as zero (WD-4).
	 */
	public function test_hazmat_with_a_missing_weight_is_one_unit_and_logged() {
		$package = $this->package( 'Hazmat', array( $this->item( 1, '', 3 ) ) );

		$result = ( new PackageRateRules() )->filter( $this->rates( 40.0, 0 ), $package );

		$this->assertSame( 40.0, $result['flat_rate:7']->cost );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'missing weight', $this->logged[0] );
		$this->assertSame( array(), $this->held );
	}

	/**
	 * The hazmat unit is filterable, so a distributor's terms need no code change.
	 */
	public function test_the_hazmat_unit_is_filterable() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'fa_toolkit_shipping_hazmat_unit_lb' === $hook ) {
					return 25.0;
				}
				return $value;
			}
		);
		$package = $this->package( 'Hazmat', array( $this->item( 1, '30', 1 ) ) );

		$result = ( new PackageRateRules() )->filter( $this->rates( 40.0, 0 ), $package );

		$this->assertSame( 80.0, $result['flat_rate:7']->cost );
	}
}
