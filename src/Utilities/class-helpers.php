<?php
/**
 * General helper utilities.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Utilities;

/**
 * Class containing general helper methods.
 */
class Helpers {

	/**
	 * Check if a value is empty.
	 *
	 * Wrapper around PHP's empty() function to allow use as a callback
	 * and provide consistent behavior across the plugin.
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if the value is empty, false otherwise.
	 */
	public static function is_empty( $value ): bool {
		return empty( $value );
	}
}
