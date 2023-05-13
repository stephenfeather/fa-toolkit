<?php
/**
 * Enqueue Google Tag Manager.
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/tagmanager.js';



function enqueue_tagmanager_properly() {
	wp_enqueue_script( 'google-tagmanager', $src, array(), '1.0.2', true );
}

add_action( 'wp_enqueue_scripts', 'enqueue_tagmanager_properly' );