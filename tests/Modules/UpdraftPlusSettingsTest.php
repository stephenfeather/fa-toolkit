<?php
/**
 * Tests for UpdraftPlusSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\UpdraftPlusSettings;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test UpdraftPlusSettings
 *
 * Note: This class is auto-initialized in the source file, so constructor
 * hook registration happens at file load time and cannot be tested with
 * Brain Monkey expectations. We test the method logic instead.
 */
class UpdraftPlusSettingsTest extends TestCase {

	/**
	 * Test excluding .git directory.
	 */
	public function test_excludes_git_directory() {
		$settings = new UpdraftPlusSettings();
		$result   = $settings->my_updraftplus_exclude_directory( false, '/path/to/.git' );

		$this->assertTrue( $result );
	}

	/**
	 * Test excluding .vscode directory.
	 */
	public function test_excludes_vscode_directory() {
		$settings = new UpdraftPlusSettings();
		$result   = $settings->my_updraftplus_exclude_directory( false, '/path/to/.vscode' );

		$this->assertTrue( $result );
	}

	/**
	 * Test not excluding non-listed directory when filter is false.
	 */
	public function test_does_not_exclude_other_directory_when_filter_false() {
		$settings = new UpdraftPlusSettings();
		$result   = $settings->my_updraftplus_exclude_directory( false, '/path/to/some-other-dir' );

		$this->assertFalse( $result );
	}

	/**
	 * Test preserving filter value for non-listed directory.
	 */
	public function test_preserves_filter_value_for_non_listed_directory() {
		$settings = new UpdraftPlusSettings();
		$result   = $settings->my_updraftplus_exclude_directory( true, '/path/to/some-other-dir' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that excluded directories override filter value.
	 */
	public function test_excluded_directories_override_filter() {
		$settings = new UpdraftPlusSettings();

		// Even if filter is false, .git should be excluded.
		$result = $settings->my_updraftplus_exclude_directory( false, '/path/to/.git' );
		$this->assertTrue( $result );

		// Even if filter is true, .git should still be excluded.
		$result = $settings->my_updraftplus_exclude_directory( true, '/another/path/.git' );
		$this->assertTrue( $result );
	}
}
