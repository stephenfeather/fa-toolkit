<?php
/**
 * Tests for HposCompatibility.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Compat;

use FAToolkit\Compat\HposCompatibility;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Actions;
use Automattic\WooCommerce\Utilities\FeaturesUtil as FeaturesUtilSpy;

/**
 * Test case for HposCompatibility.
 *
 * The declaration this class makes is a CLAIM about the whole plugin, and a
 * claim that silently stops being made is the exact defect shape this codebase
 * already shipped once: four hooks registered so that WordPress could never
 * call them, under 100% coverage, because the tests only ever invoked the
 * methods directly. So these tests assert the registration and the resulting
 * call, never merely that the method exists.
 */
class HposCompatibilityTest extends TestCase {

	/**
	 * Test that the constructor registers on before_woocommerce_init.
	 *
	 * @return void
	 */
	public function test_constructor_registers_on_before_woocommerce_init() {
		$captured = array();

		Actions\expectAdded( 'before_woocommerce_init' )
			->once()
			->whenHappen(
				function ( $callback, $priority, $accepted ) use ( &$captured ) {
					$captured = array( $callback, $priority, $accepted );
				}
			);

		$compat = new HposCompatibility();

		$this->assertNotEmpty( $captured, 'before_woocommerce_init was never hooked.' );
		$this->assertSame(
			array( $compat, 'declare_compatibility' ),
			$captured[0],
			'The callback must be bound to this instance, not a bare function name.'
		);
		$this->assertIsCallable( $captured[0] );
	}

	/**
	 * Test that dispatching the registered callback declares custom_order_tables.
	 *
	 * Dispatches the callback exactly as WordPress would, rather than calling
	 * the method directly, so that a broken registration fails here too.
	 *
	 * @return void
	 */
	public function test_registered_callback_declares_custom_order_tables() {
		FeaturesUtilSpy::reset();

		$callback = null;
		Actions\expectAdded( 'before_woocommerce_init' )
			->once()
			->whenHappen(
				function ( $registered ) use ( &$callback ) {
					$callback = $registered;
				}
			);

		$compat = new HposCompatibility();

		$this->assertSame( array( $compat, 'declare_compatibility' ), $callback );

		call_user_func( $callback );

		$this->assertSame(
			array( array( 'custom_order_tables', HposCompatibility::plugin_file(), true ) ),
			FeaturesUtilSpy::$calls,
			'Exactly one declaration must be made, for custom_order_tables.'
		);
	}

	/**
	 * Test that cart_checkout_blocks is deliberately NOT declared.
	 *
	 * fa-toolkit registers bbloomer_save_weight_order on the CLASSIC checkout
	 * hook woocommerce_checkout_update_order_meta, which WooCommerce fires only
	 * from includes/class-wc-checkout.php. The block checkout goes through the
	 * Store API and fires woocommerce_store_api_checkout_update_order_meta
	 * instead. Until that gap is addressed the plugin has NOT been shown to
	 * work with block cart and checkout, so it must not claim it.
	 *
	 * This test exists so that adding the claim requires deleting an assertion
	 * that says why it was withheld.
	 *
	 * @return void
	 */
	public function test_does_not_declare_cart_checkout_blocks() {
		FeaturesUtilSpy::reset();

		$compat = new HposCompatibility();
		$compat->declare_compatibility();

		$declared = array_column( FeaturesUtilSpy::$calls, 0 );

		$this->assertNotContains( 'cart_checkout_blocks', $declared );
		$this->assertNotContains( 'product_block_editor', $declared );
	}

	/**
	 * Test that a missing FeaturesUtil is survived rather than fataled on.
	 *
	 * The plugin must not white-screen a site running a WooCommerce too old to
	 * have FeaturesUtil, or no WooCommerce at all.
	 *
	 * @return void
	 */
	public function test_declaring_is_skipped_when_featuresutil_is_absent() {
		$compat = new HposCompatibility();

		$this->assertNull( $compat->declare_compatibility( '\Does\Not\Exist' ) );
	}

	/**
	 * Test that the declared plugin file is the main plugin bootstrap.
	 *
	 * WooCommerce keys the declaration by plugin file, so pointing at the class
	 * file instead of fa-toolkit.php would register the claim against a path
	 * that is not a plugin, and it would silently not apply.
	 *
	 * @return void
	 */
	public function test_plugin_file_points_at_the_main_plugin_file() {
		$this->assertSame( 'fa-toolkit.php', basename( HposCompatibility::plugin_file() ) );
		$this->assertFileExists( HposCompatibility::plugin_file() );
	}
}
