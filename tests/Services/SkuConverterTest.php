<?php
/**
 * Tests for SkuConverter service class.
 *
 * @package FAToolkit
 */

namespace FAToolkit\Tests\Services;

use FAToolkit\Tests\TestCase;
use FAToolkit\Services\SkuConverter;

/**
 * Test SkuConverter service class.
 */
class SkuConverterTest extends TestCase {

	/**
	 * Test to_basename strips FA- prefix.
	 */
	public function test_to_basename_strips_fa_prefix() {
		$result = SkuConverter::to_basename( 'FA-12345' );
		$this->assertEquals( '12345', $result );
	}

	/**
	 * Test to_basename keeps non-FA SKUs unchanged.
	 */
	public function test_to_basename_keeps_non_fa_skus() {
		$result = SkuConverter::to_basename( 'ABC-12345' );
		$this->assertEquals( 'ABC-12345', $result );
	}

	/**
	 * Test to_basename with suffix.
	 */
	public function test_to_basename_with_suffix() {
		$result = SkuConverter::to_basename( 'FA-12345', '_1' );
		$this->assertEquals( '12345_1', $result );
	}

	/**
	 * Test to_filename strips FA- prefix and adds extension.
	 */
	public function test_to_filename_strips_fa_prefix_adds_extension() {
		$result = SkuConverter::to_filename( 'FA-12345' );
		$this->assertEquals( '12345.jpg', $result );
	}

	/**
	 * Test to_filename with custom extension.
	 */
	public function test_to_filename_with_custom_extension() {
		$result = SkuConverter::to_filename( 'FA-12345', 'png' );
		$this->assertEquals( '12345.png', $result );
	}

	/**
	 * Test to_filename with suffix.
	 */
	public function test_to_filename_with_suffix() {
		$result = SkuConverter::to_filename( 'FA-12345', 'jpg', '_thumb' );
		$this->assertEquals( '12345_thumb.jpg', $result );
	}

	/**
	 * Test to_filename keeps non-FA SKUs.
	 */
	public function test_to_filename_keeps_non_fa_skus() {
		$result = SkuConverter::to_filename( 'ABC-12345', 'jpg' );
		$this->assertEquals( 'ABC-12345.jpg', $result );
	}
}
