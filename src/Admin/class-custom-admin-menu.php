<?php
/**
 * Customize the admin menu for more efficient operations.
 *
 * @package FA-Toolkit
 * @since 1.0.5
 */

namespace FAToolkit\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Admin Menu.
 */
class Custom_Admin_Menu {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Remove menu items.
		add_action( 'admin_init', array( $this, 'remove_admin_menu_items' ) );

		// Reorder menu items.
		add_filter( 'custom_menu_order', array( $this, 'reorder_admin_menu_items' ) );
		add_filter( 'menu_order', array( $this, 'reorder_admin_menu_items' ), 99, 1 );

		// Add menu items.
		add_action( 'admin_menu', array( $this, 'add_admin_menu_items' ) );
	}

	/**
	 * Remove menu items.
	 */
	public function remove_admin_menu_items() {
		// Remove menu items.
	}

	/**
	 * Reorder menu items.
	 *
	 * @param array $menu_ord The array of menu items.
	 * @return array The array of menu items.
	 */
	public function reorder_admin_menu_items( $menu_ord ) {
		if ( ! $menu_ord ) {
			return true;
		}
		// Set our array of menu items in the order we want them to appear.
		$admin_menu_order = array(
			'index.php', // Dashboard.
			'edit.php', // Posts.
			'edit.php?post_type=page', // Pages.
			'edit.php?post_type=product', // Products.
			'upload.php', // Media.
			'edit.php?post_type=product-feed', // REX Product Feeds.
			'edit.php?post_type=blocks', // Blocks.
			'separator1', // --Space--
			'wc-admin', // WooCommerce.
			'options-general.php', // Settings.
			'separator2', // --Space--
		);

		// return the array of menu items in the order we want them to appear.
		return $admin_menu_order;
	}

	/**
	 * Add menu items.
	 */
	public function add_admin_menu_items() {
		// Add menu items.
	}

}

new Custom_Admin_Menu();
