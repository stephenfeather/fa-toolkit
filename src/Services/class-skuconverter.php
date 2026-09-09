<?php
/**
 * SKU conversion utilities.
 *
 * @package FA-Toolkit
 * @since 1.2.0
 */

namespace FAToolkit\Services;

/**
 * Class for converting product SKUs to media-friendly formats.
 */
class SkuConverter {

	/**
	 * Convert a SKU to a basename suitable for media matching.
	 *
	 * Strips the 'FA-' prefix if present, returning just the numeric part.
	 *
	 * @param string $sku           The product SKU.
	 * @param string $basename_suffix Optional suffix to append.
	 * @return string The normalized basename.
	 */
	public static function to_basename( string $sku, string $basename_suffix = '' ): string {
		$prefix = substr( $sku, 0, 3 );
		if ( 'FA-' === $prefix ) {
			$numeric_part = substr( $sku, 3 );
			return $numeric_part . $basename_suffix;
		}
		return $sku . $basename_suffix;
	}

	/**
	 * Convert a SKU to an expected filename pattern.
	 *
	 * Strips the 'FA-' prefix if present and appends extension.
	 *
	 * @param string $sku             The product SKU.
	 * @param string $extension       File extension without dot (default: 'jpg').
	 * @param string $basename_suffix Optional suffix to append before extension.
	 * @return string The formatted filename.
	 */
	public static function to_filename( string $sku, string $extension = 'jpg', string $basename_suffix = '' ): string {
		$prefix = substr( $sku, 0, 3 );
		if ( 'FA-' === $prefix ) {
			$numeric_part = substr( $sku, 3 );
			return $numeric_part . $basename_suffix . '.' . $extension;
		}
		return $sku . $basename_suffix . '.' . $extension;
	}
}
