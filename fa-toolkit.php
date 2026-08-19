<?php
/**
 * Plugin Name: Feather Arms Toolkit
 * Version: 1.0.8
 * Description: Collection of WordPress management tools used by Feather Arms.
 * Author: Stephen Feather
 * Author URI: http://stephenfeather.com
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Copyright: 2023 Stephen Feather
 *
 * @package FA-Toolkit
 */

// Exit if accessed directly.
if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' );// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
}

add_filter( 'woocommerce_is_purchasable', '__return_true' );
define( 'FA_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'FA_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes via Composer.
// Composer autoloader is a standard and safe pattern.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
require_once __DIR__ . '/vendor/autoload.php';

// Instantiate classes with side-effects (hooks, actions, WP-CLI commands, etc).
// These classes register their own hooks in constructors.

// Admin.
new \FAToolkit\Admin\Custom_Admin_Menu();
new \FAToolkit\Admin\Attachment_SHA256_Hash_Meta_Box();
new \FAToolkit\Admin\Product_Display_Vendor();
new \FAToolkit\Admin\Product_Display_Id();
new \FAToolkit\Admin\Product_Category_Counts();
new \FAToolkit\Admin\Admin_Meta_Boxes();

// Media.
new \FAToolkit\Media\AutoAttachUploadedMedia();

// Media (WP-CLI dependent - only load if WP-CLI is active).
if ( true === defined( 'WP_CLI' ) && true === WP_CLI ) {
	new \FAToolkit\Media\Media_Fix_Ilab_Metadata();
	new \FAToolkit\Media\ProductThumbnailChecker();
}

// Promotion.
new \FAToolkit\Promotion\Promotions();
new \FAToolkit\Promotion\Promotion_Meta_Box();
new \FAToolkit\Promotion\Promotion_PostType();

// Product.
new \FAToolkit\Product\WordCount();
new \FAToolkit\Product\Bard_Meta_Box();

// Modules.
new \FAToolkit\Modules\PWBulkEditorSettings();
new \FAToolkit\Modules\UpdraftPlusSettings();
new \FAToolkit\Modules\WPAllImportSettings();
new \FAToolkit\Modules\QueryMonitorSettings();
new \FAToolkit\Modules\WooCommerceSettings();

// Utilities (WP-CLI dependent classes only load if WP-CLI is active).
if ( true === defined( 'WP_CLI' ) && true === WP_CLI ) {
	new \FAToolkit\Utilities\FixRankMathSchemas();
	new \FAToolkit\Utilities\GTINS();
	new \FAToolkit\Utilities\Color_Test();
}
new \FAToolkit\Utilities\Debug();

// Site.
new \FAToolkit\Site\GoogleTagManager();
new \FAToolkit\Site\SetupBusinessBloomer();

// Rest.
new \FAToolkit\Rest\ImportMediaImage();

// CLI Commands (only load if WP-CLI is active).
if ( true === defined( 'WP_CLI' ) && true === WP_CLI ) {
	new \FAToolkit\CLI\Tools\ExportACFField();
	new \FAToolkit\CLI\Tools\FileToolsCommand();
	new \FAToolkit\CLI\Media\ScrapeProductMedia();
	new \FAToolkit\CLI\Media\FetchImportProductImageCommand();
	new \FAToolkit\CLI\Media\FindMediaForProductCommand();
	new \FAToolkit\CLI\Media\AttachMediaToDraftProductsCommand();
	new \FAToolkit\CLI\Media\ExportDraftProductImageSourcesCommand();
	// TODO: Refactor this class to use namespacing and autoloading.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Commands/class-scrapeproductdata.php';
}
