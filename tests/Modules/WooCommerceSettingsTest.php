<?php
/**
 * Tests for WooCommerceSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\WooCommerceSettings;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test WooCommerceSettings
 *
 * Note: This class is NOT auto-initialized in the source file.
 * Cannot test hook registration directly due to Brain Monkey limitations
 * with class loading and ABSPATH checks. Tests focus on method logic.
 */
class WooCommerceSettingsTest extends TestCase {

	/**
	 * Test sell_only_states returns limited US states.
	 */
	public function test_sell_only_states_returns_limited_states() {
		Functions\expect( '__' )
			->andReturnUsing(
				function ( $text ) {
					return $text;
				}
			);

		$settings = new WooCommerceSettings();
		$result   = $settings->sell_only_states( array() );

		// Should return US states.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'US', $result );
		$this->assertIsArray( $result['US'] );

		// Should include some states.
		$this->assertArrayHasKey( 'AL', $result['US'] );
		$this->assertArrayHasKey( 'TX', $result['US'] );
		$this->assertArrayHasKey( 'FL', $result['US'] );

		// Should not include California (commented out).
		$this->assertArrayNotHasKey( 'CA', $result['US'] );

		// Should not include Alaska (commented out).
		$this->assertArrayNotHasKey( 'AK', $result['US'] );
	}

	/**
	 * Test catalog_only method exists but does nothing.
	 */
	public function test_catalog_only_exists() {
		$settings = new WooCommerceSettings();

		// Method should exist and not throw error.
		$result = $settings->catalog_only( 'TX' );
		$this->assertNull( $result );
	}

	/**
	 * Test custom_product_categories_order with matching taxonomy.
	 */
	public function test_custom_product_categories_order_with_product_cat() {
		$settings = new WooCommerceSettings();

		// Create mock term objects.
		$term1       = new \stdClass();
		$term1->slug = 'outdoors';
		$term1->name = 'Outdoors';

		$term2       = new \stdClass();
		$term2->slug = 'firearms';
		$term2->name = 'Firearms';

		$term3       = new \stdClass();
		$term3->slug = 'ammunition';
		$term3->name = 'Ammunition';

		$terms = array( $term1, $term2, $term3 );

		$args = array( 'taxonomy' => 'product_cat' );

		$result = $settings->custom_product_categories_order( $terms, array(), $args );

		// Firearms should be first (position 0 in custom order).
		$this->assertSame( 'firearms', $result[0]->slug );

		// Ammunition should be second (position 1 in custom order).
		$this->assertSame( 'ammunition', $result[1]->slug );

		// Outdoors should be last (position 13 in custom order).
		$this->assertSame( 'outdoors', $result[2]->slug );
	}

	/**
	 * Test custom_product_categories_order with non-matching taxonomy.
	 */
	public function test_custom_product_categories_order_with_non_product_cat() {
		$settings = new WooCommerceSettings();

		$term1       = new \stdClass();
		$term1->slug = 'category1';

		$terms = array( $term1 );
		$args  = array( 'taxonomy' => 'post_tag' );

		$result = $settings->custom_product_categories_order( $terms, array(), $args );

		// Should return unchanged when taxonomy is not product_cat.
		$this->assertSame( $terms, $result );
	}

	/**
	 * Test custom_product_categories_order with unlisted categories.
	 */
	public function test_custom_product_categories_order_with_unlisted_categories() {
		$settings = new WooCommerceSettings();

		$term1       = new \stdClass();
		$term1->slug = 'firearms';

		$term2       = new \stdClass();
		$term2->slug = 'unlisted-category';

		$term3       = new \stdClass();
		$term3->slug = 'another-unlisted';

		$terms = array( $term1, $term2, $term3 );
		$args  = array( 'taxonomy' => 'product_cat' );

		$result = $settings->custom_product_categories_order( $terms, array(), $args );

		// Firearms should be first.
		$this->assertSame( 'firearms', $result[0]->slug );

		// Unlisted categories should come after (order not guaranteed between them).
		$unlisted_slugs = array( $result[1]->slug, $result[2]->slug );
		$this->assertContains( 'unlisted-category', $unlisted_slugs );
		$this->assertContains( 'another-unlisted', $unlisted_slugs );
	}
}
