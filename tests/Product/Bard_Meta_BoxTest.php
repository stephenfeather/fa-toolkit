<?php
/**
 * Tests for Bard_Meta_Box class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Product;

use FAToolkit\Product\Bard_Meta_Box;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test case for Bard_Meta_Box class.
 */
class Bard_Meta_BoxTest extends TestCase {
	/**
	 * Test that register_meta_box calls add_meta_box.
	 */
	public function test_register_meta_box_calls_add_meta_box() {
		// Stubs are declared in the test that needs them. tests/bootstrap.php
		// carries none, deliberately: a when() at bootstrap scope outlives every
		// setUp()/tearDown() and poisons later expect() calls on the same name
		// (see the note in tests/bootstrap.php, and PR #60).
		Functions\when( '__' )->returnArg();

		$meta_box = new Bard_Meta_Box();

		Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'bard_meta_box',
				'Bard Prompt',
				array( $meta_box, 'render_meta_box' ),
				'product',
				'side',
				'high'
			);

		$this->assertNull( $meta_box->register_meta_box() );
	}

	/**
	 * Test render_meta_box outputs expected product data.
	 */
	public function test_render_meta_box_outputs_product_data() {
		// Create mock post object.
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 123;
		$post->post_title  = 'Test Product';

		// Mock brand term object.
		$brand_term       = Mockery::mock( 'WP_Term' );
		$brand_term->name = 'Test Brand';

		// Expect WordPress function calls.
		// UPC lives in WooCommerce's own GTIN meta, written by the import
		// (issue #77). ACF is not installed, so get_field() must never run.
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, '_sku', true )
			->andReturn( 'TEST-SKU-123' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, '_global_unique_id', true )
			->andReturn( '123456789012' );

		Functions\expect( 'get_field' )->never();

		Functions\expect( 'wp_get_post_terms' )
			->once()
			->with( 123, 'product_brand' )
			->andReturn( array( $brand_term ) );

		Functions\expect( 'esc_html' )
			->times( 4 )
			->andReturnUsing(
				function ( $text ) {
					return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
				}
			);

		$meta_box = new Bard_Meta_Box();

		// Capture output.
		ob_start();
		$meta_box->render_meta_box( $post );
		$output = ob_get_clean();

		// Verify output contains expected data.
		$this->assertStringContainsString( 'Test Product', $output );
		$this->assertStringContainsString( 'TEST-SKU-123', $output );
		$this->assertStringContainsString( '123456789012', $output );
		$this->assertStringContainsString( 'Test Brand', $output );
		$this->assertStringContainsString( 'Title:', $output );
		$this->assertStringContainsString( 'SKU:', $output );
		$this->assertStringContainsString( 'UPC:', $output );
		$this->assertStringContainsString( 'Brand:', $output );
	}

	/**
	 * Test render_meta_box with different product data.
	 */
	public function test_render_meta_box_with_different_data() {
		// Create mock post object with different data.
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 456;
		$post->post_title  = 'Another Product';

		// Mock brand term object.
		$brand_term       = Mockery::mock( 'WP_Term' );
		$brand_term->name = 'Brand Name';

		// Mock WordPress functions with different values.
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, '_sku', true )
			->andReturn( 'SKU-456' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, '_global_unique_id', true )
			->andReturn( '999999999999' );

		Functions\expect( 'get_field' )->never();

		Functions\expect( 'wp_get_post_terms' )
			->once()
			->with( 456, 'product_brand' )
			->andReturn( array( $brand_term ) );

		Functions\expect( 'esc_html' )
			->times( 4 )
			->andReturnUsing(
				function ( $text ) {
					return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
				}
			);

		$meta_box = new Bard_Meta_Box();

		// Capture output.
		ob_start();
		$meta_box->render_meta_box( $post );
		$output = ob_get_clean();

		// Verify output contains expected data.
		$this->assertStringContainsString( 'Another Product', $output );
		$this->assertStringContainsString( 'SKU-456', $output );
		$this->assertStringContainsString( '999999999999', $output );
		$this->assertStringContainsString( 'Brand Name', $output );
	}

	/**
	 * Render the box for product 789 with a given brand lookup result.
	 *
	 * @param mixed $terms What wp_get_post_terms() returns.
	 * @return string The rendered output.
	 */
	private function render_with_brand_terms( $terms ) {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = 789;
		$post->post_title = 'Brandless Product';

		Functions\when( 'get_post_meta' )->justReturn( 'VALUE-789' );
		Functions\when( 'esc_html' )->returnArg();

		// Which taxonomy is asked for is pinned by the two tests above. These
		// tests are about what comes back, so they fail for that reason alone.
		Functions\when( 'wp_get_post_terms' )->justReturn( $terms );

		// The buffer is closed on the way out of a throw too, so a render that
		// fatals reports its own error and not an unclosed buffer.
		ob_start();

		try {
			( new Bard_Meta_Box() )->render_meta_box( $post );
		} finally {
			$output = ob_get_clean();
		}

		return $output;
	}

	/**
	 * An unregistered taxonomy makes wp_get_post_terms() return a WP_Error.
	 *
	 * Indexing it was the fatal that took down every product edit screen on
	 * vanguard: "Cannot use object of type WP_Error as array" at line 50.
	 */
	public function test_render_meta_box_survives_a_wp_error_brand_lookup() {
		$output = $this->render_with_brand_terms( new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );

		$this->assertStringContainsString( 'Brandless Product', $output );
		$this->assertMatchesRegularExpression( '#Brand: </br />#', $output );
	}

	/**
	 * A product with no brand term, as every new auto-draft is, renders an empty brand.
	 */
	public function test_render_meta_box_survives_a_product_with_no_brand() {
		// Warnings are promoted so "Undefined array key 0" fails the test
		// instead of passing silently under PHP 8's warn-and-continue.
		set_error_handler(
			static function ( $severity, $message ) {
				throw new \ErrorException( $message, 0, $severity );
			},
			E_WARNING
		);

		try {
			$output = $this->render_with_brand_terms( array() );
		} finally {
			restore_error_handler();
		}

		$this->assertStringContainsString( 'Brandless Product', $output );
		$this->assertMatchesRegularExpression( '#Brand: </br />#', $output );
	}
}
