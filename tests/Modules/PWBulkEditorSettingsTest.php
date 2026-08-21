<?php
/**
 * Tests for PWBulkEditorSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\PWBulkEditorSettings;
use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoFileScopeInstantiation;
use Brain\Monkey\Functions;

/**
 * Test PWBulkEditorSettings
 *
 * Tests focus on public methods that modify PW Bulk Editor columns and filters.
 */
class PWBulkEditorSettingsTest extends TestCase {

	use AssertsNoFileScopeInstantiation;

	/**
	 * The class file must not construct itself at include time.
	 *
	 * fa-toolkit.php:96 already instantiates this class. A second, file-scope
	 * construction registers all eight of the constructor's filters twice —
	 * WordPress keys object-method callbacks on spl_object_hash(), so both
	 * registrations survive and every filter runs twice.
	 *
	 * For this class specifically that is a query-breaking defect rather than
	 * wasted work: see test_common_joins_category_count_is_not_idempotent.
	 *
	 * Issue #18, row 2.
	 *
	 * @return void
	 */
	public function test_class_file_does_not_instantiate_at_file_scope() {
		$this->assertNoFileScopeInstantiation(
			dirname( __DIR__, 2 ) . '/src/Modules/class-pwbulkeditorsettings.php'
		);
	}

	/**
	 * Applying the category-count join filter twice produces a duplicate SQL alias.
	 *
	 * This documents WHY double registration is fatal here rather than merely
	 * wasteful. The method appends a `LEFT JOIN ( ... ) AS category_counts`;
	 * applied twice, one query carries the alias `category_counts` twice, which
	 * MySQL rejects with ERROR 1066 "Not unique table/alias".
	 *
	 * The method is legitimately non-idempotent — a filter that appends to a
	 * joins string is supposed to append. The invariant that keeps the query
	 * valid is that it is registered exactly once, which is what
	 * test_class_file_does_not_instantiate_at_file_scope guards.
	 *
	 * Issue #18, row 2.
	 *
	 * @return void
	 */
	public function test_common_joins_category_count_is_not_idempotent() {
		global $wpdb;
		$wpdb                    = new \stdClass();
		$wpdb->term_relationships = 'wp_term_relationships';
		$wpdb->term_taxonomy     = 'wp_term_taxonomy';
		$wpdb->terms             = 'wp_terms';

		$settings = new PWBulkEditorSettings();

		$once  = $settings->pwbe_common_joins_category_count( '' );
		$twice = $settings->pwbe_common_joins_category_count( $once );

		$this->assertSame( 1, substr_count( $once, 'AS category_counts' ) );
		$this->assertSame(
			2,
			substr_count( $twice, 'AS category_counts' ),
			'Two applications must yield a duplicate alias — that duplicate is the ERROR 1066.'
		);
	}

	/**
	 * Test pw_bulk_edit_custom_column_order adds custom columns.
	 */
	public function test_pw_bulk_edit_custom_column_order_adds_custom_columns() {
		$settings = new PWBulkEditorSettings();

		$columns = array();
		$result  = $settings->pw_bulk_edit_custom_column_order( $columns );

		// Should have added 2 custom columns (Distributor and UPC).
		$this->assertGreaterThanOrEqual( 2, count( $result ) );

		// Check that Distributor column was added.
		$distributor_found = false;
		foreach ( $result as $column ) {
			if ( isset( $column['name'] ) && 'Distributor' === $column['name'] ) {
				$distributor_found = true;
				$this->assertSame( 'acf_dealer', $column['field'] );
				$this->assertTrue( $column['readonly'] );
				break;
			}
		}
		$this->assertTrue( $distributor_found, 'Distributor column should be added' );

		// Check that UPC column was added.
		$upc_found = false;
		foreach ( $result as $column ) {
			if ( isset( $column['name'] ) && 'UPC' === $column['name'] ) {
				$upc_found = true;
				$this->assertSame( 'upc_code', $column['field'] );
				$this->assertTrue( $column['readonly'] );
				break;
			}
		}
		$this->assertTrue( $upc_found, 'UPC column should be added' );
	}

	/**
	 * Test pw_bulk_edit_custom_column_order reorders columns.
	 */
	public function test_pw_bulk_edit_custom_column_order_reorders_columns() {
		$settings = new PWBulkEditorSettings();

		$columns = array(
			array( 'name' => 'Product name' ),
			array( 'name' => 'SKU' ),
			array( 'name' => 'ID' ),
		);

		$result = $settings->pw_bulk_edit_custom_column_order( $columns );

		// ID should be first based on new_order.
		$first_column_name = isset( $result[0]['name'] ) ? $result[0]['name'] : '';
		$this->assertSame( 'ID', $first_column_name );

		// SKU should be second.
		$second_column_name = isset( $result[1]['name'] ) ? $result[1]['name'] : '';
		$this->assertSame( 'SKU', $second_column_name );
	}

	/**
	 * Test pwbe_results_product_acf_upc sets acf_dealer property.
	 */
	public function test_pwbe_results_product_acf_upc() {
		Functions\expect( 'get_field' )
			->once()
			->with( 'upc_code', 123 )
			->andReturn( '1234567890' );

		$settings = new PWBulkEditorSettings();

		$product          = new \stdClass();
		$product->post_id = 123;

		$column = array( 'field' => 'acf_upc_code' );

		$result = $settings->pwbe_results_product_acf_upc( $product, $column );

		$this->assertSame( '1234567890', $result->acf_dealer );
	}

	/**
	 * Test pwbe_results_product_acf_dealer sets acf_dealer property.
	 */
	public function test_pwbe_results_product_acf_dealer() {
		Functions\expect( 'get_field' )
			->once()
			->with( 'dealer', 123 )
			->andReturn( 'Test Dealer' );

		$settings = new PWBulkEditorSettings();

		$product          = new \stdClass();
		$product->post_id = 123;

		$column = array( 'field' => 'acf_dealer' );

		$result = $settings->pwbe_results_product_acf_dealer( $product, $column );

		$this->assertSame( 'Test Dealer', $result->acf_dealer );
	}

	/**
	 * Test pwbe_filter_types_dates adds date filters.
	 */
	public function test_pwbe_filter_types_dates() {
		$settings = new PWBulkEditorSettings();

		$filter_types = array();
		$result       = $settings->pwbe_filter_types_dates( $filter_types );

		$this->assertArrayHasKey( 'post_date', $result );
		$this->assertSame( 'Created On Date', $result['post_date']['name'] );
		$this->assertSame( 'text', $result['post_date']['type'] );

		$this->assertArrayHasKey( 'post_modified', $result );
		$this->assertSame( 'Last Edited Date', $result['post_modified']['name'] );
		$this->assertSame( 'text', $result['post_modified']['type'] );
	}

	/**
	 * Test pwbe_filter_types_category_count adds category count filter.
	 */
	public function test_pwbe_filter_types_category_count() {
		$settings = new PWBulkEditorSettings();

		$filter_types = array();
		$result       = $settings->pwbe_filter_types_category_count( $filter_types );

		$this->assertArrayHasKey( 'category_count', $result );
		$this->assertSame( 'Category Count', $result['category_count']['name'] );
		$this->assertSame( 'numeric', $result['category_count']['type'] );
	}
}
