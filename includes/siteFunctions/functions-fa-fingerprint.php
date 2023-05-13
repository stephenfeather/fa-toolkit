<?php
/**
 * Load FingerprintJS
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/** Send php values into the dom space for use by javascript. */
function pass_fingerprint_variables() {

	echo '<script>';
	echo 'var sessionid = ' . wp_json_encode( session_id() ) . ';';
	echo 'var userid = ' . wp_json_encode( get_current_user_id() ) . ';';
	echo '</script>';
}

add_action( 'wp_head', 'pass_fingerprint_variables', 2 );

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/fingerprintjs.js';

function enqueue_fingerprintjs_properly() {
	wp_enqueue_script( 'fa-fingerprintjs', $src, array(), '1.0.5', false );
}

add_action( 'wp_enqueue_scripts', 'enqueue_fingerprintjs_properly' );
