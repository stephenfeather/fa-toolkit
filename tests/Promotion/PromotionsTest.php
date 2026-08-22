<?php
/**
 * Tests for FAToolkit\Promotion\Promotions
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Promotion;

use FAToolkit\Promotion\Promotions;
use FAToolkit\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test Promotions data class.
 */
#[CoversClass( Promotions::class )]
class PromotionsTest extends TestCase {

	/**
	 * Test that constructor creates instance with default data.
	 */
	public function test_constructor_creates_instance() {
		$promotion = new Promotions();

		$this->assertInstanceOf( Promotions::class, $promotion );
	}

	/**
	 * Test get_date_created returns date_created from data array.
	 */
	public function test_get_date_created_returns_date() {
		$promotion = new Promotions();

		// Initially null.
		$this->assertNull( $promotion->get_date_created() );
	}

	/**
	 * Test get_date_modified returns date_modified from data array.
	 */
	public function test_get_date_modified_returns_date() {
		$promotion = new Promotions();

		// Initially null.
		$this->assertNull( $promotion->get_date_modified() );
	}

	/**
	 * Test get_date_expires returns date_expires from data array.
	 */
	public function test_get_date_expires_returns_date() {
		$promotion = new Promotions();

		// Initially null.
		$this->assertNull( $promotion->get_date_expires() );
	}

	/**
	 * Test set_date_created updates date_created in data array.
	 */
	public function test_set_date_created_updates_date() {
		$promotion = new Promotions();
		$date      = '2024-01-15 10:30:00';

		$promotion->set_date_created( $date );

		$this->assertSame( $date, $promotion->get_date_created() );
	}

	/**
	 * Test set_date_modified updates date_modified in data array.
	 */
	public function test_set_date_modified_updates_date() {
		$promotion = new Promotions();
		$date      = '2024-01-20 14:45:00';

		$promotion->set_date_modified( $date );

		$this->assertSame( $date, $promotion->get_date_modified() );
	}

	/**
	 * Test set_date_expires updates date_expires in data array.
	 */
	public function test_set_date_expires_updates_date() {
		$promotion = new Promotions();
		$date      = '2024-12-31 23:59:59';

		$promotion->set_date_expires( $date );

		$this->assertSame( $date, $promotion->get_date_expires() );
	}

	/**
	 * Test that context parameter is accepted but unused in getters.
	 */
	public function test_getters_accept_context_parameter() {
		$promotion = new Promotions();
		$date      = '2024-06-01 12:00:00';

		$promotion->set_date_created( $date );

		// Context parameter should be accepted but not affect output.
		$this->assertSame( $date, $promotion->get_date_created( 'view' ) );
		$this->assertSame( $date, $promotion->get_date_created( 'edit' ) );
		$this->assertSame( $date, $promotion->get_date_created() );
	}

	/**
	 * Test multiple date operations on same instance.
	 */
	public function test_multiple_date_operations() {
		$promotion = new Promotions();

		$created  = '2024-01-01 00:00:00';
		$modified = '2024-06-15 14:30:00';
		$expires  = '2024-12-31 23:59:59';

		$promotion->set_date_created( $created );
		$promotion->set_date_modified( $modified );
		$promotion->set_date_expires( $expires );

		$this->assertSame( $created, $promotion->get_date_created() );
		$this->assertSame( $modified, $promotion->get_date_modified() );
		$this->assertSame( $expires, $promotion->get_date_expires() );
	}
}
