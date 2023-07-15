<?php
/**
 * Custom PW Bulk Editor Settings.
 *
 * @package FAToolkit
 * @since   1.0.4
 */

namespace FAToolkit\Modules;

/**
 * PW Bulk Editor Settings.
 */
class PWBulkEditorSettings {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'pwbe_product_columns', array( $this, 'pw_bulk_edit_custom_column_order' ), 11 );
		add_filter( 'pwbe_results_product', array( $this, 'pwbe_results_product_acf_upc' ), 10, 2 );
		add_filter( 'pwbe_results_product', array( $this, 'pwbe_results_product_acf_dealer' ), 10, 2 );
		add_filter( 'pwbe_filter_types', array( $this, 'pwbe_filter_types_dates' ) );
		add_filter( 'pwbe_where_clause', array( $this, 'pwbe_where_clause_dates' ), 10, 6 );
		add_filter( 'pwbe_filter_types', array( $this, 'pwbe_filter_types_category_count' ) );
		add_filter( 'pwbe_common_joins', array( $this, 'pwbe_common_joins_category_count' ) );
		add_filter( 'pwbe_where_clause', array( $this, 'pwbe_where_clause_category_count' ), 10, 6 );
	}

	/**
	 * PW Bulk Edit Custom Column Order.
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public function pw_bulk_edit_custom_column_order( $columns ) {
		$columns[] = array(
			'name'       => 'Distributor',
			'type'       => 'text',
			'table'      => 'calculated',
			'field'      => 'acf_dealer',
			'visibility' => 'both',
			'readonly'   => true,
			'sortable'   => 'false',
		);

		$columns[] = array(
			'name'       => 'UPC',
			'type'       => 'text',
			'table'      => 'calculated',
			'field'      => 'upc_code',
			'visibility' => 'both',
			'readonly'   => true,
			'sortable'   => 'true',
		);

		$new_order = array(
			'ID',
			'SKU',
			'Image',
			'Product gallery',
			'Product name',
			'Categories',
			'Status',
			'Regular price',
			'Sale price',
			'Sale start date',
			'Sale end date',
			'Product description',
			'Short description',
			'Distributor',
			'UPC',
		);

		$modified_columns = $columns;
		$first            = array_fill( 0, count( $new_order ), '' );

		$column_count = count( $columns );
		for ( $x = 0; $x < $column_count; $x++ ) {
			$custom_index = array_search( $columns[ $x ]['name'], $new_order, true );
			if ( false !== $custom_index ) {
				$first[ $custom_index ] = $columns[ $x ];
				unset( $modified_columns[ $x ] );
			}
		}

		return array_merge( $first, $modified_columns );
	}

	/**
	 * PWBE Results Product ACF UPC.
	 *
	 * @param object $pwbe_product PWBE Product.
	 * @param array  $column       Column.
	 */
	public function pwbe_results_product_acf_upc( $pwbe_product, $column ) {
		if ( 'acf_upc_code' === $column['field'] ) {
			$result                   = get_field( 'upc_code', $pwbe_product->post_id );
			$pwbe_product->acf_dealer = $result;
		}

		return $pwbe_product;
	}

	/**
	 * PWBE Results Product ACF Dealer.
	 *
	 * @param object $pwbe_product PWBE Product.
	 * @param array  $column       Column.
	 */
	public function pwbe_results_product_acf_dealer( $pwbe_product, $column ) {
		if ( 'acf_dealer' === $column['field'] ) {
			$result                   = get_field( 'dealer', $pwbe_product->post_id );
			$pwbe_product->acf_dealer = $result;
		}

		return $pwbe_product;
	}

	/**
	 * PWBE Filter Types Dates.
	 *
	 * @param array $filter_types Filter Types.
	 */
	public function pwbe_filter_types_dates( $filter_types ) {
		$filter_types['post_date']     = array(
			'name' => 'Created On Date',
			'type' => 'text',
		);
		$filter_types['post_modified'] = array(
			'name' => 'Last Edited Date',
			'type' => 'text',
		);

		return $filter_types;
	}

	/**
	 * PWBE Where Clause Dates.
	 *
	 * @param string $row_sql      Row SQL.
	 * @param string $field_name   Field Name.
	 * @param string $filter_type  Filter Type.
	 * @param string $field_value  Field Value.
	 * @param string $field_value2 Field Value 2.
	 * @param string $group_type   Group Type.
	 */
	public function pwbe_where_clause_dates( $row_sql, $field_name, $filter_type, $field_value, $field_value2, $group_type ) {
		if ( in_array( $field_name, array( 'post_date', 'post_modified' ), true ) ) {
			$sql_builder = new PWBE_SQL_Builder();
			$row_sql     = $sql_builder->string_search( 'post.' . $field_name, $filter_type, $field_value, $field_value2 );
		}

		return $row_sql;
	}

	/**
	 * PWBE Filter Types Category Count.
	 *
	 * @param array $filter_types Filter Types.
	 */
	public function pwbe_filter_types_category_count( $filter_types ) {
		$filter_types['category_count'] = array(
			'name' => 'Category Count',
			'type' => 'numeric',
		);

		return $filter_types;
	}

	/**
	 * PWBE Common Joins Category Count.
	 *
	 * @param string $common_joins Common Joins.
	 */
	public function pwbe_common_joins_category_count( $common_joins ) {
		global $wpdb;

		$common_joins .= "
	    LEFT JOIN (
	        SELECT tr.object_id, COUNT(DISTINCT tt.term_taxonomy_id) as category_count
	        FROM {$wpdb->term_relationships} AS tr
	        INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
	        INNER JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
	        WHERE tt.taxonomy = 'product_cat'
	        GROUP BY tr.object_id
	    ) AS category_counts ON (category_counts.object_id = parent.ID)
		";

		return $common_joins;
	}

	/**
	 * PWBE Where Clause Category Count.
	 *
	 * @param string $row_sql      Row SQL.
	 * @param string $field_name   Field Name.
	 * @param string $filter_type  Filter Type.
	 * @param string $field_value  Field Value.
	 * @param string $field_value2 Field Value 2.
	 * @param string $group_type   Group Type.
	 */
	public function pwbe_where_clause_category_count( $row_sql, $field_name, $filter_type, $field_value, $field_value2, $group_type ) {
		if ( 'category_count' === $field_name ) {
			$sql_builder = new PWBE_SQL_Builder();
			$row_sql     = $sql_builder->numeric_search( 'category_counts.category_count', $filter_type, $field_value, $field_value2 );
		}

		return $row_sql;
	}
}

new PWBulkEditorSettings();
