<?php
/**
 * Tests for Product_Category_Counts.
 *
 * @package FAToolkit\Tests\Admin
 */

namespace FAToolkit\Tests\Admin;

use Brain\Monkey\Functions;
use FAToolkit\Admin\Product_Category_Counts;
use FAToolkit\Tests\TestCase;

/**
 * Product_Category_Counts renders the "Product Category Counts" admin page.
 *
 * Issue #24.
 */
class Product_Category_CountsTest extends TestCase {

	/**
	 * Restore superglobals touched by the page tests.
	 */
	protected function tearDown(): void {
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Build a category term object as get_terms() returns it.
	 *
	 * @param int    $id    Term id.
	 * @param string $name  Term name.
	 * @param int    $count Product count.
	 * @return object
	 */
	private function category( $id, $name, $count ) {
		$term          = new \stdClass();
		$term->term_id = $id;
		$term->name    = $name;
		$term->count   = $count;
		return $term;
	}

	/**
	 * Stub every escaping and URL helper the page calls as a pass-through.
	 */
	private function stub_output_helpers() {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			function ( $args ) {
				return 'sort_by=' . $args['sort_by'] . '&sort_order=' . $args['sort_order'];
			}
		);
	}

	/**
	 * The constructor hooks the menu registration onto admin_menu.
	 */
	public function test_constructor_registers_admin_menu_hook() {
		$counts = new Product_Category_Counts();

		$this->assertNotFalse( has_action( 'admin_menu', array( $counts, 'add_product_category_counts_menu' ) ) );
	}

	/**
	 * The page is a submenu of Products, gated on manage_options.
	 */
	public function test_menu_is_a_products_submenu_requiring_manage_options() {
		$counts = new Product_Category_Counts();
		$added  = array();
		Functions\when( 'add_submenu_page' )->alias(
			function ( ...$args ) use ( &$added ) {
				$added[] = $args;
				return 'hook-suffix';
			}
		);

		$counts->add_product_category_counts_menu();

		$this->assertSame(
			array(
				array(
					'edit.php?post_type=product',
					'Product Category Counts',
					'Product Category Counts',
					'manage_options',
					'product-category-counts',
					array( $counts, 'product_category_counts_page' ),
				),
			),
			$added
		);
	}

	/**
	 * Stub the data the page reads: the category list, its lineage lookups,
	 * the product counts, and the nonce field the refresh form emits.
	 *
	 * @param array $categories Category stubs from category().
	 * @param int   $published  Published product count.
	 * @param int   $draft      Draft product count.
	 */
	private function stub_page_data( array $categories, $published = 0, $draft = 0 ) {
		$this->stub_output_helpers();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_terms' )->justReturn( $categories );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		$names = array_column( $categories, 'name', 'term_id' );
		Functions\when( 'get_term' )->alias(
			function ( $id ) use ( $names ) {
				$term       = new \stdClass();
				$term->name = $names[ $id ];
				return $term;
			}
		);
		$posts          = new \stdClass();
		$posts->publish = $published;
		$posts->draft   = $draft;
		Functions\when( 'wp_count_posts' )->justReturn( $posts );
	}

	/**
	 * Make the request carry a verified sort nonce with the given values.
	 *
	 * @param string $sort_by    Requested sort field.
	 * @param string $sort_order Requested sort order.
	 */
	private function request_verified_sort( $sort_by, $sort_order ) {
		$_REQUEST = array(
			'product_category_counts_sort_nonce' => 'n',
			'sort_by'                            => $sort_by,
			'sort_order'                         => $sort_order,
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
	}

	/**
	 * Render the page and return its output.
	 *
	 * @return string
	 */
	private function render_page() {
		ob_start();
		( new Product_Category_Counts() )->product_category_counts_page();
		return ob_get_clean();
	}

	/**
	 * Lineage lists ancestors root-first and the category itself last.
	 *
	 * get_ancestors() returns nearest parent first, so the method must reverse it.
	 */
	public function test_get_category_lineage_orders_root_first_then_category() {
		$names = array(
			1 => 'Firearms',
			2 => 'Handguns',
			3 => 'Revolvers',
		);
		Functions\when( 'get_ancestors' )->justReturn( array( 2, 1 ) );
		Functions\when( 'get_term' )->alias(
			function ( $id ) use ( $names ) {
				$term       = new \stdClass();
				$term->name = $names[ $id ];
				return $term;
			}
		);

		$lineage = ( new Product_Category_Counts() )->get_category_lineage( 3 );

		$this->assertSame( array( 'Firearms', 'Handguns', 'Revolvers' ), $lineage );
	}

	/**
	 * A top-level category has a one-element lineage.
	 */
	public function test_get_category_lineage_for_top_level_category() {
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_term' )->alias(
			function () {
				$term       = new \stdClass();
				$term->name = 'Optics';
				return $term;
			}
		);

		$this->assertSame( array( 'Optics' ), ( new Product_Category_Counts() )->get_category_lineage( 9 ) );
	}

