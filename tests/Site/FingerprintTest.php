<?php
/**
 * Tests for Fingerprint class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\Fingerprint;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test case for Fingerprint.
 */
class FingerprintTest extends TestCase {

	/**
	 * Test that register_scripts registers the FingerprintJS script.
	 *
	 * @return void
	 */
	public function test_register_scripts_registers_fingerprint_script() {
		Functions\expect( 'wp_register_script' )
			->once()
			->with(
				'iife',
				'https://fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx/iife.min.js',
				array(),
				'3.0.0',
				true
			);

		$fingerprint = new Fingerprint();
		$fingerprint->register_scripts();
	}

	/**
	 * Test that add_jscript_checkout outputs the checkout fingerprint script.
	 *
	 * @return void
	 */
	public function test_add_jscript_checkout_outputs_script() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( 'test_session_123' );

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 42 );

		Functions\expect( 'esc_html' )
			->times( 2 )
			->andReturnFirstArg();

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->add_jscript_checkout();
		$output = ob_get_clean();

		// Verify script structure.
		$this->assertStringContainsString( '<script id="fingerprint">', $output );
		$this->assertStringContainsString( 'Initializing Fingerprint', $output );
		$this->assertStringContainsString( 'fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx', $output );
		$this->assertStringContainsString( 'apiKey: \'Oo4CqqyVw0pCzwTpD4Mx\'', $output );
		$this->assertStringContainsString( 'endpoint: \'https://metrics.featherarms.com\'', $output );
		$this->assertStringContainsString( '</script>', $output );
	}

	/**
	 * Test that add_jscript_checkout includes session ID.
	 *
	 * @return void
	 */
	public function test_add_jscript_checkout_includes_session_id() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( 'abc123session' );

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 99 );

		Functions\expect( 'esc_html' )
			->times( 2 )
			->andReturnFirstArg();

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->add_jscript_checkout();
		$output = ob_get_clean();

		// Verify session ID is in the output.
		$this->assertStringContainsString( 'abc123session', $output );
		$this->assertStringContainsString( 'PHPSESSID:', $output );
	}

	/**
	 * Test that add_jscript_checkout includes user ID.
	 *
	 * @return void
	 */
	public function test_add_jscript_checkout_includes_user_id() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( 'session_xyz' );

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 777 );

		Functions\expect( 'esc_html' )
			->times( 2 )
			->andReturnFirstArg();

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->add_jscript_checkout();
		$output = ob_get_clean();

		// Verify user ID is in the output.
		$this->assertStringContainsString( '777', $output );
		$this->assertStringContainsString( 'userID:', $output );
	}

	/**
	 * Test that response_handler builds and outputs the FingerprintJS loader.
	 *
	 * @return void
	 */
	public function test_response_handler_builds_script() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( '0' ); // session_id() casted to int becomes 0.

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 123 );

		Functions\expect( 'wp_kses_post' )
			->once()
			->andReturnUsing(
				function ( $script ) {
					echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					return $script;
				}
			);

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->response_handler();
		$output = ob_get_clean();

		// Verify script structure.
		$this->assertStringContainsString( '<script async id="FingerPrint">', $output );
		$this->assertStringContainsString( 'Initializing Fingerprint', $output );
		$this->assertStringContainsString( 'FingerprintJS.load', $output );
		$this->assertStringContainsString( 'apiKey: "Oo4CqqyVw0pCzwTpD4Mx"', $output );
		$this->assertStringContainsString( 'endpoint: "https://metrics.featherarms.com"', $output );
		$this->assertStringContainsString( '</script>', $output );
	}

	/**
	 * Test that response_handler includes session ID as integer.
	 *
	 * @return void
	 */
	public function test_response_handler_includes_session_id() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( '12345' ); // Will be cast to int.

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 456 );

		Functions\expect( 'wp_kses_post' )
			->once()
			->andReturnUsing(
				function ( $script ) {
					echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					return $script;
				}
			);

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->response_handler();
		$output = ob_get_clean();

		// Verify PHPSESSID is present (session_id cast to int).
		$this->assertStringContainsString( 'PHPSESSID: 12345', $output );
	}

	/**
	 * Test that response_handler includes user ID.
	 *
	 * @return void
	 */
	public function test_response_handler_includes_user_id() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( '0' );

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 999 );

		Functions\expect( 'wp_kses_post' )
			->once()
			->andReturnUsing(
				function ( $script ) {
					echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					return $script;
				}
			);

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->response_handler();
		$output = ob_get_clean();

		// Verify userID is present.
		$this->assertStringContainsString( 'userID: 999', $output );
	}

	/**
	 * Test that response_handler script is async.
	 *
	 * @return void
	 */
	public function test_response_handler_script_is_async() {
		Functions\expect( 'session_id' )
			->once()
			->andReturn( '0' );

		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 1 );

		Functions\expect( 'wp_kses_post' )
			->once()
			->andReturnUsing(
				function ( $script ) {
					echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					return $script;
				}
			);

		$fingerprint = new Fingerprint();

		ob_start();
		$fingerprint->response_handler();
		$output = ob_get_clean();

		// Verify async attribute.
		$this->assertStringContainsString( 'async', $output );
	}
}
