<?php
/**
 * Loads our includes.
 *
 * @package FA-Toolkit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

// Only load our CLI utilities if in the CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	require_once plugin_dir_path( __FILE__ ) . 'product-thumbnail-check.php';
	require_once plugin_dir_path( __FILE__ ) . 'attach-media-to-draft-products.php';
	require_once plugin_dir_path( __FILE__ ) . 'export-draft-product-image-sources.php';
	require_once plugin_dir_path( __FILE__ ) . 'fetch-import-product-image.php';
	require_once plugin_dir_path( __FILE__ ) . 'find-media-for-product.php';
	require_once plugin_dir_path( __FILE__ ) . 'tools.php';
}

// Load our additional includes.
