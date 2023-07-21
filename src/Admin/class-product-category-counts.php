<?php
/**
 * Creates an admin menu item that lists product categories and the number of products in each category.
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

namespace FAToolkit\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Product Category Counts
 */
class Product_Category_Counts {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_product_category_counts_menu' ) );
	}

	/**
	 * Adds the product category counts menu item.
	 */
	public function add_product_category_counts_menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			'Product Category Counts',
			'Product Category Counts',
			'manage_options',
			'product-category-counts',
			array( $this, 'product_category_counts_page' )
		);
	}

	/**
	 * Displays the product category counts page.
	 */
	public function product_category_counts_page() {

		$nonce_action = 'product_category_counts_sort_action';
		$nonce_name   = 'product_category_counts_sort_nonce';

		$sort_by    = isset( $_REQUEST['sort_by'] ) && in_array( $_REQUEST['sort_by'], array( 'name', 'count' ), true ) ? sanitize_text_field( wp_unslash( $_REQUEST['sort_by'] ) ) : 'name';
		$sort_order = isset( $_REQUEST['sort_order'] ) && in_array( $_REQUEST['sort_order'], array( 'asc', 'desc' ), true ) ? sanitize_text_field( wp_unslash( $_REQUEST['sort_order'] ) ) : 'desc';

		// Sort the categories array.
		if ( 'name' === $sort_by ) {
			usort(
				$categories,
				function ( $a, $b ) use ( $sort_order ) {
					return 'asc' === $sort_order ? strcasecmp( $a->name, $b->name ) : strcasecmp( $b->name, $a->name );
				}
			);
		} else {
			usort(
				$categories,
				function ( $a, $b ) use ( $sort_order ) {
					return 'asc' === $sort_order ? $a->count - $b->count : $b->count - $a->count;
				}
			);
		}

		// Get total categories and published product counts.
		$total_categories         = count( $categories );
		$total_published_products = wp_count_posts( 'product' )->publish;
		$total_draft_products     = wp_count_posts( 'product' )->draft;

		// Output our css styles.
		$this->create_css_styles();

		// Outout our pretable content.
		echo '<div class="wrap">';
		echo '<h1>Product Category Counts</h1>';
		echo '<p>Total Categories: ' . esc_html( $total_categories ) . '</p>';
		echo '<p>Total Published Products: ' . esc_html( $total_published_products ) . '</p>';
		echo '<p>Total Draft Products: ' . esc_html( $total_draft_products ) . '</p>';

		// Output our refresh counts form.
		$this->create_refresh_counts_form();

		// Output our table.
		echo '<table class="wp-list-table widefat striped">';
		$this->create_table_headers( $sort_by, $sort_order, $nonce_action, $nonce_name );
		echo '<tbody>';
		foreach ( $categories as $category ) {
			$lineage_names  = $this->get_category_lineage( $category->term_id );
			$lineage_string = $this->build_lineage_string( $lineage_names );
			echo '<tr>';
			echo '<td>' . esc_html( $lineage_string ) . '</td>';
			echo '<td>' . esc_html( $category->count ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Retrieves the lineage (ancestors) of a category.
	 *
	 * @param int $category_id The ID of the category.
	 * @return array An array of lineage names.
	 */
	public function get_category_lineage( $category_id ) {
		$lineage_names = array();
		$ancestors     = get_ancestors( $category_id, 'product_cat' );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			array_unshift( $lineage_names, $ancestor->name );
		}

		// Add the category name at the end.
		$category        = get_term( $category_id, 'product_cat' );
		$lineage_names[] = $category->name;

		return $lineage_names;
	}

	/**
	 * Builds the lineage string and assigns CSS class for each parent category.
	 *
	 * @param array $lineage_names The array of lineage names.
	 * @return string The lineage string with individual CSS classes for each level.
	 */
	public function build_lineage_string( $lineage_names ) {
		$lineage_string  = '';
		$lineage_classes = '';
		foreach ( $lineage_names as $index => $name ) {
			$level            = $index + 1;
			$cat              = ( count( $lineage_names ) - 1 === $index ) ? 'fa-pc-category ' : '';
			$top              = ( 1 === count( $lineage_names ) ) ? 'fa-pc-top ' : '';
			$lineage_string  .= '<span class="fa-pc-catLevel-' . $level . ' ' . $top . $cat . '">' . esc_html( $name ) . '</span>';
			$lineage_classes .= ' fa-pc-catLevel-' . $level;
			if ( $index < count( $lineage_names ) - 1 ) {
				$lineage_string .= ' > ';
			}
		}

		return $lineage_string;
	}

	/**
	 * Sorts an array of categories based on the specified sort criteria.
	 *
	 * @param array  $categories The array of category objects to be sorted. (Passed by reference).
	 * @param string $sort_by The field to sort the categories by. Possible values: 'name' or 'count'.
	 * @param string $sort_order The sort order. Possible values: 'asc' for ascending or 'desc' for descending.
	 * @return void
	 */
	public function sort_categories( &$categories, $sort_by, $sort_order ) {
		if ( 'name' === $sort_by ) {
			usort(
				$categories,
				function ( $a, $b ) use ( $sort_order ) {
					return 'asc' === $sort_order ? strcasecmp( $a->name, $b->name ) : strcasecmp( $b->name, $a->name );
				}
			);
		} else {
			usort(
				$categories,
				function ( $a, $b ) use ( $sort_order ) {
					return 'asc' === $sort_order ? $a->count - $b->count : $b->count - $a->count;
				}
			);
		}
	}

	/**
	 * Create our CSS styles.
	 *
	 * @return void
	 */
	public function create_css_styles() {
		echo '<style>';
		echo '.fa-pc-top { color: #000000; font-weight: bold; }';
		echo '.parent-category { color: #000000; }';
		echo '</style>';
	}

	/**
	 * Create our counts refresh form.
	 *
	 * @return void
	 */
	public function create_refresh_counts_form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="force_recount_product_cat">';
		wp_nonce_field( 'force_recount_product_cat', 'force_recount_product_cat_nonce' );
		echo '<p>';
		echo '</p>';
		echo '<button type="submit">Force Recount</button>';
		echo '</form>';
	}

	/**
	 * Create our table headers.
	 *
	 * @param string $sort_by The field to sort the categories by. Possible values: 'name' or 'count'.
	 * @param string $sort_order The sort order. Possible values: 'asc' for ascending or 'desc' for descending.
	 * @param string $nonce_action The nonce action.
	 * @param string $nonce_name The nonce name.
	 * @return void
	 */
	public function create_table_headers( $sort_by, $sort_order, $nonce_action, $nonce_name ) {
		echo '<thead>';
		echo '<tr>';
		echo '<th><a href="' . esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'sort_by'    => 'name',
						'sort_order' => 'name' === $sort_by && 'asc' === $sort_order ? 'desc' : 'asc',
					)
				)
			),
			$nonce_action,
			$nonce_name
		) . '">Category Name ' . ( 'name' === $sort_by ? '<span class="dashicons dashicons-arrow-' . ( 'asc' === $sort_order ? 'down' : 'up' ) . '"></span>' : '' ) . '</a></th>';

		echo '<th><a href="' . esc_url(
			wp_nonce_url(
				add_query_arg(
					array(
						'sort_by'    => 'count',
						'sort_order' => 'count' === $sort_by && 'asc' === $sort_order ? 'desc' : 'asc',
					)
				)
			),
			$nonce_action,
			$nonce_name
		) . '">Number of Products ' . ( 'count' === $sort_by ? '<span class="dashicons dashicons-arrow-' . ( 'asc' === $sort_order ? 'down' : 'up' ) . '"></span>' : '' ) . '</a></th>';

		echo '</tr>';
		echo '</thead>';
	}

}

new Product_Category_Counts();
