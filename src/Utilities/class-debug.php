<?php
/**
 * Debug utilities for the FA plugin.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Utilities;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
}

/**
 * Debug class for logging and debugging utilities.
 */
class Debug {
	/**
	 * Constructor - registers shutdown hook for debugging.
	 */
	public function __construct() {
		add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
	}

	/**
	 * Logs data to the error log, handling arrays and objects safely.
	 *
	 * @param mixed $log Data to be written to the log.
	 *
	 * @return void
	 */
	public static function write_log( $log ) {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		} else {
			error_log( $log ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
	public static function var_dump_database() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		var_dump( self::wpdb()->num_queries, self::wpdb()->queries );
	}

	/**
	 * Adds a custom tracer to New Relic if the extension is available.
	 *
	 * @param string $tracer_name Name of the function to be traced.
	 *
	 * @return bool True when the tracer was registered, false otherwise.
	 */
	public static function add_custom_tracer( $tracer_name ) {
		if ( extension_loaded( 'newrelic' ) ) { // Ensure PHP agent is available.
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

		$output  = 'console.info(\'' . $context . ':\');';
		$output .= 'console.log(' . wp_json_encode( $data ) . ');';
		$output  = sprintf( '<script>%s</script>', $output );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses_post( $output );
	}

	/**
	 * Shutdown handler - outputs database debug info if WP_DEBUG is enabled.
	 *
	 * @return void
	 */
	public function shutdown_handler() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			# self::var_dump_database();
		}
	}
}

// Instantiate to register hooks.
new Debug();
