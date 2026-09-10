<?php
/**
 * Tests for Admin_Meta_Boxes.
 *
 * @package FAToolkit\Tests\Admin
 */

namespace FAToolkit\Tests\Admin;

use Brain\Monkey\Functions;
use FAToolkit\Admin\Admin_Meta_Boxes;
use FAToolkit\Promotion\Promotion_Meta_Box;
use FAToolkit\Tests\TestCase;

/**
 * Admin_Meta_Boxes rearranges the promotion edit screen's meta boxes.
 *
 * Issue #24: the Admin module's 0% coverage was effort, not a structural
 * blocker; these tests are that effort for this class.
 */
class Admin_Meta_BoxesTest extends TestCase {

	/**
	 * The constructor hooks the four rearrangement steps onto add_meta_boxes
	 * and the promotion save handler onto save_post.
	 */
	public function test_constructor_registers_hooks() {
		$boxes = new Admin_Meta_Boxes();

		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $boxes, 'remove_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $boxes, 'rename_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $boxes, 'add_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $boxes, 'sort_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'save_post', array( Promotion_Meta_Box::class, 'save_custom_promotion_fields' ) ) );
	}

	/**
	 * Five stock boxes are removed from the promotion screen.
	 */
	public function test_remove_meta_boxes_removes_stock_boxes_from_promotion() {
		$removed = $this->record( 'remove_meta_box' );

		( new Admin_Meta_Boxes() )->remove_meta_boxes();

		$this->assertSame(
			array(
				array( 'woothemes-settings', 'promotion', 'normal' ),
				array( 'commentstatusdiv', 'promotion', 'normal' ),
				array( 'slugdiv', 'promotion', 'normal' ),
				array( 'formatdiv', 'promotion', 'normal' ),
				array( 'postdivrich', 'promotion', 'normal' ),
			),
			$removed->calls
		);
	}

	/**
	 * The excerpt box is re-added under a promotion-specific title using the
	 * core excerpt renderer.
	 */
	public function test_rename_meta_boxes_replaces_excerpt_box_title() {
		Functions\when( '__' )->returnArg();
		$removed = $this->record( 'remove_meta_box' );
		$added   = $this->record( 'add_meta_box' );

		( new Admin_Meta_Boxes() )->rename_meta_boxes();

		$this->assertSame( array( array( 'postexcerpt', 'promotion', 'normal' ) ), $removed->calls );
		$this->assertSame(
			array( array( 'postexcerpt', 'Promotion short description', 'post_excerpt_meta_box', 'promotion', 'normal' ) ),
			$added->calls
		);
	}

	/**
	 * The promotion details box renders through Promotion_Meta_Box::output.
	 */
	public function test_add_meta_boxes_adds_promotion_details_box() {
		Functions\when( '__' )->returnArg();
		$added = $this->record( 'add_meta_box' );

		( new Admin_Meta_Boxes() )->add_meta_boxes();

		$this->assertSame(
			array(
				array(
					'promotion_data',
					'Promotion Details',
					array( Promotion_Meta_Box::class, 'output' ),
					'promotion',
					'normal',
					'high',
				),
			),
			$added->calls
		);
	}

	/**
	 * A user who already has a saved box order keeps it.
	 */
	public function test_sort_meta_boxes_keeps_an_existing_user_order() {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$read = $this->record( 'get_user_meta', array( 'normal' => 'custom' ) );
		$written = $this->record( 'update_user_meta' );

		( new Admin_Meta_Boxes() )->sort_meta_boxes();

		$this->assertSame( array( array( 7, 'meta-box-order_promotion', true ) ), $read->calls );
		$this->assertSame( array(), $written->calls, 'An existing order must not be overwritten.' );
	}

	/**
	 * A user with no saved order gets the promotion default written once.
	 */
	public function test_sort_meta_boxes_writes_default_order_when_none_saved() {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$written = $this->record( 'update_user_meta' );

		( new Admin_Meta_Boxes() )->sort_meta_boxes();

		$this->assertSame(
			array(
				array(
					7,
					'meta-box-order_promotion',
					array(
						'side'     => '',
						'normal'   => 'titlediv,postexcerpt,promotion_data, postexcerpt, postdivrich',
						'advanced' => '',
					),
				),
			),
			$written->calls
		);
	}

	/**
	 * Stub a WordPress function to record every call's arguments.
	 *
	 * Assertions on the recorded calls are explicit, which is both stronger
	 * than an expectation verified at teardown and visible to static analysis.
	 *
	 * @param string $function_name Function to stub.
	 * @param mixed  $return        Value the stub returns.
	 * @return object Recorder with a public `calls` array.
	 */
	private function record( $function_name, $return = null ) {
		$recorder        = new \stdClass();
		$recorder->calls = array();
		Functions\when( $function_name )->alias(
			function ( ...$args ) use ( $recorder, $return ) {
				$recorder->calls[] = $args;
				return $return;
			}
		);
		return $recorder;
	}
}
