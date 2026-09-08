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

// Inert constants, deliberately above the guard: they define paths and register
// nothing, so they leave no observable behaviour behind if the bootstrap bails.
// The guard below is about SIDE EFFECTS, not about everything preceding it.
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
// No side effect may sit above the guard - a plugin that failed to load its
// classes must not half-apply. (The two define() calls above are exempt because
// they register nothing and leave no observable behaviour behind.)

// Admin.
//
// Custom_Admin_Menu stays FIRST below the guard. The guard tests that exact
// class, and its comment claims the tested class is the first one instantiated
// — put anything ahead of it and that claim quietly stops being true.
new \FAToolkit\Admin\Custom_Admin_Menu();
new \FAToolkit\Admin\Attachment_SHA256_Hash_Meta_Box();
new \FAToolkit\Admin\Product_Display_Vendor();
new \FAToolkit\Admin\Product_Display_Id();
new \FAToolkit\Admin\Product_Category_Counts();
new \FAToolkit\Admin\Admin_Meta_Boxes();

// WooCommerce feature compatibility.
//
// Sits BELOW the guard, with the other side effects, rather than above it with
// the constants. Registering a hook is a side effect, and the invariant above
// admits no exceptions. Nothing is lost by it: if the guard bails, the plugin
// registers nothing and touches no orders, so having made no claim is correct
// rather than merely acceptable.
new \FAToolkit\Compat\HposCompatibility();

// Media.
new \FAToolkit\Media\AutoAttachUploadedMedia();

// Serves URLs for attachments whose image lives on s3 rather than on disk.
// Registers filters only; it is inert for every ordinary local attachment.
new \FAToolkit\Media\RemoteAttachmentUrls();

// Media (WP-CLI dependent - only load if WP-CLI is active).
if ( true === \FAToolkit\Utilities\Helpers::is_wp_cli() ) {
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
if ( true === \FAToolkit\Utilities\Helpers::is_wp_cli() ) {
	new \FAToolkit\Utilities\FixRankMathSchemas();
	new \FAToolkit\Utilities\GTINS();
	// Color_Test registers its own command at file scope
	// (src/Utilities/class-color-test.php:68), outside the class body, and the
	// class has no constructor — so including the file IS the registration and
	// this `new` constructs nothing observable.
	//
	// Do not delete it as dead code: under the classmap autoloader the reference
	// is what triggers the include, and nothing else in the tree references this
	// class. Removing this line removes the `color-test` command. See issue #18.
	new \FAToolkit\Utilities\Color_Test();
}
new \FAToolkit\Utilities\Debug();

// Site.
new \FAToolkit\Site\GoogleTagManager();
new \FAToolkit\Site\SetupBusinessBloomer();

// Rest.
new \FAToolkit\Rest\ImportMediaImage();

// CLI Commands (only load if WP-CLI is active).
if ( true === \FAToolkit\Utilities\Helpers::is_wp_cli() ) {
	new \FAToolkit\CLI\Tools\ExportACFField();
	new \FAToolkit\CLI\Tools\FileToolsCommand();
	new \FAToolkit\CLI\Media\ScrapeProductMedia();
	new \FAToolkit\CLI\Media\FetchImportProductImageCommand();
	new \FAToolkit\CLI\Media\FindMediaForProductCommand();
	new \FAToolkit\CLI\Media\AttachMediaToDraftProductsCommand();
	new \FAToolkit\CLI\Media\ExportDraftProductImageSourcesCommand();
	new \FAToolkit\CLI\Media\CreateRemoteAttachmentsCommand();
	// Scrape_Product_Data_Command registers its own command at file scope
	// (src/CLI/Commands/class-scrapeproductdata.php:155), outside the class
	// body — so including the file IS the registration, and this class is not
	// instantiated like the commands above.
	//
	// Referencing the class name triggers the Composer classmap autoloader,
	// which includes the file and performs that registration at exactly this
	// point in the bootstrap, as the previous require_once did. Do not remove
	// this line as an unused expression: it is the registration.
	//
	// class_exists() rather than `new`: the class declaration is itself
	// conditional on WP_CLI_Command existing, so class_exists() returns false
	// where `new` would fatal.
	class_exists( \FAToolkit\CLI\Commands\Scrape_Product_Data_Command::class );
}
