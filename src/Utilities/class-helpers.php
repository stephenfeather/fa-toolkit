<?php
/**
 * General helper utilities.
 *
 * @package FA-Toolkit
 * @since 1.2.0
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

	/**
	 * Whether the current request is running under WP-CLI.
	 *
	 * The single expression for this check. Hand-written variants were spread
	 * across the plugin in five different forms, seven of which were wrong in
	 * the same way:
	 *
	 *     defined( 'WP_CLI' ) === false && WP_CLI === false
	 *
	 * That is a De Morgan error introduced when the original and correct
	 * `! ( defined( 'WP_CLI' ) && WP_CLI )` was rewritten to satisfy PHPCS -
	 * negating both operands requires && to become ||. As written, an
	 * undefined WP_CLI makes the left operand true, so PHP evaluates the right
	 * one and throws "Undefined constant WP_CLI": the guard fatals in exactly
	 * the case it exists to handle.
	 *
	 * Short-circuiting is the point. WP_CLI must never be read unless defined()
	 * has already confirmed it exists, so keep these operands in this order,
	 * joined by &&.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when WP-CLI is loaded and active.
	 */
	public static function is_wp_cli(): bool {
		return self::is_constant_true( 'WP_CLI' );
	}

	/**
	 * Whether a constant is defined AND truthy, without reading it if it is not.
	 *
	 * The constant() call is only reached once defined() has confirmed the name exists,
	 * so an undefined constant returns false rather than throwing. This is the
	 * property the inverted guards lost, and the one the tests pin.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name Constant name.
	 * @return bool True when the constant exists and is truthy.
	 */
	public static function is_constant_true( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}
}
