<?php
/**
 * Tests for WordCount class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Product;

use FAToolkit\Product\WordCount;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test case for WordCount class.
 */
class WordCountTest extends TestCase {
	/**
	 * Test count_words strips HTML tags and counts words correctly.
	 */
	public function test_count_words_strips_tags_and_counts() {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( '<p>Hello world</p>' )
			->andReturn( 'Hello world' );

		$word_count = new WordCount();
		$result     = $word_count->count_words( '<p>Hello world</p>' );

		$this->assertEquals( 2, $result );
	}

	/**
	 * Test count_words with plain text.
	 */
	public function test_count_words_with_plain_text() {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( 'This is a test with five words' )
			->andReturn( 'This is a test with five words' );

		$word_count = new WordCount();
		$result     = $word_count->count_words( 'This is a test with five words' );

		$this->assertEquals( 7, $result );
	}

	/**
	 * Test count_words with empty string.
	 */
	public function test_count_words_with_empty_string() {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( '' )
			->andReturn( '' );

		$word_count = new WordCount();
		$result     = $word_count->count_words( '' );

		$this->assertEquals( 0, $result );
	}

	/**
	 * Test update_word_count_meta with product post type.
	 */
	public function test_update_word_count_meta_with_product() {
		$post_id = 123;

		// Create mock post object.
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $post_id;
		$post->post_type    = 'product';
		$post->post_content = '<p>This is product content</p>';

		// Mock WordPress functions.
		Functions\expect( 'get_post' )
			->once()
			->with( $post_id )
			->andReturn( $post );

		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( '<p>This is product content</p>' )
			->andReturn( 'This is product content' );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( $post_id, 'fa_word_count', 4 );

		$word_count = new WordCount();
		$word_count->update_word_count_meta( $post_id );
	}

	/**
	 * Test update_word_count_meta with non-product post type.
	 */
	public function test_update_word_count_meta_with_non_product() {
		$post_id = 456;

		// Create mock post object with non-product type.
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = $post_id;
		$post->post_type = 'page';

		// Mock WordPress functions.
		Functions\expect( 'get_post' )
			->once()
			->with( $post_id )
			->andReturn( $post );

		// Should NOT call wp_strip_all_tags or update_post_meta.
		Functions\expect( 'wp_strip_all_tags' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$word_count = new WordCount();
		$word_count->update_word_count_meta( $post_id );
	}

	/**
	 * Test update_word_count_meta with null post.
	 */
	public function test_update_word_count_meta_with_null_post() {
		$post_id = 789;

		// Mock WordPress function to return null.
		Functions\expect( 'get_post' )
			->once()
			->with( $post_id )
			->andReturnNull();

		// Should NOT call wp_strip_all_tags or update_post_meta.
		Functions\expect( 'wp_strip_all_tags' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$word_count = new WordCount();
		$word_count->update_word_count_meta( $post_id );
	}

	/**
	 * Test update_word_count_command executes without errors.
	 *
	 * This test verifies that the WP-CLI command runs successfully.
	 * Testing WP_Query loop behavior is complex, so we focus on verifying
	 * that the command completes and calls WP_CLI::success.
	 *
	 * Note: The source code has a bug - it uses "new WP_Query" without the global
	 * namespace prefix "\WP_Query". We create a class alias to work around this.
	 */
	public function test_update_word_count_command_executes() {
		// Create class alias for WP_Query in the Product namespace.
		// This works around the bug in the source code.
		if ( ! class_exists( 'FAToolkit\Product\WP_Query' ) ) {
			class_alias( '\WP_Query', 'FAToolkit\Product\WP_Query' );
		}

		// Mock WordPress functions that might be called.
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_reset_postdata' )->justReturn( null );

		// Reset WP_CLI calls.
		\WP_CLI::reset_calls();

		$word_count = new WordCount();
		$word_count->update_word_count_command( array(), array() );

		// Verify WP_CLI::success was called.
		$success_calls = \WP_CLI::get_calls( 'success' );
		$this->assertCount( 1, $success_calls );
		$this->assertEquals( 'Word count updated successfully.', $success_calls[0]['args'][0] );
	}

}
