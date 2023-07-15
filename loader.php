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

require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-pwbulkeditorsettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-updraftplussettings.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Modules/class-wpallimportsettings.php';

require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-fix-rank-math-schemas.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-populate-gtins.php';
require_once plugin_dir_path( __FILE__ ) . 'src/Utilities/class-colortest.php';
