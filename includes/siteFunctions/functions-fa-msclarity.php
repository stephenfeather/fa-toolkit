<?php
/**
 * Adds the MS Bing Clarity code to the header.
 *
 * @package FA-Toolkit
 * @since 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/msclarity.js';


function enqueue_msclarity_properly() {
	wp_enqueue_script( 'fa-msclarity', $src, array(), '1.0.3', true );
}

add_action( 'wp_enqueue_scripts', 'enqueue_msclarity_properly' );
