<?php
/**
 * Load an email obfuscation script
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/passive-listeners.js';


function enqueue_passive_listeners_properly() {
	wp_enqueue_script( 'fa-passive-listeners', $src, array(), '1.0.3', false );
}

add_action( 'wp_enqueue_scripts', 'enqueue_passive_listeners_properly' );
