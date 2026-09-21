<?php
/**
 * Tests for SmallOrderFee.
 *
 * @package FAToolkit\Tests\Shipping
 */

namespace FAToolkit\Tests\Shipping;

use Brain\Monkey\Functions;
use FAToolkit\Shipping\SmallOrderFee;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * SmallOrderFee: +$7.50 once per order when the cart subtotal is under $100,
 * covering the distributors' small-order handling fee. Issue #650.
 */
class SmallOrderFeeTest extends TestCase {

	/**
	 * Stub the admin check and translation.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( '__' )->returnArg();
	}

	/**
	 * A cart mock.
	 *
	 * @param float $subtotal       Cart subtotal.
	 * @param bool  $needs_shipping Whether anything in it ships.
	 * @return \Mockery\MockInterface
	 */
	private function cart( $subtotal, $needs_shipping = true ) {
		$cart = Mockery::mock( 'WC_Cart' );
		$cart->shouldReceive( 'get_subtotal' )->andReturn( $subtotal );
		$cart->shouldReceive( 'needs_shipping' )->andReturn( $needs_shipping );

		return $cart;
	}

	/**
	 * The constructor hooks the fee onto the cart fees action.
	 */
	public function test_constructor_registers_on_cart_calculate_fees() {
		$fee = new SmallOrderFee();

		$this->assertNotFalse( has_action( 'woocommerce_cart_calculate_fees', array( $fee, 'add' ) ) );
	}

	/**
	 * A cart under $100 gets one $7.50 fee, untaxed.
	 */
	public function test_a_cart_under_the_threshold_gets_the_fee() {
		$cart = $this->cart( 99.99 );
		$cart->shouldReceive( 'add_fee' )->once()->with( 'Small order fee', 7.5, false );

		( new SmallOrderFee() )->add( $cart );
	}

	/**
	 * At $100 and above there is no fee.
	 */
	public function test_a_cart_at_the_threshold_gets_no_fee() {
		$cart = $this->cart( 100.0 );
		$cart->shouldReceive( 'add_fee' )->never();

		( new SmallOrderFee() )->add( $cart );
	}

	/**
	 * An empty cart gets no fee.
	 */
	public function test_an_empty_cart_gets_no_fee() {
		$cart = $this->cart( 0.0 );
		$cart->shouldReceive( 'add_fee' )->never();

		( new SmallOrderFee() )->add( $cart );
	}

	/**
	 * A cart that ships nothing pays no handling fee.
	 */
	public function test_a_cart_that_needs_no_shipping_gets_no_fee() {
		$cart = $this->cart( 20.0, false );
		$cart->shouldReceive( 'add_fee' )->never();

		( new SmallOrderFee() )->add( $cart );
	}

	/**
	 * Fee calculation in wp-admin outside AJAX is skipped, as WooCommerce asks.
	 */
	public function test_admin_page_loads_are_skipped() {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$cart = $this->cart( 20.0 );
		$cart->shouldReceive( 'add_fee' )->never();

		( new SmallOrderFee() )->add( $cart );
	}

	/**
	 * Threshold and amount are filterable.
	 */
	public function test_threshold_and_amount_are_filterable() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'fa_toolkit_shipping_small_order_threshold' === $hook ) {
					return 50.0;
				}
				if ( 'fa_toolkit_shipping_small_order_fee' === $hook ) {
					return 5.0;
				}
				return $value;
			}
		);
		$cart = $this->cart( 49.0 );
		$cart->shouldReceive( 'add_fee' )->once()->with( 'Small order fee', 5.0, false );

		( new SmallOrderFee() )->add( $cart );
	}
}