	/**
	 * A single name is marked both top-level and the category.
	 */
	public function test_build_lineage_string_marks_single_level_as_top_and_category() {
		Functions\when( 'esc_html' )->returnArg();

		$html = ( new Product_Category_Counts() )->build_lineage_string( array( 'Optics' ) );

		$this->assertSame( '<span class="fa-pc-catLevel-1 fa-pc-top fa-pc-category ">Optics</span>', $html );
	}

	/**
	 * Multi-level lineage numbers each level, separates with " > ", and marks
	 * only the last as the category.
	 */
	public function test_build_lineage_string_joins_levels_and_marks_only_last_as_category() {
		Functions\when( 'esc_html' )->returnArg();

		$html = ( new Product_Category_Counts() )->build_lineage_string( array( 'Firearms', 'Handguns' ) );

		$this->assertSame(
			'<span class="fa-pc-catLevel-1 ">Firearms</span> > <span class="fa-pc-catLevel-2 fa-pc-category ">Handguns</span>',
			$html
		);
	}

	/**
	 * Names are escaped before they land in the markup.
	 */
	public function test_build_lineage_string_escapes_names() {
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );

		$html = ( new Product_Category_Counts() )->build_lineage_string( array( 'A & B' ) );

		$this->assertStringContainsString( 'A &amp; B', $html );
		$this->assertStringNotContainsString( 'A & B', $html );
	}

	/**
	 * Sorting by name is case-insensitive and honours direction.
	 */
	public function test_sort_categories_by_name_in_both_directions() {
		$counts     = new Product_Category_Counts();
		$categories = array(
			$this->category( 1, 'beta', 5 ),
			$this->category( 2, 'Alpha', 1 ),
			$this->category( 3, 'gamma', 3 ),
		);

		$counts->sort_categories( $categories, 'name', 'asc' );
		$this->assertSame( array( 'Alpha', 'beta', 'gamma' ), array_column( $categories, 'name' ) );

		$counts->sort_categories( $categories, 'name', 'desc' );
		$this->assertSame( array( 'gamma', 'beta', 'Alpha' ), array_column( $categories, 'name' ) );
	}

	/**
	 * Sorting by count is numeric and honours direction.
	 */
	public function test_sort_categories_by_count_in_both_directions() {
		$counts     = new Product_Category_Counts();
		$categories = array(
			$this->category( 1, 'a', 10 ),
			$this->category( 2, 'b', 2 ),
			$this->category( 3, 'c', 33 ),
		);

		$counts->sort_categories( $categories, 'count', 'asc' );
		$this->assertSame( array( 2, 10, 33 ), array_column( $categories, 'count' ) );

		$counts->sort_categories( $categories, 'count', 'desc' );
		$this->assertSame( array( 33, 10, 2 ), array_column( $categories, 'count' ) );
	}

	/**
	 * The stylesheet block is emitted once with both rules.
	 */
	public function test_create_css_styles_outputs_style_block() {
		ob_start();
		( new Product_Category_Counts() )->create_css_styles();
		$out = ob_get_clean();

		$this->assertStringStartsWith( '<style>', $out );
		$this->assertStringEndsWith( '</style>', $out );
		$this->assertStringContainsString( '.fa-pc-top', $out );
	}

	/**
	 * The refresh form posts the force_recount_product_cat action to admin-post.php
	 * with a nonce field.
	 */
	public function test_create_refresh_counts_form_posts_recount_action_with_nonce() {
		$this->stub_output_helpers();
		Functions\expect( 'wp_nonce_field' )
			->once()
			->with( 'force_recount_product_cat', 'force_recount_product_cat_nonce' );

		ob_start();
		( new Product_Category_Counts() )->create_refresh_counts_form();
		$out = ob_get_clean();

		$this->assertStringContainsString( '<form method="post" action="admin-post.php">', $out );
		$this->assertStringContainsString( 'name="action" value="force_recount_product_cat"', $out );
		$this->assertStringContainsString( '<button type="submit">Force Recount</button>', $out );
	}

	/**
	 * The active column's link flips direction and shows an arrow; the other
	 * column links ascending with no arrow.
	 */
	public function test_create_table_headers_toggles_active_sort_and_shows_arrow() {
		$this->stub_output_helpers();

		ob_start();
		( new Product_Category_Counts() )->create_table_headers( 'name', 'asc', 'act', 'nonce' );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'href="sort_by=name&sort_order=desc">Category Name <span class="dashicons dashicons-arrow-down"></span>', $out );
		$this->assertStringContainsString( 'href="sort_by=count&sort_order=asc">Number of Products </a>', $out );
	}

	/**
	 * Without a nonce the page reports the totals and labels the headers as
	 * name / ascending, but leaves the rows in get_terms() order.
	 *
	 * That last part is a defect, recorded here rather than fixed: the no-nonce
	 * branch sets $sort_by and $sort_order and never sorts, while the public
	 * sort_categories() method sits unused. The assertion pins the current
	 * behaviour so the fix, when it lands, flips exactly one assertion.
	 */
	public function test_page_without_a_nonce_reports_totals_and_leaves_rows_in_get_terms_order() {
		$this->stub_page_data( array( $this->category( 2, 'Zulu', 4 ), $this->category( 1, 'Alpha', 9 ) ), 120, 3 );
		Functions\expect( 'wp_verify_nonce' )->never();

		$out = $this->render_page();

		$this->assertStringContainsString( '<p>Total Categories: 2 </p>', $out );
		$this->assertStringContainsString( '<p>Total Published Products: 120 </p>', $out );
		$this->assertStringContainsString( '<p>Total Draft Products: 3 </p>', $out );
		$this->assertLessThan( strpos( $out, '>Alpha<' ), strpos( $out, '>Zulu<' ), 'Rows stay in get_terms() order: Zulu first. See the docblock.' );
		$this->assertStringContainsString( 'href="sort_by=name&sort_order=desc"', $out, 'Name header must offer the flip to desc.' );
	}

	/**
	 * With a valid nonce the request's sort_by and sort_order are applied.
	 */
	public function test_page_sorts_by_count_when_nonce_verifies() {
		$this->stub_page_data( array( $this->category( 1, 'Few', 2 ), $this->category( 2, 'Many', 50 ) ) );
		$this->request_verified_sort( 'count', 'desc' );
		$verified = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			function ( ...$args ) use ( &$verified ) {
				$verified[] = $args;
				return 1;
			}
		);

		$out = $this->render_page();

		$this->assertSame( array( array( 'n', 'product_category_counts_sort_action' ) ), $verified );
		$this->assertLessThan( strpos( $out, '>Few<' ), strpos( $out, '>Many<' ), 'Many (50) must precede Few (2) in count desc.' );
		$this->assertStringContainsString( 'href="sort_by=count&sort_order=asc">Number of Products <span class="dashicons dashicons-arrow-up"></span>', $out );
	}

	/**
	 * Unknown sort values fall back to name / desc rather than being used raw.
	 */
	public function test_page_rejects_unknown_sort_values_when_nonce_verifies() {
		$this->stub_page_data( array( $this->category( 1, 'Alpha', 1 ), $this->category( 2, 'Zulu', 1 ) ) );
		$this->request_verified_sort( 'evil', 'sideways' );

		$out = $this->render_page();

		$this->assertStringNotContainsString( 'evil', $out );
		$this->assertStringNotContainsString( 'sideways', $out );
		$this->assertLessThan( strpos( $out, '>Alpha<' ), strpos( $out, '>Zulu<' ), 'Fallback is name desc, so Zulu precedes Alpha.' );
	}
}
