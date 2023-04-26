<?php
/**
 * Load an email obfuscation script
 *
 * @package FA-Toolkit
 * @since 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$src = '/wp-content/plugins/fa-toolkit/assets/scripts/email-decode-1.0.2.min.js';
wp_enqueue_script( 'cloudflare-email-decode', $src, array(), '1.0.2', true );
