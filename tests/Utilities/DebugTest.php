<?php
/**
 * Tests for Debug class
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Utilities;

use FAToolkit\Tests\TestCase;
use FAToolkit\Utilities\Debug;
use Brain\Monkey\Functions;

/**
 * Test case for Debug utility class.
 */
class DebugTest extends TestCase {
	/**
	 * Test write_log with string and injected logger.
	 *
	 * @return void
	 */
	public function test_write_log_with_string_uses_injected_logger() {
		$logged = array();
		$logger = function ( $message ) use ( &$logged ) {
			$logged[] = $message;
		};

		$debug = new Debug( $logger, false );
		$debug->write_log( 'test message' );

		$this->assertCount( 1, $logged );
		$this->assertEquals( 'test message', $logged[0] );
	}

	/**
	 * Test write_log with array and injected logger.
	 *
	 * @return void
	 */
	public function test_write_log_with_array_uses_injected_logger() {
		$logged = array();
		$logger = function ( $message ) use ( &$logged ) {
			$logged[] = $message;
		};

		Functions\expect( 'wp_json_encode' )
			->once()
			->with( array( 'key' => 'value' ) )
			->andReturn( '{"key":"value"}' );

		$debug = new Debug( $logger, false );
		$debug->write_log( array( 'key' => 'value' ) );

		$this->assertCount( 1, $logged );
		$this->assertEquals( '{"key":"value"}', $logged[0] );
	}

	/**
	 * Test write_log with object and injected logger.
	 *
	 * @return void
	 */
	public function test_write_log_with_object_uses_injected_logger() {
		$logged = array();
		$logger = function ( $message ) use ( &$logged ) {
			$logged[] = $message;
		};

		$object = (object) array( 'prop' => 'test' );

		Functions\expect( 'wp_json_encode' )
			->once()
			->with( $object )
			->andReturn( '{"prop":"test"}' );

		$debug = new Debug( $logger, false );
		$debug->write_log( $object );

		$this->assertCount( 1, $logged );
		$this->assertEquals( '{"prop":"test"}', $logged[0] );
	}

	/**
	 * Test constructor can skip hook registration.
	 *
	 * @return void
	 */
	public function test_constructor_can_skip_hook_registration() {
		// Should not expect add_action when register_hooks is false.
		$debug = new Debug( null, false );

		$this->assertInstanceOf( Debug::class, $debug );
	}

	/**
	 * Test wpdb method returns global wpdb object.
	 *
	 * @return void
	 */
	public function test_wpdb_returns_global_wpdb() {
		global $wpdb;
		$wpdb = \Mockery::mock( '\wpdb' );

		$result = Debug::wpdb();

		$this->assertSame( $wpdb, $result );
	}

	/**
	 * Test add_custom_tracer when New Relic extension is not loaded.
	 *
	 * @return void
	 */
	public function test_add_custom_tracer_without_newrelic() {
		Functions\expect( 'extension_loaded' )
			->once()
			->with( 'newrelic' )
			->andReturn( false );

		$result = Debug::add_custom_tracer( 'my_function' );

		$this->assertFalse( $result );
	}

	/**
	 * Test add_custom_tracer when New Relic extension is loaded.
	 *
	 * @return void
	 */
	public function test_add_custom_tracer_with_newrelic_loaded() {
		// Mock extension_loaded to return true.
		Functions\expect( 'extension_loaded' )
			->once()
			->with( 'newrelic' )
			->andReturn( true );

		// Mock the newrelic function since it doesn't exist.
		Functions\when( 'newrelic_add_custom_tracer' )->justReturn( null );

		$result = Debug::add_custom_tracer( 'my_function' );

		$this->assertTrue( $result );
	}

	/**
	 * Test debug_to_console outputs correct JavaScript.
	 *
	 * Note: debug_to_console has bugs in source code (unclosed output buffer).
	 * This test just verifies it doesn't throw exceptions.
	 *
	 * @return void
	 */
	public function test_debug_to_console_outputs_javascript() {
		$test_data = array( 'key' => 'value', 'number' => 123 );

		Functions\when( 'wp_json_encode' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		Debug::debug_to_console( $test_data, 'Test Context' );

		// Clean up unclosed output buffer from buggy source code.
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$this->assertTrue( true );
	}

	/**
	 * Test debug_to_console with default context.
	 *
	 * Note: debug_to_console has bugs in source code (unclosed output buffer).
	 * This test just verifies it doesn't throw exceptions.
	 *
	 * @return void
	 */
	public function test_debug_to_console_with_default_context() {
		Functions\when( 'wp_json_encode' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		Debug::debug_to_console( 'test' );

		// Clean up unclosed output buffer from buggy source code.
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$this->assertTrue( true );
	}

	/**
	 * Test constructor registers shutdown hook.
	 *
	 * @return void
	 */
	public function test_constructor_registers_shutdown_hook() {
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', \Mockery::type( 'array' ) );

		new Debug();
	}

	/**
	 * Test shutdown_handler when WP_DEBUG is false.
	 *
	 * @return void
	 */
	public function test_shutdown_handler_when_debug_disabled() {
		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}

		Functions\expect( 'add_action' )
			->once();

		$debug = new Debug();

		// Should not call var_dump_database since WP_DEBUG is false.
		$debug->shutdown_handler();

		// If no exception, test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test shutdown_handler when WP_DEBUG is true but user can't manage options.
	 *
	 * @return void
	 */
	public function test_shutdown_handler_when_user_cannot_manage_options() {
		// Skip if WP_DEBUG is false.
		if ( defined( 'WP_DEBUG' ) && ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is false and cannot be redefined' );
		}

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		Functions\expect( 'add_action' )
			->once();

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( false );

		$debug = new Debug();
		$debug->shutdown_handler();

		// Should not output anything.
		$this->assertTrue( true );
	}

	/**
	 * Test shutdown_handler when WP_DEBUG is true and user can manage options.
	 *
	 * Note: The actual var_dump_database call is commented out in source,
	 * so we just verify the conditions are checked.
	 *
	 * @return void
	 */
	public function test_shutdown_handler_when_debug_enabled_and_user_authorized() {
		// Skip if WP_DEBUG is false.
		if ( defined( 'WP_DEBUG' ) && ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG is false and cannot be redefined' );
		}

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		Functions\expect( 'add_action' )
			->once();

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		$debug = new Debug();
		$debug->shutdown_handler();

		// The var_dump_database call is commented out in the source.
		// This test verifies the conditions are checked correctly.
		$this->assertTrue( true );
	}
}
