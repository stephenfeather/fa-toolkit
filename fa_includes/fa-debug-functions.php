<?php
if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
}

if ( function_exists( 'write_log' ) === false ) {
	function write_log( $log ) {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}
}

function wpdb() {
	global $wpdb;
	return $wpdb;
}

function var_dump_database() {
	var_dump( wpdb()->num_queries, wpdb()->queries );
}

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
 * @param data object, array, string             $data
 * @param $context string  Optional a description.
 *
 * @return string
 */
function debug_to_console( $data, $context = 'Debug in Console' ) {

	// Buffering to solve problems frameworks, like header() in this and not a solid return.
	ob_start();

	$output  = 'console.info(\'' . $context . ':\');';
	$output .= 'console.log(' . json_encode( $data ) . ');';
	$output  = sprintf( '<script>%s</script>', $output );

	printf( '%s', esc_js( $output ) );
}
