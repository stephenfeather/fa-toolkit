<?php
/**
 * Tests for CartPackageSplitter.
 *
 * @package FAToolkit\Tests\Shipping
 */

namespace FAToolkit\Tests\Shipping;

use Brain\Monkey\Functions;
use FAToolkit\Shipping\CartPackageSplitter;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * CartPackageSplitter: one shipping package per shipping class, so a handgun
 * and a box of ammunition are rated as two shipments. Issue #650.
 */
class CartPackageSplitterTest extends TestCase {

	/**
	 * Term names by id, as get_term_by() answers them.
	 *
	 * @var array<int, string>
	 */
	private const TERMS = array(
		29 => 'Ammunition',
		30 => 'Handguns',
		31 => 'Long Guns',
		32 => 'Standard',
		33 => 'Hazmat',
	);

	/**
	 * Answer term lookups from the table.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_term_by' )->alias(
			fn( $field, $id ) => isset( self::TERMS[ $id ] ) ? (object) array( 'name' => self::TERMS[ $id ] ) : false
		);
	}

	/**
	 * A cart line whose product carries a class id.
	 *
	 * @param string $key        Cart item key.
	 * @param int    $class_id   Shipping class term id, 0 for none.
	 * @param float  $line_total Line total.
	 * @return array
	 */
	private function item( $key, $class_id, $line_total = 10.0 ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_shipping_class_id' )->andReturn( $class_id );

		return array(
			'key'        => $key,
			'data'       => $product,
			'quantity'   => 1,
			'line_total' => $line_total,
		);
	}

	/**
	 * A WooCommerce cart package.
	 *
	 * @param array $contents Cart items keyed by cart item key.
	 * @return array
	 */
	private function package( array $contents ) {
		return array(
			'contents'        => $contents,
			'contents_cost'   => array_sum( array_column( $contents, 'line_total' ) ),
			'applied_coupons' => array( 'SAVE5' ),
			'user'            => array( 'ID' => 7 ),
			'destination'     => array( 'country' => 'US', 'state' => 'GA' ),
			'cart_subtotal'   => 99.0,
		);
	}

	/**
	 * The constructor hooks the split onto the cart packages filter.
	 */
	public function test_constructor_registers_on_cart_shipping_packages() {
		$splitter = new CartPackageSplitter();

		$this->assertNotFalse( has_filter( 'woocommerce_cart_shipping_packages', array( $splitter, 'split' ) ) );
	}

	/**
	 * A cart whose items all share one class stays one package, and the
	 * package is tagged with the class so the rates filter need not re-derive it.
	 */
	public function test_split_keeps_a_single_class_cart_as_one_package() {
		$packages = array( $this->package( array( 'a' => $this->item( 'a', 31 ), 'b' => $this->item( 'b', 31 ) ) ) );

		$result = ( new CartPackageSplitter() )->split( $packages );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Long Guns', $result[0]['fa_shipping_class'] );
		$this->assertSame( array( 'a', 'b' ), array_keys( $result[0]['contents'] ) );
	}

	/**
	 * A mixed cart becomes one package per class, in the ranked order, each
	 * carrying its own contents and contents_cost and a copy of everything else.
	 */
	public function test_split_makes_one_package_per_class() {
		$packages = array(
			$this->package(
				array(
					'ammo'   => $this->item( 'ammo', 29, 30.0 ),
					'pistol' => $this->item( 'pistol', 30, 500.0 ),
					'powder' => $this->item( 'powder', 33, 45.0 ),
					'rifle'  => $this->item( 'rifle', 31, 700.0 ),
				)
			),
		);

		$result = ( new CartPackageSplitter() )->split( $packages );

		$this->assertSame(
			array( 'Handguns', 'Long Guns', 'Ammunition', 'Hazmat' ),
			array_column( $result, 'fa_shipping_class' )
		);
		$this->assertSame( array( 'pistol' ), array_keys( $result[0]['contents'] ) );
		$this->assertSame( 500.0, $result[0]['contents_cost'] );
		$this->assertSame( array( 'rifle' ), array_keys( $result[1]['contents'] ) );
		$this->assertSame( 700.0, $result[1]['contents_cost'] );
		$this->assertSame( array( 'SAVE5' ), $result[3]['applied_coupons'] );
		$this->assertSame( array( 'country' => 'US', 'state' => 'GA' ), $result[3]['destination'] );
		$this->assertSame( array( 'ID' => 7 ), $result[3]['user'] );
	}

	/**
	 * Items with no class, or a class outside the table, go in their own
	 * package last, tagged with an empty class, so the rates filter can hold
	 * them without touching the classified shipments beside them.
	 */
	public function test_split_isolates_unclassified_items_in_a_last_package() {
		$packages = array(
			$this->package(
				array(
					'mystery' => $this->item( 'mystery', 0 ),
					'pistol'  => $this->item( 'pistol', 30 ),
					'heavy'   => $this->item( 'heavy', 99 ),
				)
			),
		);

		$result = ( new CartPackageSplitter() )->split( $packages );

		$this->assertSame( array( 'Handguns', '' ), array_column( $result, 'fa_shipping_class' ) );
		$this->assertSame( array( 'mystery', 'heavy' ), array_keys( $result[1]['contents'] ) );
	}

	/**
	 * Packages other plugins already split are each split on their own; the
	 * output order follows the input packages.
	 */
	public function test_split_handles_each_incoming_package_separately() {
		$packages = array(
			$this->package( array( 'a' => $this->item( 'a', 32 ) ) ),
			$this->package( array( 'b' => $this->item( 'b', 30 ), 'c' => $this->item( 'c', 32 ) ) ),
		);

		$result = ( new CartPackageSplitter() )->split( $packages );

		$this->assertSame( array( 'Standard', 'Handguns', 'Standard' ), array_column( $result, 'fa_shipping_class' ) );
	}

	/**
	 * A package with no contents, or with no product objects, is passed through
	 * untouched rather than dropped.
	 */
	public function test_split_passes_through_a_package_without_products() {
		$packages = array( array( 'contents' => array(), 'destination' => array() ) );

		$this->assertSame( $packages, ( new CartPackageSplitter() )->split( $packages ) );
	}
}
