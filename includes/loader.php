<?php
/**
 * Loads our includes.
 *
 * @package FA-Toolkit
 * @since 1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

// Load Site Functions.

// Only load our CLI utilities if in the CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'cli/class-productthumbnailchecker.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/attach-media-to-draft-products.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/export-draft-product-image-sources.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/fetch-import-product-image.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/find-media-for-product.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/class-scrapeproductmedia.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/tools.php';
	require_once plugin_dir_path( __FILE__ ) . 'cli/class-scrape_product_data_command.php';
}

// Load our additional includes.
