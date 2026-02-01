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
}
