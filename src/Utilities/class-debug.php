<?php
/**
 * Debug utilities for the FA plugin.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Utilities;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Debug class for logging and debugging utilities.
 */
class Debug {
	/**
	 * Logger callable for dependency injection.
	 *
	 * @var callable
	 */
	private $logger;

	/**
	 * Constructor - registers shutdown hook for debugging.
	 *
	 * @param callable|null $logger Optional logger callable. Defaults to error_log.
	 * @param bool          $register_hooks Whether to register WordPress hooks. Defaults to true.
	 */
	public function __construct( $logger = null, $register_hooks = true ) {
		$this->logger = $logger ?? 'error_log';

		if ( true === $register_hooks ) {
			add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
		}
	}

	/**
	 * Logs data to the error log, handling arrays and objects safely.
	 *
	 * @param mixed $log Data to be written to the log.
	 *
	 * @return void
	 */
	public function write_log( $log ) {
		if ( true === is_array( $log ) || true === is_object( $log ) ) {
			( $this->logger )( wp_json_encode( $log ) );
		} else {
			( $this->logger )( $log );
		}
	}

	/**
	 * Helper wrapper that exposes the global $wpdb instance.
	 *
	 * @return \wpdb WordPress database object.
	 */
	public static function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Outputs the total number of queries and their details for debugging purposes.
	 *
	 * @return void
	 */
	public function var_dump_database() {
		$this->write_log(
			array(
				'num_queries' => self::wpdb()->num_queries,
				'queries'     => self::wpdb()->queries,
			)
		);
	}

	/**
	 * Adds a custom tracer to New Relic if the extension is available.
	 *
	 * @param string $tracer_name Name of the function to be traced.
	 *
	 * @return bool True when the tracer was registered, false otherwise.
	 */
	public static function add_custom_tracer( $tracer_name ) {
		if ( true === extension_loaded( 'newrelic' ) ) { // Ensure PHP agent is available.
			newrelic_add_custom_tracer( $tracer_name ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
			return true;
		}
		return false;
	}

	/**
	 * Simple helper to debug to the console
	 *
	 * @param mixed  $data     Data to be logged (object, array, string, etc).
	 * @param string $context  Optional description for the log entry.
	 *
	 * @return void
	 */
	public static function debug_to_console( $data, $context = 'Debug in Console' ) {
		// Buffering to solve problems frameworks, like header() in this and not a solid return.
		ob_start();
		$context = esc_js( $context );
		$json    = wp_json_encode( $data );

		$output = sprintf(
			"<script>console.info('%s:'); console.log(%s);</script>",
			$context,
			$json
		);

		// Allow script tags with no attributes
		$allowed_tags = array(
			'script' => array(),
		);

		echo wp_kses( $output, $allowed_tags );
	}

	/**
	 * Shutdown handler - outputs database debug info if WP_DEBUG is enabled.
	 *
	 * @return void
	 */
	public function shutdown_handler() {
		if ( true === defined( 'WP_DEBUG' ) && true === WP_DEBUG && true === current_user_can( 'manage_options' ) ) {
			// Database debug output intentionally disabled.
		}
	}
}
