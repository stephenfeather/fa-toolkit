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

define( 'FA_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'FA_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes via Composer.
// Composer autoloader is a standard and safe pattern.
//
// Present only for a standalone checkout, where this plugin owns its vendor/.
// When installed as a Composer dependency the file does not exist: Composer
// merges this package's autoload config into the consuming project's root
// autoloader instead, so the classes below are already resolvable.
if ( true === file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/vendor/autoload.php';
}

// Confirm the classes are actually reachable before instantiating any of them.
//
// Deliberately tests for a CLASS rather than for the autoloader FILE. Under a
// Composer install the file above is legitimately absent, so a file check would
// bail out on a correct install and register nothing at all - silently. The
// class tested here is the first one instantiated below, so this check fails
// exactly when that instantiation would fatal, and never otherwise.
if ( false === class_exists( \FAToolkit\Admin\Custom_Admin_Menu::class ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					'Feather Arms Toolkit: classes could not be autoloaded. '
					. 'For a standalone checkout, run "composer install" in the plugin directory. '
					. 'When installed as a Composer dependency, run "composer install" in the project root.'
				)
			);
		}
	);
	return;
}

// Instantiate classes with side-effects (hooks, actions, WP-CLI commands, etc).
// These classes register their own hooks in constructors.
//
// Everything below this point is a side effect, and must stay below the guard:
// a plugin that failed to load its classes must not half-apply. This filter in
// particular forces every product purchasable store-wide, bypassing price and
// stock checks, so leaving it active in a broken state would be worse than not
// loading at all.
add_filter( 'woocommerce_is_purchasable', '__return_true' );

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
	new \FAToolkit\CLI\Media\ScrapeProductMedia();
	new \FAToolkit\CLI\Media\FetchImportProductImageCommand();
	// TODO: Refactor these classes to use namespacing and autoloading.
	// The remaining CLI files are procedural and register commands globally.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Media/class-attachmediatodraftproducts.php';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Media/class-exportdraftproductimagesources.php';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Media/class-findmediaforproduct.php';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Commands/class-scrapeproductdata.php';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_require_once
	require_once __DIR__ . '/src/CLI/Tools/class-tools.php';
}
