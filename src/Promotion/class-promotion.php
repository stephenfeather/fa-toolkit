<?php
/**
 * FA Promotions
 *
 * The FA Promotions class gets promotion data from storage.
 *
 * @package FA-Toolkit
 * @since 1.0.7
 */

namespace FAToolkit\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use FAToolkit\Promotion\Promotion_Meta_Box;
use FAToolkit\Promotion\Promotion_PostType;

/**
 * FA Promotions class.
 */
class Promotions {

	/**
	 * Data array.
	 *
	 * @var array
	 */
	protected $data = array(
		'title'         => '', // The promotion title.
		'excerpt'       => '', // The promotion excerpt.
		'description'   => '', // The promotion description.
		'date_created'  => null, // The date the promotion was created.
		'date_modified' => null, // The date the promotion was last modified.
        'date_begins'   => null, // The date the promotion begins.
		'date_expires'  => null, // The date the promotion expires.
		'url'           => null, // The URL to the promotion information/redemption page.
		'product_tags'  => array(), // shares the same taxonomy as products.
		'brand'         => null, // Perfect Brand.
	);

	/**
	 * Constructor.
	 *
	 * @param string $data The data to be stored.
	 */
	public function __construct( $data = '' ) {

	}

    /**
     * 
     */

	/** Getters. */

	/**
	 * Get date created.
	 *
	 * @param string $context The context.
	 * @return string $date_created The date created.
	 */
	public function get_date_created( $context = 'view' ) {
		return $this->data['date_created'];
	}

	/**
	 * Get date modified.
	 *
	 * @param string $context The context.
	 * @return string $date_modified The date modified.
	 */
	public function get_date_modified( $context = 'view' ) {
		return $this->data['date_modified'];
	}

	/**
	 * Get date expires.
	 *
	 * @param string $context The context.
	 * @return string $date_expires The date expires.
	 */
	public function get_date_expires( $context = 'view' ) {
		return $this->data['date_expires'];
	}








	/** Setters. */

	/**
	 * Set date created.
	 *
	 * @param string $date_created The date created.
	 */
	public function set_date_created( $date_created ) {
		$this->data['date_created'] = $date_created;
	}

	/**
	 * Set date modified.
	 *
	 * @param string $date_modified The date modified.
	 */
	public function set_date_modified( $date_modified ) {
		$this->data['date_modified'] = $date_modified;
	}

	/**
	 * Set date expires.
	 *
	 * @param string $date_expires The date expires.
	 */
	public function set_date_expires( $date_expires ) {
		$this->data['date_expires'] = $date_expires;
	}
}
