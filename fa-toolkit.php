<?php
/**
 * Plugin Name: Feather Arms Toolkit
 * Version: 1.0.5
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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

define( 'FA_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'FA_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

require_once FA_TOOLKIT_PATH . 'vendor/autoload.php';

// Load the plugin files.
require_once FA_TOOLKIT_PATH . 'loader.php';
require_once FA_TOOLKIT_PATH . 'includes/loader.php';

// Your plugin code goes here.
