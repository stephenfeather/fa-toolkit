<?php
/**
 * PHPUnit bootstrap file for FA-Toolkit test suite.
 *
 * This file initializes the testing environment:
 * - Loads Composer autoloader
 * - Initializes Brain Monkey for WordPress function mocking
 * - Configures Mockery
 *
 * @package FAToolkit
 */

// Load Composer autoloader.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialize Brain Monkey.
// Brain Monkey provides utilities for mocking WordPress functions and hooks.
// It uses Patchwork to intercept function calls and Mockery for expectations.

// Create a mock WP_CLI class for testing WP-CLI commands.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static $calls = array();

		public static function add_command( $name, $callable ) {
			self::$calls[] = array( 'method' => 'add_command', 'args' => func_get_args() );
		}

		public static function colorize( $string ) {
			self::$calls[] = array( 'method' => 'colorize', 'args' => func_get_args() );
			return $string;
		}

		public static function line( $message ) {
			self::$calls[] = array( 'method' => 'line', 'args' => func_get_args() );
		}

		public static function success( $message ) {
			self::$calls[] = array( 'method' => 'success', 'args' => func_get_args() );
		}

		public static function error( $message, $exit = true ) {
			self::$calls[] = array( 'method' => 'error', 'args' => func_get_args() );
			if ( $exit ) {
				throw new \Exception( $message );
			}
		}

		public static function log( $message ) {
			self::$calls[] = array( 'method' => 'log', 'args' => func_get_args() );
		}

		public static function reset_calls() {
			self::$calls = array();
		}

		public static function get_calls( $method = null ) {
			if ( $method === null ) {
				return self::$calls;
			}
			// Use array_values to re-index after filtering.
			return array_values(
				array_filter(
					self::$calls,
					function ( $call ) use ( $method ) {
						return $call['method'] === $method;
					}
				)
			);
		}
	}
}
