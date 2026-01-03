<?php
/**
 * Tests for WPAllImportSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\WPAllImportSettings;
use FAToolkit\Tests\TestCase;

/**
 * Test WPAllImportSettings
 *
 * Note: This class is auto-initialized. All methods are private and not called
 * anywhere in the codebase (dead code). Testing via reflection to verify logic.
 */
class WPAllImportSettingsTest extends TestCase {

	/**
	 * Test davidsons_upc_clean removes # from UPC code.
	 */
	public function test_davidsons_upc_clean() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'davidsons_upc_clean', array( '#12345#' ) );
		$this->assertSame( '12345', $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_upc_clean', array( '12345' ) );
		$this->assertSame( '12345', $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_upc_clean', array( '#ABC#123#' ) );
		$this->assertSame( 'ABC123', $result );
	}

	/**
	 * Test davidsons_quantity_combine handles A* values.
	 */
	public function test_davidsons_quantity_combine_with_asterisk() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( 'A*', '10' ) );
		$this->assertSame( 0, $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( '10', 'A*' ) );
		$this->assertSame( 0, $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( 'A*', 'A*' ) );
		$this->assertSame( 0, $result );
	}

	/**
	 * Test davidsons_quantity_combine adds numeric values.
	 */
	public function test_davidsons_quantity_combine_with_numbers() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( '10', '5' ) );
		$this->assertSame( 15.0, $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( '0', '0' ) );
		$this->assertSame( 0.0, $result );

		$result = $this->invokePrivateMethod( $settings, 'davidsons_quantity_combine', array( '7.5', '2.5' ) );
		$this->assertSame( 10.0, $result );
	}

	/**
	 * Test cssi_check_stock returns 0 for empty quantity.
	 */
	public function test_cssi_check_stock_with_empty_quantity() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'cssi_check_stock', array( '', 'No' ) );
		$this->assertSame( 0, $result );

		$result = $this->invokePrivateMethod( $settings, 'cssi_check_stock', array( '', 'Yes' ) );
		$this->assertSame( 0, $result );
	}

	/**
	 * Test cssi_check_stock returns 0 for allocated items.
	 */
	public function test_cssi_check_stock_with_allocated() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'cssi_check_stock', array( '10', 'Yes' ) );
		$this->assertSame( 0, $result );
	}

	/**
	 * Test cssi_check_stock returns quantity for non-allocated items.
	 */
	public function test_cssi_check_stock_with_non_allocated() {
		$settings = new WPAllImportSettings();

		$result = $this->invokePrivateMethod( $settings, 'cssi_check_stock', array( '10', 'No' ) );
		$this->assertSame( '10', $result );

		$result = $this->invokePrivateMethod( $settings, 'cssi_check_stock', array( '5', '' ) );
		$this->assertSame( '5', $result );
	}

	/**
	 * Helper method to invoke private methods via reflection.
	 *
	 * @param object $object     The object instance.
	 * @param string $method_name The private method name.
	 * @param array  $parameters The method parameters.
	 *
	 * @return mixed The method result.
	 */
	private function invokePrivateMethod( $object, $method_name, array $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}
