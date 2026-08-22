<?php
/**
 * File sha256 hash class.
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

namespace FAToolkit\File;

/**
 * Class to hand the creation and verification of sha256 hashes.
 */
class SHA256 {

		/**
		 * Create a sha256 hash from a file.
		 *
		 * @param string $file The file to hash.
		 * @return string The sha256 hash.
		 */
	public static function create( $file ) {
		return hash_file( 'sha256', $file );
	}

		/**
		 * Verify a sha256 hash against a file.
		 *
		 * @param string $file The file to verify.
		 * @param string $hash The hash to verify against.
		 * @return bool True if the hash matches, false otherwise.
		 */
	public static function verify( $file, $hash ) {
		return hash_equals( self::create( $file ), $hash );
	}
}
