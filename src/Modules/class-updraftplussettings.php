<?php
/**
 * Filter out .git directories from UpdraftPlus backups
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

namespace FAToolkit\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UpdraftPlus Settings.
 */
class UpdraftPlusSettings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'updraftplus_exclude_directory', array( $this, 'my_updraftplus_exclude_directory' ), 10, 2 );
	}

	/**
	 * Exclude .git directories from UpdraftPlus backups.
	 *
	 * @param bool   $filter Filter.
	 * @param string $dir    Directory.
	 */
	public function my_updraftplus_exclude_directory( $filter, $dir ) {
		$excluded_directories = array( '.git', '.vscode' ); // Array of directories to exclude.

		return ( in_array( basename( $dir ), $excluded_directories, true ) ) ? true : $filter;
	}
}

new UpdraftPlusSettings();
