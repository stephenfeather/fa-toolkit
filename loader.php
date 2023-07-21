<?php
/**
 * Loads our src/files.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

require_once plugin_dir_path( __FILE__ ) . 'src/Rest/class-importmediaimage.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-attachment-sha256-meta-box.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-custom-admin-menu.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-product-display-vendor.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-product-category-counts.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Product/class-wordcount.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-pwbulkeditorsettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-updraftplussettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-wpallimportsettings.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-fixrankmathschemas.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-gtins.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-color-test.php';
