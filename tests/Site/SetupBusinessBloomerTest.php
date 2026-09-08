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
use PHPUnit\Framework\Attributes\DataProvider;

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

		// The markup is now built in the printf format string, so the date is
		// requested bare — no before/after wrapper handed to the_modified_date().
		Functions\expect( 'the_modified_date' )
			->once()
			->with( '', '', '', false )
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
	 * Test that the product date renders real markup, not escaped markup.
	 *
	 * The original form wrapped esc_html() around a string that already
	 * contained the <span>, so repairing the hook registration would have
	 * shown shoppers the literal tag text on every product page. This asserts
	 * the span survives as markup and that no escaped angle bracket appears.
	 *
	 * @return void
	 */
	public function test_bloomer_echo_product_date_outputs_unescaped_markup() {
		Functions\expect( 'is_product' )->once()->andReturn( true );

		Functions\expect( 'the_modified_date' )
			->once()
			->with( '', '', '', false )
			->andReturn( 'January 1, 2026' );

		Functions\when( 'esc_html' )->returnArg();

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bloomer_echo_product_date();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<span class="single_product_date_published">Updated: January 1, 2026</span>',
			$output
		);
		$this->assertStringNotContainsString( '&lt;span', $output );
	}

	/**
	 * Test that the product date prints nothing when no date is available.
	 *
	 * @return void
	 */
	public function test_bloomer_echo_product_date_skips_empty_date() {
		Functions\expect( 'is_product' )->once()->andReturn( true );

		Functions\expect( 'the_modified_date' )
			->once()
			->with( '', '', '', false )
			->andReturn( '' );

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bloomer_echo_product_date();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'An empty date must not produce an empty span.' );
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

		// HPOS-safe: the weight goes through the order object's CRUD methods,
		// not update_post_meta(). With custom order tables enabled, a post-meta
		// write against an order id does not land where WooCommerce reads it.
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_cart_weight', 12.5 );
		$order->shouldReceive( 'save_meta_data' )->once();
		// A full save() here would re-persist the whole order mid-checkout
		// and fire woocommerce_update_order; only the meta may be written.
		$order->shouldNotReceive( 'save' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 123 )
			->andReturn( $order );

		Functions\expect( 'update_post_meta' )->never();

		$bloomer = new SetupBusinessBloomer();
		$bloomer->bbloomer_save_weight_order( 123 );
	}

	/**
	 * Test that save_weight_order bails when the order cannot be loaded.
	 *
	 * @return void
	 */
	public function test_save_weight_order_bails_on_missing_order() {
		Functions\expect( 'wc_get_order' )
			->once()
			->with( 404 )
			->andReturn( false );

		Functions\expect( 'WC' )->never();

		$bloomer = new SetupBusinessBloomer();

		// Returning cleanly is the behaviour under test: without the guard this
		// would fatal calling update_meta_data() on false.
		$this->assertNull( $bloomer->bbloomer_save_weight_order( 404 ) );
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

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_cart_weight', 0 );
		$order->shouldReceive( 'save_meta_data' )->once();
		// A full save() here would re-persist the whole order mid-checkout
		// and fire woocommerce_update_order; only the meta may be written.
		$order->shouldNotReceive( 'save' );

		Functions\expect( 'wc_get_order' )
			->once()
			->with( 456 )
			->andReturn( $order );

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
		$order->shouldReceive( 'get_meta' )
			->once()
			->with( '_cart_weight' )
			->andReturn( '15.75' );

		Functions\expect( 'get_post_meta' )->never();

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
	 * Test that the admin weight display prints nothing when meta is absent.
	 *
	 * Every order placed before this hook worked has no _cart_weight, which is
	 * all of them. The previous behaviour printed a bare "Order Weight:  kg"
	 * on each one. Absent meta must now render nothing at all.
	 *
	 * @return void
	 */
	public function test_delivery_weight_display_handles_empty_weight() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )
			->once()
			->with( '_cart_weight' )
			->andReturn( '' );

		Functions\expect( 'get_option' )->never();

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bbloomer_delivery_weight_display_admin_order_meta( $order );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Absent cart weight must render nothing.' );
	}

	/**
	 * Test that a zero weight still renders.
	 *
	 * Zero is a real measurement, not absent meta, so the guard must not
	 * swallow it — otherwise a genuinely weightless order looks unrecorded.
	 *
	 * @return void
	 */
	public function test_delivery_weight_display_renders_zero_weight() {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )
			->once()
			->with( '_cart_weight' )
			->andReturn( 0 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_weight_unit' )
			->andReturn( 'kg' );

		Functions\when( 'esc_html' )->returnArg();

		$bloomer = new SetupBusinessBloomer();

		ob_start();
		$bloomer->bbloomer_delivery_weight_display_admin_order_meta( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<strong>Order Weight:</strong>', $output );
		$this->assertStringContainsString( '0', $output );
		$this->assertStringContainsString( 'kg', $output );
	}

	/**
	 * Every hook the constructor is responsible for registering.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:int,4:int}>
	 */
	public static function hook_registration_provider() {
		return array(
			'product date'      => array( 'action', 'woocommerce_single_product_summary', 'bloomer_echo_product_date', 25, 1 ),
			'price filter'      => array( 'filter', 'woocommerce_get_price_html', 'bbloomer_hide_price_if_out_stock_frontend', 9999, 2 ),
			'save order weight' => array( 'action', 'woocommerce_checkout_update_order_meta', 'bbloomer_save_weight_order', 10, 1 ),
			'admin weight'      => array( 'action', 'woocommerce_admin_order_data_after_billing_address', 'bbloomer_delivery_weight_display_admin_order_meta', 10, 1 ),
		);
	}

	/**
	 * Test that each hook is registered as a callback bound to this instance.
	 *
	 * The constructor originally registered all four callbacks as bare
	 * function-name strings. Nothing in PHP or WordPress rejects that at
	 * registration time — add_action() stores whatever it is given — so the
	 * class instantiated cleanly and every direct-invocation test passed at
	 * 100% coverage. The failure only surfaced when WordPress tried to call
	 * one: call_user_func_array() in class-wp-hook.php fatals on a function
	 * name that does not exist at global scope, which is why every product
	 * surface returned HTTP 500 while the suite stayed green.
	 *
	 * Asserting on the shape of the registered callback, rather than only on
	 * the behaviour of the method it points at, is what closes that gap.
	 *
	 * @param string $type     Either 'action' or 'filter'.
	 * @param string $hook     Hook name.
	 * @param string $method   Method expected to be bound to the hook.
	 * @param int    $priority Expected priority.
	 * @param int    $args     Expected accepted argument count.
	 * @return void
	 */
	#[DataProvider( 'hook_registration_provider' )]
	public function test_constructor_registers_hook_as_bound_callback( $type, $hook, $method, $priority, $args ) {
		$captured = array();

		$expectation = 'filter' === $type
			? Filters\expectAdded( $hook )
			: Actions\expectAdded( $hook );

		$expectation->once()->whenHappen(
			function ( $callback, $registered_priority, $accepted_args ) use ( &$captured ) {
				$captured = array( $callback, $registered_priority, $accepted_args );
			}
		);

		$bloomer = new SetupBusinessBloomer();

		$this->assertNotEmpty( $captured, sprintf( 'Hook "%s" was never registered.', $hook ) );

		// The bound instance must be the object that did the registering, not
		// merely some SetupBusinessBloomer — a callback bound to a different
		// instance would dispatch, but against the wrong object.
		$this->assertSame(
			array( $bloomer, $method ),
			$captured[0],
			sprintf( 'Hook "%s" must be bound to $this and method "%s".', $hook, $method )
		);
		$this->assertIsCallable( $captured[0], sprintf( 'Callback for "%s" is not invocable.', $hook ) );
		$this->assertSame( $priority, $captured[1], sprintf( 'Wrong priority for "%s".', $hook ) );
		$this->assertSame( $args, $captured[2], sprintf( 'Wrong accepted arg count for "%s".', $hook ) );
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

		$bloomer = new SetupBusinessBloomer();

		$this->assertCount( 4, $registered, 'Expected all four hooks to be registered.' );

		foreach ( $registered as $callback ) {
			// describe_callback() must not itself fatal on a malformed callback:
			// this runs on the failure path, and a formatter that throws would
			// replace the real assertion message with an unrelated TypeError.
			$this->assertIsCallable(
				$callback,
				sprintf(
					'Registered callback %s is not invocable; WordPress would fatal on it.',
					$this->describe_callback( $callback )
				)
			);
			$this->assertSame( $bloomer, $callback[0], 'Callback is bound to the wrong instance.' );
		}
	}

	/**
	 * Render any callback as a readable string, without assuming its shape.
	 *
	 * @param mixed $callback Whatever was registered.
	 * @return string
	 */
	private function describe_callback( $callback ) {
		if ( is_string( $callback ) ) {
			return sprintf( 'string("%s")', $callback );
		}

		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : var_export( $callback[0], true );

			return sprintf( '%s::%s', $target, is_string( $callback[1] ) ? $callback[1] : var_export( $callback[1], true ) );
		}

		return get_debug_type( $callback );
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

		$bloomer = new SetupBusinessBloomer();

		// Assert the capture succeeded before dispatching. Without this, a
		// filter that was never registered fails as a TypeError from
		// call_user_func_array( null, ... ), which hides the actual cause.
		$this->assertSame(
			array( $bloomer, 'bbloomer_hide_price_if_out_stock_frontend' ),
			$callback,
			'The price filter was not registered as a bound callback.'
		);

		Functions\when( 'is_admin' )->justReturn( false );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_in_stock' )->once()->andReturn( true );

		// Dispatch exactly as WP_Hook::apply_filters() does.
		$result = call_user_func_array( $callback, array( '<span>$19.99</span>', $product ) );

		$this->assertSame( '<span>$19.99</span>', $result );
	}
}
