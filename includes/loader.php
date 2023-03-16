<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} 

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// Only load our CLI utilities if in the CLI.
	require_once plugin_dir_path( __FILE__ ) . 'product-thumbnail-check.php';
	require_once plugin_dir_path( __FILE__ ) . 'attach-media-to-draft-products.php';
	require_once plugin_dir_path( __FILE__ ) . 'export-draft-product-image-sources.php';
	require_once plugin_dir_path( __FILE__ ) . 'fetch-import-product-image.php';
	require_once plugin_dir_path( __FILE__ ) . 'find-media-for-product.php';
	require_once plugin_dir_path( __FILE__ ) . 'tools.php';
}
