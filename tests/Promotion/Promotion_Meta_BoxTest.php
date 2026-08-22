<?php
/**
 * Tests for FAToolkit\Promotion\Promotion_Meta_Box
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Promotion;

use Brain\Monkey\Functions;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * Test Promotion_Meta_Box class.
 *
 * @covers \FAToolkit\Promotion\Promotion_Meta_Box
 */
class Promotion_Meta_BoxTest extends TestCase {

	/**
	 * Test output() renders meta box HTML with post data.
	 */
	public function test_output_renders_meta_box() {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 123;

		Functions\expect( 'wp_nonce_field' )
			->twice()
			->andReturnNull();

		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'promotion_date_begins', true )
			->andReturn( '2024-01-15' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'promotion_date_ends', true )
			->andReturn( '2024-12-31' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'promotion_url', true )
			->andReturn( 'https://example.com/promo' );

		Functions\expect( 'esc_attr' )
			->times( 3 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'printf' )
			->times( 3 )
			->andReturnNull();

		ob_start();
		\FAToolkit\Promotion\Promotion_Meta_Box::output( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'promotion_date_begins', $output );
		$this->assertStringContainsString( 'promotion_date_ends', $output );
		$this->assertStringContainsString( 'promotion_url', $output );
		$this->assertStringContainsString( 'Beginning Date:', $output );
		$this->assertStringContainsString( 'Ending Date:', $output );
		$this->assertStringContainsString( 'Promotion URL:', $output );
	}

	/**
	 * Test save_custom_promotion_fields returns early if not a promotion post type.
	 */
	public function test_save_returns_early_if_not_promotion_post_type() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'post' );

		// Should return early, no other functions called.
		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		$this->assertTrue( true );
	}

	/**
	 * Note: Testing DOING_AUTOSAVE behavior is skipped because PHP constants
	 * cannot be reliably mocked in unit tests without runtime extensions.
	 * This is WordPress core behavior (autosave detection) rather than our
	 * business logic.
	 */

	/**
	 * Test save_custom_promotion_fields returns early if user lacks permission.
	 */
	public function test_save_returns_early_if_user_cannot_edit() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'promotion' );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 123 )
			->andReturn( false );

		// Should return early after permission check.
		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		$this->assertTrue( true );
	}

	/**
	 * Test save_custom_promotion_fields returns early if nonce is invalid.
	 */
	public function test_save_returns_early_if_nonce_invalid() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'promotion' );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 123 )
			->andReturn( true );

		// Nonce not set in $_POST.
		// Should return early.
		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		$this->assertTrue( true );
	}

	/**
	 * Test save_custom_promotion_fields saves promotion dates and URL.
	 */
	public function test_save_saves_promotion_fields() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'promotion' );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 123 )
			->andReturn( true );

		// Mock $_POST.
		$_POST['custom_promotion_nonce'] = 'nonce_value';
		$_POST['promotion_date_begins']  = '2024-01-15';
		$_POST['promotion_date_ends']    = '2024-12-31';
		$_POST['promotion_url']          = 'https://example.com/promo';

		Functions\expect( 'sanitize_text_field' )
			->times( 4 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'wp_unslash' )
			->times( 4 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( 'nonce_value', 'custom_promotion_nonce' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, 'promotion_date_begins', '2024-01-15' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, 'promotion_date_ends', '2024-12-31' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, 'promotion_url', 'https://example.com/promo' );

		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		// Clean up.
		unset( $_POST['custom_promotion_nonce'] );
		unset( $_POST['promotion_date_begins'] );
		unset( $_POST['promotion_date_ends'] );
		unset( $_POST['promotion_url'] );

		$this->assertTrue( true );
	}

	/**
	 * Test save_custom_promotion_fields saves description to post excerpt.
	 */
	public function test_save_saves_description_to_excerpt() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'promotion' );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 123 )
			->andReturn( true );

		// Mock $_POST.
		$_POST['custom_promotion_nonce'] = 'nonce_value';
		$_POST['description']            = 'Promotion description text';

		Functions\expect( 'sanitize_text_field' )
			->once()
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'sanitize_textarea_field' )
			->once()
			->with( 'Promotion description text' )
			->andReturn( 'Promotion description text' );

		Functions\expect( 'wp_unslash' )
			->times( 2 )
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( 'nonce_value', 'custom_promotion_nonce' )
			->andReturn( true );

		Functions\expect( 'wp_update_post' )
			->once()
			->withArgs(
				function ( $post ) {
					$this->assertSame( 123, $post['ID'] );
					$this->assertSame( 'Promotion description text', $post['post_excerpt'] );
					return true;
				}
			);

		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		// Clean up.
		unset( $_POST['custom_promotion_nonce'] );
		unset( $_POST['description'] );

		$this->assertTrue( true );
	}

	/**
	 * Test save_custom_promotion_fields handles partial data.
	 */
	public function test_save_handles_partial_data() {
		Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 123 )
			->andReturn( 'promotion' );

		Functions\expect( 'current_user_can' )
			->once()
			->with( 'edit_post', 123 )
			->andReturn( true );

		// Only set one field.
		$_POST['custom_promotion_nonce'] = 'nonce_value';
		$_POST['promotion_date_begins']  = '2024-01-15';

		Functions\expect( 'sanitize_text_field' )
			->twice()
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'wp_unslash' )
			->twice()
			->andReturnUsing(
				function ( $value ) {
					return $value;
				}
			);

		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( 'nonce_value', 'custom_promotion_nonce' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, 'promotion_date_begins', '2024-01-15' );

		// No other update_post_meta calls.
		\FAToolkit\Promotion\Promotion_Meta_Box::save_custom_promotion_fields( 123 );

		// Clean up.
		unset( $_POST['custom_promotion_nonce'] );
		unset( $_POST['promotion_date_begins'] );

		$this->assertTrue( true );
	}
}
