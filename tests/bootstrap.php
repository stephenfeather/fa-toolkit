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

// Define ABSPATH to prevent WordPress file guards from exiting.
//
// This points at a real fixture directory rather than a made-up path because
// some code under test does more than check `defined( 'ABSPATH' )` — it does
// `require_once ABSPATH . 'wp-admin/includes/image.php'`. A fictional path makes
// that require fatal and the method untestable. See tests/fixtures/wp-root/.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/wp-root/' );
}

// Initialize Brain Monkey.
// Brain Monkey provides utilities for mocking WordPress functions and hooks.
// It uses Patchwork to intercept function calls and Mockery for expectations.
\Brain\Monkey\setUp();

// No bootstrap-scope Functions\when() stubs live here, and none should be added.
//
// They used to, with the rationale "many classes auto-instantiate at the bottom
// of their files". That rationale is gone: issue #18 removed every file-scope
// `new` from src/, so nothing runs at class-load time any more.
//
// Keeping them was actively harmful. A when() called out here binds the stub to
// the bootstrap-era Brain Monkey container, but the function it defines outlives
// every later setUp()/tearDown() cycle. The name is then permanently poisoned:
// calls to it still succeed and still return the bootstrap stub value, while
// Brain Monkey records nothing — so a later Functions\expect() on that same name
// reports "called 0 times" even though the code under test called it. It fails
// by test order, which is how it stayed hidden.
//
// Declare the stubs a test needs inside that test.

// Create a mock WP_Query class for testing WordPress queries.
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		private $posts       = array();
		private $post_index  = 0;
		private $post_count  = 0;
		public $query_args   = array();

		public function __construct( $args = array() ) {
			// Store args for potential verification.
			$this->query_args = $args;
		}

		public function have_posts() {
			return $this->post_index < $this->post_count;
		}

		public function the_post() {
			if ( $this->have_posts() ) {
				$this->post_index++;
			}
		}

		public function set_posts( $posts ) {
			$this->posts      = $posts;
			$this->post_count = count( $posts );
			$this->post_index = 0;
		}
	}
}

// Define WP_CLI constant for CLI command testing.
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

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

		public static function debug( $message ) {
			self::$calls[] = array( 'method' => 'debug', 'args' => func_get_args() );
		}

		public static function warning( $message ) {
			self::$calls[] = array( 'method' => 'warning', 'args' => func_get_args() );
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

// Load the FeaturesUtil test double.
//
// A class in its own file, not a Functions\when() stub — the note above concerns
// function stubs bound to the bootstrap-era Brain Monkey container, which does
// not apply to a plain class declaration.
if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/fixtures/class-featuresutil-stub.php';
}

// Create a mock WP_Error class for testing WordPress errors.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				return $this->message;
			}
			return '';
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				return $this->data;
			}
			return null;
		}
	}
}

// Create a mock WP_REST_Request class for testing REST API endpoints.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();

		public function __construct( $params = array() ) {
			$this->params = $params;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}
