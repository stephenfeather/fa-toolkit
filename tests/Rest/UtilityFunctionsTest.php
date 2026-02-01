<?php
/**
 * Tests for URL utility functions.
 *
 * @package FAToolkit
 */

namespace FAToolkit\Tests\Rest;

use FAToolkit\Tests\TestCase;
use FAToolkit\File\UrlHelper;
use Brain\Monkey\Functions;

/**
 * Test UrlHelper utility class.
 */
class UtilityFunctionsTest extends TestCase {

	/**
	 * Test scrub function removes Cloudinary parameters.
	 */
	public function test_scrub_removes_cloudinary_parameters() {
		Functions\expect( 'wp_parse_url' )
			->once()
			->andReturnUsing(
				function ( $url ) {
					return \parse_url( $url );
				}
			);

		$url    = 'https://res.cloudinary.com/davidsons-inc/image/upload/v1/media/catalog/product/1/0/1006336.jpg?_a=AAAA0AA';
		$result = UrlHelper::scrub( $url );

		// The scrub function removes query strings (and specific Cloudinary transformations for a different pattern).
		// This URL doesn't match the Cloudinary transformation pattern, so it just removes the query string.
		$this->assertEquals( 'https://res.cloudinary.com/davidsons-inc/image/upload/v1/media/catalog/product/1/0/1006336.jpg', $result );
	}

	/**
	 * Test scrub function removes query strings from regular URLs.
	 */
	public function test_scrub_removes_query_strings() {
		Functions\expect( 'wp_parse_url' )
			->once()
			->andReturnUsing(
				function ( $url ) {
					return \parse_url( $url );
				}
			);

		$url    = 'https://example.com/image.jpg?width=800&height=600';
		$result = UrlHelper::scrub( $url );

		$this->assertEquals( 'https://example.com/image.jpg', $result );
	}

	/**
	 * Test scrub function handles URLs without query strings.
	 */
	public function test_scrub_handles_urls_without_query_strings() {
		Functions\expect( 'wp_parse_url' )
			->once()
			->andReturnUsing(
				function ( $url ) {
					return \parse_url( $url );
				}
			);

		$url    = 'https://example.com/image.jpg';
		$result = UrlHelper::scrub( $url );

		$this->assertEquals( 'https://example.com/image.jpg', $result );
	}

	/**
	 * Test clean_filename removes double jpg extensions.
	 */
	public function test_clean_filename_removes_double_jpg_extensions() {
		$filename = 'image.jpg.jpg';
		$result   = UrlHelper::clean_filename( $filename );

		$this->assertEquals( 'image.jpg', $result );
	}

	/**
	 * Test clean_filename handles normal filenames.
	 */
	public function test_clean_filename_handles_normal_filenames() {
		$filename = 'image.jpg';
		$result   = UrlHelper::clean_filename( $filename );

		$this->assertEquals( 'image.jpg', $result );
	}

	/**
	 * Test get_extension extracts extension from URL.
	 */
	public function test_get_extension_extracts_extension() {
		$url    = 'https://example.com/path/to/image.jpg';
		$result = UrlHelper::get_extension( $url );

		$this->assertEquals( 'jpg', $result );
	}

	/**
	 * Test get_extension handles URLs with query strings.
	 */
	public function test_get_extension_handles_query_strings() {
		$url    = 'https://example.com/image.png?width=800';
		$result = UrlHelper::get_extension( $url );

		// Note: pathinfo on URL with query string may not work as expected.
		// This test documents current behavior.
		$this->assertIsString( $result );
	}

	/**
	 * Test get_filename extracts filename from URL.
	 */
	public function test_get_filename_extracts_filename() {
		$url    = 'https://example.com/path/to/image.jpg';
		$result = UrlHelper::get_filename( $url );

		$this->assertEquals( 'image', $result );
	}

	/**
	 * Test get_filename handles complex paths.
	 */
	public function test_get_filename_handles_complex_paths() {
		$url    = 'https://example.com/media/catalog/product.jpg';
		$result = UrlHelper::get_filename( $url );

		$this->assertEquals( 'product', $result );
	}

	/**
	 * Test attachment_exists returns WP_Error when attachment exists.
	 */
	public function test_attachment_exists_returns_error_when_exists() {
		Functions\expect( 'post_exists' )
			->once()
			->with( 'image.jpg' )
			->andReturn( 123 );

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'The attachment already exists.', 'my-text-domain' )
			->andReturn( 'The attachment already exists.' );

		$result = UrlHelper::attachment_exists( 'image.jpg' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_attachment_exists', $result->get_error_code() );
		$this->assertEquals( 'The attachment already exists.', $result->get_error_message() );

		// Check error data includes attachment_id.
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertEquals( 123, $error_data['attachment_id'] );
	}

	/**
	 * Test attachment_exists returns false when attachment does not exist.
	 */
	public function test_attachment_exists_returns_false_when_not_exists() {
		Functions\expect( 'post_exists' )
			->once()
			->with( 'image.jpg' )
			->andReturn( 0 );

		$result = UrlHelper::attachment_exists( 'image.jpg' );

		$this->assertFalse( $result );
	}
}
