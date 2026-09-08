<?php
/**
 * Test double for WooCommerce's FeaturesUtil.
 *
 * Declared under WooCommerce's real namespace on purpose. The code under test
 * resolves the class by its fully-qualified name and guards on class_exists(),
 * so a differently-named double would never be reached — the guard would skip
 * silently and the test would pass while asserting nothing.
 *
 * @package FA-Toolkit
 */

namespace Automattic\WooCommerce\Utilities;

/**
 * Records declare_compatibility() calls instead of talking to WooCommerce.
 */
class FeaturesUtil {

	/**
	 * Every declaration made, as array( feature, plugin_file, positive ).
	 *
	 * @var array<int, array{0:string,1:string,2:bool}>
	 */
	public static $calls = array();

	/**
	 * Record a compatibility declaration.
	 *
	 * @param string $feature     Feature id.
	 * @param string $plugin_file Absolute path to the plugin bootstrap file.
	 * @param bool   $positive    Whether the plugin is compatible.
	 * @return void
	 */
	public static function declare_compatibility( $feature, $plugin_file, $positive = true ) {
		self::$calls[] = array( $feature, $plugin_file, $positive );
	}

	/**
	 * Forget every recorded call.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$calls = array();
	}
}
