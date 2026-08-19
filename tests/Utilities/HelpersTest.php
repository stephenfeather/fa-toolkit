<?php
/**
 * Tests for Helpers utility class.
 *
 * @package FAToolkit
 */

namespace FAToolkit\Tests\Utilities;

use FAToolkit\Tests\TestCase;
use FAToolkit\Utilities\Helpers;

/**
 * Test Helpers utility class.
 */
class HelpersTest extends TestCase {

	/**
	 * Test is_empty returns true for empty string.
	 */
	public function test_is_empty_returns_true_for_empty_string() {
		$this->assertTrue( Helpers::is_empty( '' ) );
	}

	/**
	 * Test is_empty returns true for null.
	 */
	public function test_is_empty_returns_true_for_null() {
		$this->assertTrue( Helpers::is_empty( null ) );
	}

	/**
	 * Test is_empty returns true for zero.
	 */
	public function test_is_empty_returns_true_for_zero() {
		$this->assertTrue( Helpers::is_empty( 0 ) );
	}

	/**
	 * Test is_empty returns true for empty array.
	 */
	public function test_is_empty_returns_true_for_empty_array() {
		$this->assertTrue( Helpers::is_empty( array() ) );
	}

	/**
	 * Test is_empty returns false for non-empty string.
	 */
	public function test_is_empty_returns_false_for_non_empty_string() {
		$this->assertFalse( Helpers::is_empty( 'hello' ) );
	}

	/**
	 * Test is_empty returns false for non-zero number.
	 */
	public function test_is_empty_returns_false_for_non_zero_number() {
		$this->assertFalse( Helpers::is_empty( 42 ) );
	}

	/**
	 * Test is_empty returns false for non-empty array.
	 */
	public function test_is_empty_returns_false_for_non_empty_array() {
		$this->assertFalse( Helpers::is_empty( array( 'item' ) ) );
	}

	/**
	 * An undefined constant must return false, NOT throw.
	 *
	 * This is the regression. The guards this helper replaces read
	 * `defined( 'WP_CLI' ) === false && WP_CLI === false`, which evaluates the
	 * right operand when the constant is undefined and throws "Undefined
	 * constant". If this test ever fails with an Error rather than an
	 * assertion failure, that idiom has come back.
	 */
	public function test_is_constant_true_returns_false_for_undefined_constant() {
		$this->assertFalse( Helpers::is_constant_true( 'FA_TOOLKIT_DEFINITELY_NOT_DEFINED' ) );
	}

	/**
	 * Test is_constant_true returns true for a defined truthy constant.
	 */
	public function test_is_constant_true_returns_true_for_truthy_constant() {
		define( 'FA_TOOLKIT_TEST_TRUTHY', true );
		$this->assertTrue( Helpers::is_constant_true( 'FA_TOOLKIT_TEST_TRUTHY' ) );
	}

	/**
	 * Test is_constant_true returns false for a defined but falsy constant.
	 */
	public function test_is_constant_true_returns_false_for_falsy_constant() {
		define( 'FA_TOOLKIT_TEST_FALSY', false );
		$this->assertFalse( Helpers::is_constant_true( 'FA_TOOLKIT_TEST_FALSY' ) );
	}

	/**
	 * The test bootstrap defines WP_CLI as true, so this reflects a CLI context.
	 */
	public function test_is_wp_cli_returns_true_when_wp_cli_is_defined_and_true() {
		$this->assertTrue( Helpers::is_wp_cli() );
	}
}
