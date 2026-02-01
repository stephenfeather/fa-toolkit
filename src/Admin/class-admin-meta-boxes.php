<?php
/**
 * FA-Toolkit Meta Boxes
 *
 * The FA-Toolkit Meta Boxes class adds meta boxes to the admin.
 *
 * @package FA-Toolkit
 * @since 1.0.7
 */

namespace FAToolkit\Admin;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

use FAToolkit\Promotion\Promotion_Meta_Box as Promotion_Meta_Box;
use FAToolkit\Utilities\Helpers;

/**
 * FA-Toolkit Meta Boxes class.
 */
class Admin_Meta_Boxes {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'rename_meta_boxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'add_meta_boxes', array( $this, 'sort_meta_boxes' ) );
		add_action( 'save_post', array( Promotion_Meta_Box::class, 'save_custom_promotion_fields' ), 10, 2 );
	}

	/**
	 * Remove meta boxes.
	 */
	public function remove_meta_boxes() {

		// Promotions.
		remove_meta_box( 'woothemes-settings', 'promotion', 'normal' );
		remove_meta_box( 'commentstatusdiv', 'promotion', 'normal' );
		remove_meta_box( 'slugdiv', 'promotion', 'normal' );
		remove_meta_box( 'formatdiv', 'promotion', 'normal' );
		remove_meta_box( 'postdivrich', 'promotion', 'normal' );
	}

	/**
	 * Rename meta boxes.
	 */
	public function rename_meta_boxes() {

		// Promotions.
		remove_meta_box( 'postexcerpt', 'promotion', 'normal' );
		add_meta_box( 'postexcerpt', __( 'Promotion short description', 'fa-toolkit' ), 'post_excerpt_meta_box', 'promotion', 'normal' );
	}

	/**
	 * Add meta boxes.
	 */
	public function add_meta_boxes() {

		// Promotions.
		add_meta_box( 'promotion_data', __( 'Promotion Details', 'fa-toolkit' ), array( Promotion_Meta_Box::class, 'output' ), 'promotion', 'normal', 'high' );
	}

	/**
	 * Sort meta boxes.
	 */
	public function sort_meta_boxes() {

		$current_value = get_user_meta( get_current_user_id(), 'meta-box-order_promotion', true );
		if ( false === Helpers::is_empty( $current_value ) ) {
			return;
		}

		update_user_meta(
			get_current_user_id(),
			'meta-box-order_promotion',
			array(
				'side'     => '',
				'normal'   => 'titlediv,postexcerpt,promotion_data, postexcerpt, postdivrich',
				'advanced' => '',
			)
		);
	}
}
