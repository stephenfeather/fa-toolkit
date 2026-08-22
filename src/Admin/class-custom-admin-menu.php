<?php
/**
 * Customize the admin menu for more efficient operations.
 *
 * @package FA-Toolkit
 * @since 1.0.5
 */

namespace FAToolkit\Admin;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
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
		add_filter( 'custom_menu_order', array( $this, 'reorder_admin_menu_items' ), 999, 1 );
		add_filter( 'menu_order', array( $this, 'reorder_admin_menu_items' ), 999, 1 );

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
	 * This one callback serves BOTH filters registered in the constructor, and
	 * they have different contracts:
	 *
	 * - `custom_menu_order` is invoked as apply_filters( 'custom_menu_order', false )
	 *   and a truthy return enables custom ordering.
	 * - `menu_order` receives the ordered array of menu items and must return an
	 *   array. It only takes effect if `custom_menu_order` returned truthy.
	 *
	 * So the `false === $menu_ord` test below is NOT input validation — it is how
	 * this method identifies which of its two filters is calling it. `false` can
	 * only have arrived from `custom_menu_order`, where `true` is the right
	 * answer; anything else is `menu_order`'s array, where the order array is.
	 *
	 * Edge case, correct but by luck rather than design: if another plugin
	 * returns true for `custom_menu_order` at a priority below 999, $menu_ord
	 * arrives as true, the strict check fails, and this returns the ORDER ARRAY
	 * to `custom_menu_order`. A non-empty array is truthy, so ordering is still
	 * enabled and behaviour is unchanged.
	 *
	 * @param array|bool $menu_ord Ordered array of menu items from `menu_order`,
	 *                             or false from `custom_menu_order`.
	 * @return array|bool True for `custom_menu_order`, the order array for `menu_order`.
	 */
	public function reorder_admin_menu_items( $menu_ord ) {
		// Called via `custom_menu_order`: enable custom ordering.
		if ( false === $menu_ord ) {
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
			'edit.php?post_type=promotion', // Promotions.
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
