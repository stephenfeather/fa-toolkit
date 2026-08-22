<?php
/**
 * Tests for SHA256 utility class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\File;

use FAToolkit\File\SHA256;
use FAToolkit\Tests\TestCase;
use function Patchwork\replace;
use function Patchwork\getFunction;

/**
 * Test case for SHA256 class.
 */
class SHA256Test extends TestCase {

	/**
	 * Test creating a SHA256 hash from a file.
	 */
	public function test_create_returns_hash_from_file() {
		$file_path     = '/path/to/test/file.txt';
		$expected_hash = 'abc123def456';

		// Mock hash_file to return our expected hash.
		replace(
			'hash_file',
			function( $algorithm, $filename ) use ( $file_path, $expected_hash ) {
				$this->assertSame( 'sha256', $algorithm );
				$this->assertSame( $file_path, $filename );
				return $expected_hash;
			}
		);

		$result = SHA256::create( $file_path );

		$this->assertSame( $expected_hash, $result );
	}

	/**
	 * Test creating hash with different file paths.
	 */
	public function test_create_handles_different_file_paths() {
		$test_cases = array(
			'/path/to/file1.txt' => 'hash1',
			'/path/to/file2.txt' => 'hash2',
			'/absolute/path.pdf' => 'hash3',
		);

		foreach ( $test_cases as $file_path => $expected_hash ) {
			// Reset Patchwork for each iteration.
			replace(
				'hash_file',
				function( $algorithm, $filename ) use ( $expected_hash ) {
					return $expected_hash;
				}
			);

			$result = SHA256::create( $file_path );
			$this->assertSame( $expected_hash, $result );
		}
	}

	/**
	 * Test verify returns true when hashes match.
	 */
	public function test_verify_returns_true_when_hashes_match() {
		$file_path      = '/path/to/test/file.txt';
		$correct_hash   = 'abc123def456';
		$computed_hash  = 'abc123def456';

		// Mock hash_file to return the computed hash.
		replace(
			'hash_file',
			function( $algorithm, $filename ) use ( $computed_hash ) {
				return $computed_hash;
			}
		);

		// Mock hash_equals to return true for matching hashes.
		replace(
			'hash_equals',
			function( $known_string, $user_string ) use ( $computed_hash, $correct_hash ) {
				$this->assertSame( $computed_hash, $known_string );
				$this->assertSame( $correct_hash, $user_string );
				return true;
			}
		);

		$result = SHA256::verify( $file_path, $correct_hash );

		$this->assertTrue( $result );
	}

	/**
	 * Test verify returns false when hashes do not match.
	 */
	public function test_verify_returns_false_when_hashes_do_not_match() {
		$file_path      = '/path/to/test/file.txt';
		$wrong_hash     = 'wrong123hash456';
		$computed_hash  = 'abc123def456';

		// Mock hash_file to return the computed hash.
		replace(
			'hash_file',
			function( $algorithm, $filename ) use ( $computed_hash ) {
				return $computed_hash;
			}
		);

		// Mock hash_equals to return false for non-matching hashes.
		replace(
			'hash_equals',
			function( $known_string, $user_string ) use ( $computed_hash, $wrong_hash ) {
				$this->assertSame( $computed_hash, $known_string );
				$this->assertSame( $wrong_hash, $user_string );
				return false;
			}
		);

		$result = SHA256::verify( $file_path, $wrong_hash );

		$this->assertFalse( $result );
	}

	/**
	 * Test verify with multiple different hashes.
	 */
	public function test_verify_with_multiple_scenarios() {
		$file_path     = '/path/to/test/file.txt';
		$computed_hash = 'realfilehash123';

		$test_cases = array(
			array(
				'hash'     => 'realfilehash123',
				'expected' => true,
				'label'    => 'exact match',
			),
			array(
				'hash'     => 'wronghash456',
				'expected' => false,
				'label'    => 'different hash',
			),
			array(
				'hash'     => '',
				'expected' => false,
				'label'    => 'empty hash',
			),
		);

		foreach ( $test_cases as $test_case ) {
			// Mock hash_file to return the computed hash.
			replace(
				'hash_file',
				function( $algorithm, $filename ) use ( $computed_hash ) {
					return $computed_hash;
				}
			);

			// Mock hash_equals based on the test case.
			$expected_result = $test_case['expected'];
			replace(
				'hash_equals',
				function( $known_string, $user_string ) use ( $expected_result ) {
					return $expected_result;
				}
			);

			$result = SHA256::verify( $file_path, $test_case['hash'] );

			$this->assertSame(
				$test_case['expected'],
				$result,
				"Failed for scenario: {$test_case['label']}"
			);
		}
	}

	/**
	 * Test that verify calls create internally.
	 */
	public function test_verify_uses_create_method() {
		$file_path     = '/path/to/test/file.txt';
		$hash          = 'somehash123';
		$computed_hash = 'abc123';

		// Track that hash_file was called (which create() uses).
		$hash_file_called = false;

		replace(
			'hash_file',
			function( $algorithm, $filename ) use ( &$hash_file_called, $computed_hash ) {
				$hash_file_called = true;
				return $computed_hash;
			}
		);

		replace(
			'hash_equals',
			function( $known_string, $user_string ) {
				return false;
			}
		);

		SHA256::verify( $file_path, $hash );

		$this->assertTrue( $hash_file_called, 'verify() should call create() which uses hash_file()' );
	}
}
