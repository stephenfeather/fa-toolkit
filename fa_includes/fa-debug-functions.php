<?php
/**
 * Debug functions for the FA plugin.
 *
 * @package FA\Includes
 */

if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
}

if ( function_exists( 'write_log' ) === false ) {
	/**
	 * Logs data to the error log, handling arrays and objects safely.
	 *
	 * @param mixed $log Data to be written to the log.
	 *
	 * @return void
	 */
	function write_log( $log ) {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}
}

/**
 * Helper wrapper that exposes the global $wpdb instance.
 *
 * @return wpdb WordPress database object.
 */
function wpdb() {
	global $wpdb;
	return $wpdb;
}

/**
 * Outputs the total number of queries and their details for debugging purposes.
 *
 * @return void
 */
function var_dump_database() {
	var_dump( wpdb()->num_queries, wpdb()->queries );
}

/**
 * Adds a custom tracer to New Relic if the extension is available.
 *
 * @param string $tracer_name Name of the function to be traced.
 *
 * @return bool True when the tracer was registered, false otherwise.
 */
function add_custom_tracer( $tracer_name ) {
	if ( extension_loaded( 'newrelic' ) ) { // Ensure PHP agent is available.
		newrelic_add_custom_tracer( $tracer_name );
		return true;
	}
	return false;
}

add_action(
	'shutdown',
	function() {
		if ( WP_DEBUG && current_user_can( 'manage_options' ) ) {
			var_dump_database();
		}
	}
);

/**
 * Simple helper to debug to the console
 *
 * @param mixed  $data     Data to be logged (object, array, string, etc).
 * @param string $context  Optional description for the log entry.
 *
 * @return void
 */
function debug_to_console( $data, $context = 'Debug in Console' ) {

	// Buffering to solve problems frameworks, like header() in this and not a solid return.
	ob_start();

	$output  = 'console.info(\'' . $context . ':\');';
	$output .= 'console.log(' . json_encode( $data ) . ');';
	$output  = sprintf( '<script>%s</script>', $output );

	printf( '%s', esc_js( $output ) );
}
