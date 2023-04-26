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
wp_enqueue_script( 'fa-passive-listeners', $src, array(), '1.0.3', false );
