<?php
/**
 * Tests for SetupBusinessBloomer class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\SetupBusinessBloomer;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test case for SetupBusinessBloomer.
 */
class SetupBusinessBloomerTest extends TestCase {

	/**
	 * Test that bloomer_echo_product_date outputs date on product pages.
	 *
	 * @return void
	 */
	public function test_bloomer_echo_product_date_outputs_on_product_page() {
		Functions\expect( 'is_product' )
			->once()
			->andReturn( true );

		Functions\expect( 'the_modified_date' )
			->once()
			->with( '', '<span class="single_product_date_published">Updated: ', '</span>', false )
			->andReturn( 'January 1, 2026' );

		// esc_html must be stubbed HERE, not relied on from tests/bootstrap.php.
		// Brain Monkey's setUp() resets every stub before each test, so the
		// when( 'esc_html' ) call at bootstrap scope never reaches this test.
		Functions\when( 'esc_html' )->returnArg();

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bloomer_echo_product_date();
		$output = ob_get_clean();

		// Verify the date is output.
		$this->assertStringContainsString( 'January 1, 2026', $output );
	}

	/**
	 * Test that bloomer_echo_product_date does nothing on non-product pages.
	 *
	 * @return void
	 */
	public function test_bloomer_echo_product_date_skips_non_product_page() {
		Functions\expect( 'is_product' )
			->once()
			->andReturn( false );

		Functions\expect( 'the_modified_date' )
			->never();

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bloomer_echo_product_date();
		$output = ob_get_clean();

		// Verify nothing is output.
		$this->assertEmpty( $output );
	}

	/**
	 * Test that bbloomer_hide_price_if_out_stock_frontend returns price on admin.
	 *
	 * @return void
	 */
	public function test_hide_price_returns_price_on_admin() {
		Functions\expect( 'is_admin' )
			->once()
			->andReturn( true );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldNotReceive( 'is_in_stock' );

		$bloomer = new SetupBusinessBloomer();

		$result = $bloomer->bbloomer_hide_price_if_out_stock_frontend( '$99.99', $product );

		// Verify price is returned unchanged.
		$this->assertSame( '$99.99', $result );
	}

	/**
	 * Test that bbloomer_hide_price_if_out_stock_frontend hides price when out of stock.
	 *
	 * @return void
	 */
	public function test_hide_price_hides_price_when_out_of_stock() {
		Functions\expect( 'is_admin' )
			->once()
			->andReturn( false );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'woocommerce_empty_price_html', '', Mockery::type( 'Mockery\MockInterface' ) )
			->andReturn( '' );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_in_stock' )
			->once()
			->andReturn( false );

		$bloomer = new SetupBusinessBloomer();

		$result = $bloomer->bbloomer_hide_price_if_out_stock_frontend( '$99.99', $product );

		// Verify price is hidden (empty string).
		$this->assertSame( '', $result );
	}

	/**
	 * Test that bbloomer_hide_price_if_out_stock_frontend returns price when in stock.
	 *
	 * @return void
	 */
	public function test_hide_price_returns_price_when_in_stock() {
		Functions\expect( 'is_admin' )
			->once()
			->andReturn( false );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_in_stock' )
			->once()
			->andReturn( true );

		$bloomer = new SetupBusinessBloomer();

		$result = $bloomer->bbloomer_hide_price_if_out_stock_frontend( '$99.99', $product );

		// Verify price is returned unchanged.
		$this->assertSame( '$99.99', $result );
	}

	/**
	 * Test that bbloomer_save_weight_order saves cart weight to order meta.
	 *
	 * @return void
	 */
	public function test_save_weight_order_saves_cart_weight() {
		// Mock WC() global function and cart.
		$cart = Mockery::mock( 'WC_Cart' );
		$cart->shouldReceive( 'get_cart_contents_weight' )
			->once()
			->andReturn( 12.5 );

		$wc = Mockery::mock( 'stdClass' );
		$wc->cart = $cart;

		Functions\expect( 'WC' )
			->once()
			->andReturn( $wc );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, '_cart_weight', 12.5 );

		$bloomer = new SetupBusinessBloomer();
		$bloomer->bbloomer_save_weight_order( 123 );
	}

	/**
	 * Test that bbloomer_save_weight_order handles zero weight.
	 *
	 * @return void
	 */
	public function test_save_weight_order_handles_zero_weight() {
		$cart = Mockery::mock( 'WC_Cart' );
		$cart->shouldReceive( 'get_cart_contents_weight' )
			->once()
			->andReturn( 0 );

		$wc = Mockery::mock( 'stdClass' );
		$wc->cart = $cart;

		Functions\expect( 'WC' )
			->once()
			->andReturn( $wc );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 456, '_cart_weight', 0 );

		$bloomer = new SetupBusinessBloomer();
		$bloomer->bbloomer_save_weight_order( 456 );
	}

	/**
	 * Test that bbloomer_delivery_weight_display_admin_order_meta displays weight.
	 *
	 * @return void
	 */
	public function test_delivery_weight_display_outputs_weight() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )
			->once()
			->andReturn( 789 );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 789, '_cart_weight', true )
			->andReturn( '15.75' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_weight_unit' )
			->andReturn( 'lbs' );

		Functions\expect( 'esc_html' )
			->times( 2 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bbloomer_delivery_weight_display_admin_order_meta( $order );
		$output = ob_get_clean();

		// Verify weight and unit are displayed.
		$this->assertStringContainsString( '<strong>Order Weight:</strong>', $output );
		$this->assertStringContainsString( '15.75', $output );
		$this->assertStringContainsString( 'lbs', $output );
	}

	/**
	 * Test that bbloomer_delivery_weight_display_admin_order_meta handles empty weight.
	 *
	 * @return void
	 */
	public function test_delivery_weight_display_handles_empty_weight() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )
			->once()
			->andReturn( 999 );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 999, '_cart_weight', true )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_weight_unit' )
			->andReturn( 'kg' );

		Functions\expect( 'esc_html' )
			->times( 2 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bbloomer_delivery_weight_display_admin_order_meta( $order );
		$output = ob_get_clean();

		// Verify output even with empty weight.
		$this->assertStringContainsString( '<strong>Order Weight:</strong>', $output );
		$this->assertStringContainsString( 'kg', $output );
	}
}
