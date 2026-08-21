<?php
/**
 * Tests for Custom_Admin_Menu.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Admin;

use FAToolkit\Admin\Custom_Admin_Menu;
use FAToolkit\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the dual-filter contract of reorder_admin_menu_items().
 *
 * One callback is registered on both `custom_menu_order` and `menu_order`,
 * which have different contracts. That is only discoverable by reading the two
 * registrations together, so it is worth a test.
 */
#[CoversClass( Custom_Admin_Menu::class )]
class Custom_Admin_MenuTest extends TestCase {

	/**
	 * The `custom_menu_order` arm: WordPress passes false, expects truthy.
	 *
	 * @return void
	 */
	public function test_returns_true_when_passed_false() {
		$menu = new Custom_Admin_Menu();

		$this->assertTrue( $menu->reorder_admin_menu_items( false ) );
	}

	/**
	 * The `menu_order` arm: WordPress passes the array, expects an array back.
	 *
	 * @return void
	 */
	public function test_returns_the_order_array_when_passed_an_array() {
		$menu = new Custom_Admin_Menu();

		$result = $menu->reorder_admin_menu_items( array( 'edit.php', 'index.php' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'index.php', $result[0], 'Dashboard must sort first.' );
		$this->assertContains( 'edit.php?post_type=product', $result );
		$this->assertContains( 'wc-admin', $result );
		$this->assertCount( 12, $result );
	}

	/**
	 * A prior filter having already returned true must not break the contract.
	 *
	 * If another plugin enables custom ordering below priority 999, $menu_ord
	 * arrives as true rather than false, the strict check fails, and the order
	 * array is returned to `custom_menu_order`. Arrays are truthy, so ordering
	 * is still enabled. Correct, but by luck rather than design — pinned here so
	 * a future change to the branch does not silently break it.
	 *
	 * @return void
	 */
	public function test_returns_truthy_when_a_prior_filter_returned_true() {
		$menu = new Custom_Admin_Menu();

		$result = $menu->reorder_admin_menu_items( true );

		$this->assertNotFalse( $result );
		$this->assertIsArray( $result );
	}
}
