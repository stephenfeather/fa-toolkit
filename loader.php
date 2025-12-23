<?php
/**
 * Loads our src/files.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

// Exit if accessed directly.
if ( defined( 'ABSPATH' ) === false ) {
	exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
};



require_once plugin_dir_path( __FILE__ ) . 'src/Rest/class-importmediaimage.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-attachment-sha256-hash-meta-box.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-custom-admin-menu.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-product-display-vendor.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-product-display-id.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-product-category-counts.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Admin/class-admin-meta-boxes.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Media/class-media-fix-ilab-metadata.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Media/class-productthumbnailchecker.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Media/class-fetchdraftids.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Promotion/class-promotion.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Promotion/class-promotion-meta-box.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Promotion/class-promotion-posttype.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Product/class-wordcount.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Product/class-bard-meta-box.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Product/class-wc-update-global-unique-id.php';
// require_once plugin_dir_path( __FILE__ ) . 'src/Product/class-custom-product-status.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Media/class-media-fix-ilab-metadata.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-pwbulkeditorsettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-updraftplussettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-wpallimportsettings.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-fixrankmathschemas.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-gtins.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-color-test.php';

require_once plugin_dir_path( __FILE__ ) . 'src/CLI/Tools/class-exportacffield.php';

require_once plugin_dir_path( __FILE__ ) . 'src/User/class-userregistration.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Site/class-setup.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-querymonitorsettings.php';

// use FAToolkit\Tools\ExportACFField;
// require __DIR__ . '/vendor/autoload.php';

// new ExportACFField();
