<?php
/**
 * Tests for SetupBusinessBloomer class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\SetupBusinessBloomer;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
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

	/**
	 * Matcher for a callback bound to a SetupBusinessBloomer method.
	 *
	 * The constructor originally registered these four callbacks as bare
	 * function-name strings. Nothing in PHP or WordPress rejects that at
	 * registration time — add_action() stores whatever it is given — so the
	 * class instantiated cleanly and every direct-invocation test passed at
	 * 100% coverage. The failure only surfaced when WordPress tried to call
	 * one: call_user_func_array() in class-wp-hook.php fatals on a function
	 * name that does not exist at global scope, which is why every product
	 * surface returned HTTP 500 while the suite stayed green.
	 *
	 * Asserting on the shape of the callback, not just on the behaviour of
	 * the method it points at, is what closes that gap.
	 *
	 * @param string $method Method name expected in the callback array.
	 * @return \Mockery\Matcher\Closure
	 */
	private function callback_to( $method ) {
		return Mockery::on(
			function ( $callback ) use ( $method ) {
				return is_array( $callback )
					&& 2 === count( $callback )
					&& $callback[0] instanceof SetupBusinessBloomer
					&& $method === $callback[1]
					&& is_callable( $callback );
			}
		);
	}

	/**
	 * Test that the product date action is registered as a bound callback.
	 *
	 * @return void
	 */
	public function test_constructor_registers_product_date_action() {
		Actions\expectAdded( 'woocommerce_single_product_summary' )
			->once()
			->with( $this->callback_to( 'bloomer_echo_product_date' ), 25 );

		new SetupBusinessBloomer();
	}

	/**
	 * Test that the price filter is registered as a bound callback.
	 *
	 * @return void
	 */
	public function test_constructor_registers_price_filter() {
		Filters\expectAdded( 'woocommerce_get_price_html' )
			->once()
			->with( $this->callback_to( 'bbloomer_hide_price_if_out_stock_frontend' ), 9999, 2 );

		new SetupBusinessBloomer();
	}

	/**
	 * Test that the order weight save action is registered as a bound callback.
	 *
	 * @return void
	 */
	public function test_constructor_registers_save_weight_action() {
		Actions\expectAdded( 'woocommerce_checkout_update_order_meta' )
			->once()
			->with( $this->callback_to( 'bbloomer_save_weight_order' ) );

		new SetupBusinessBloomer();
	}

	/**
	 * Test that the admin order weight display action is registered as a bound callback.
	 *
	 * @return void
	 */
	public function test_constructor_registers_admin_weight_display_action() {
		Actions\expectAdded( 'woocommerce_admin_order_data_after_billing_address' )
			->once()
			->with( $this->callback_to( 'bbloomer_delivery_weight_display_admin_order_meta' ), 10, 1 );

		new SetupBusinessBloomer();
	}

	/**
	 * Test that every registered callback is actually invocable.
	 *
	 * This is the assertion that maps directly onto the production failure:
	 * WordPress does not care what a callback looks like until it calls it,
	 * and a bare string naming a method that exists only on the class fatals
	 * at that moment. is_callable() on each registered callback is the cheap
	 * check that would have caught all four at once.
	 *
	 * @return void
	 */
	public function test_all_registered_callbacks_are_invocable() {
		$registered = array();

		$capture = function ( $callback ) use ( &$registered ) {
			$registered[] = $callback;
		};

		Actions\expectAdded( 'woocommerce_single_product_summary' )->once()->whenHappen( $capture );
		Filters\expectAdded( 'woocommerce_get_price_html' )->once()->whenHappen( $capture );
		Actions\expectAdded( 'woocommerce_checkout_update_order_meta' )->once()->whenHappen( $capture );
		Actions\expectAdded( 'woocommerce_admin_order_data_after_billing_address' )->once()->whenHappen( $capture );

		new SetupBusinessBloomer();

		$this->assertCount( 4, $registered, 'Expected all four hooks to be registered.' );

		foreach ( $registered as $callback ) {
			$this->assertIsCallable(
				$callback,
				sprintf(
					'Registered callback %s is not invocable; WordPress would fatal on it.',
					is_array( $callback ) ? implode( '::', array( get_class( $callback[0] ), $callback[1] ) ) : var_export( $callback, true )
				)
			);
		}
	}

	/**
	 * Test that the registered price callback survives WordPress-style dispatch.
	 *
	 * This is the closest local analogue to "the HTTP 500 is gone". The
	 * production fatal happened inside WP_Hook::apply_filters(), which does
	 * call_user_func_array() on whatever was registered — it is the dispatch,
	 * not the registration, that blows up. Asserting is_callable() alone would
	 * not catch a callback that is callable but wrongly bound, so this test
	 * takes the callback exactly as WordPress stored it and calls it the same
	 * way, on the specific hook that fataled on every price render.
	 *
	 * @return void
	 */
	public function test_registered_price_callback_dispatches_without_fatal() {
		$callback = null;

		Filters\expectAdded( 'woocommerce_get_price_html' )
			->once()
			->whenHappen(
				function ( $registered ) use ( &$callback ) {
					$callback = $registered;
				}
			);

		new SetupBusinessBloomer();

		Functions\when( 'is_admin' )->justReturn( false );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_in_stock' )->once()->andReturn( true );

		// Dispatch exactly as WP_Hook::apply_filters() does.
		$result = call_user_func_array( $callback, array( '<span>$19.99</span>', $product ) );

		$this->assertSame( '<span>$19.99</span>', $result );
	}
}
