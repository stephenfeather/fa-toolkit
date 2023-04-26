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

function pass_fingerprint_variables() {

	echo '<script>';
	echo 'var sessionid = ' . wp_json_encode( session_id() ) . ';';
	echo 'var userid = ' . wp_json_encode( get_current_user_id() ) . ';';
	echo '</script>';
}

add_action( 'wp_head', 'pass_fingerprint_variables', 2 );

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/fingerprintjs.js';
wp_enqueue_script( 'fa-fingerprintjs', $src, array(), '1.0.5', false );
