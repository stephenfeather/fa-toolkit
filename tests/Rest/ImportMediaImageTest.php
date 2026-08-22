<?php
/**
 * Tests for ImportMediaImage class and related utility functions.
 *
 * @package FAToolkit
 */

namespace FAToolkit\Tests\Rest;

use FAToolkit\Rest\ImportMediaImage;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test ImportMediaImage REST API endpoint.
 */
class ImportMediaImageTest extends TestCase {

	/**
	 * Test that constructor registers the REST API route.
	 *
	 * Note: Cannot test constructor directly because the class is auto-instantiated
	 * at the bottom of the file and constructor runs during class loading.
	 */
	public function test_constructor_registers_route() {
		// Create a fresh instance to test registration.
		Functions\expect( 'add_action' )
			->once()
			->with( 'rest_api_init', Mockery::type( 'array' ) )
			->andReturn( true );

		$instance = new ImportMediaImage();
		$this->assertInstanceOf( ImportMediaImage::class, $instance );
	}

	/**
	 * Test register_route method.
	 */
	public function test_register_route_calls_register_rest_route() {
		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				'fa-toolkit/v1',
				'/import-media-image/',
				Mockery::type( 'array' )
			)
			->andReturn( true );

		$instance = new ImportMediaImage();
		$instance->register_route();
	}

	/**
	 * Test permission callback returns true for users with upload_files capability.
	 */
	public function test_import_media_image_permission_allows_upload_files() {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'upload_files' )
			->andReturn( true );

		$instance = new ImportMediaImage();
		$result   = $instance->import_media_image_permission();

		$this->assertTrue( $result );
	}

	/**
	 * Test permission callback returns WP_Error for users without upload_files capability.
	 */
	public function test_import_media_image_permission_denies_without_upload_files() {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'upload_files' )
			->andReturn( false );

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'Your are not permitted to upload files.', 'my-text-domain' )
			->andReturn( 'Your are not permitted to upload files.' );

		$instance = new ImportMediaImage();
		$result   = $instance->import_media_image_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
		$this->assertEquals( 'Your are not permitted to upload files.', $result->get_error_message() );
	}

	/**
	 * Test import_media_image returns error for invalid URL.
	 */
	public function test_import_media_image_rejects_invalid_url() {
		Functions\when( 'filter_var' )->alias(
			function ( $value, $filter ) {
				if ( $filter === FILTER_VALIDATE_URL ) {
					return strpos( $value, 'http' ) === 0;
				}
				return $value;
			}
		);

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'The url provided is not valid.', 'my-text-domain' )
			->andReturn( 'The url provided is not valid.' );

		$request = new \WP_REST_Request( array( 'url' => 'not-a-valid-url' ) );

		$instance = new ImportMediaImage();
		$result   = $instance->import_media_image( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test import_media_image scrubs URL and checks for existing attachment.
	 */
	public function test_import_media_image_checks_existing_attachment() {
		Functions\when( 'filter_var' )->alias(
			function ( $value, $filter ) {
				if ( $filter === FILTER_VALIDATE_URL ) {
					return strpos( $value, 'http' ) === 0;
				}
				return $value;
			}
		);

		Functions\expect( 'esc_url_raw' )
			->once()
			->with( 'https://example.com/image.jpg' )
			->andReturn( 'https://example.com/image.jpg' );

		// scrub() and attachment_exists() used to be free functions in the
		// FAToolkit\Rest namespace. They are now static methods on
		// FAToolkit\File\UrlHelper, so the old Functions\expect() mocks matched
		// nothing. Drive the real UrlHelper instead and stub what it calls.
		Functions\expect( 'wp_parse_url' )
			->once()
			->with( 'https://example.com/image.jpg' )
			->andReturn(
				array(
					'scheme' => 'https',
					'host'   => 'example.com',
					'path'   => '/image.jpg',
				)
			);

		Functions\expect( 'post_exists' )
			->once()
			->with( 'image.jpg' )
			->andReturn( 42 );

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'The attachment already exists.', 'my-text-domain' )
			->andReturn( 'The attachment already exists.' );

		$request = new \WP_REST_Request( array( 'url' => 'https://example.com/image.jpg' ) );

		$instance = new ImportMediaImage();
		$result   = $instance->import_media_image( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_attachment_exists', $result->get_error_code() );
	}

	/**
	 * Test download_media method handles download failure.
	 */
	public function test_download_media_handles_download_failure() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/image.jpg' )
			->andReturn( new \WP_Error( 'http_error', 'Connection failed' ) );

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'The download failed.', 'my-text-domain' )
			->andReturn( 'The download failed.' );

		$instance = new ImportMediaImage();
		$method   = new \ReflectionMethod( ImportMediaImage::class, 'download_media' );

		$result = $method->invoke( $instance, 'https://example.com/image.jpg' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_download_failed', $result->get_error_code() );
	}

	/**
	 * Test download_media method handles successful download.
	 */
	public function test_download_media_handles_successful_download() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/image.jpg' )
			->andReturn( array( 'body' => 'fake-image-data' ) );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_upload_dir' )
			->once()
			->andReturn( array( 'path' => '/var/www/uploads' ) );

		// clean_filename() moved from a FAToolkit\Rest free function to a
		// UrlHelper static method. The real one is a pure str_replace, so it
		// needs no stub — the old Functions\expect() matched nothing.
		Functions\expect( 'wp_upload_bits' )
			->once()
			->with( 'image.jpg', null, Mockery::any() )
			->andReturn(
				array(
					'file'  => '/var/www/uploads/image.jpg',
					'url'   => 'https://example.com/wp-content/uploads/image.jpg',
					'type'  => 'image/jpeg',
					'error' => false,
				)
			);

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( 'fake-image-data' );

		$instance = new ImportMediaImage();
		$method   = new \ReflectionMethod( ImportMediaImage::class, 'download_media' );

		$result = $method->invoke( $instance, 'https://example.com/image.jpg' );

		$this->assertIsArray( $result );
		$this->assertEquals( '/var/www/uploads/image.jpg', $result['file'] );
		$this->assertFalse( $result['error'] );
	}

	/**
	 * Test download_media method handles upload failure.
	 */
	public function test_download_media_handles_upload_failure() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( array( 'body' => 'fake-image-data' ) );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_upload_dir' )
			->once()
			->andReturn( array( 'path' => '/var/www/uploads' ) );

		// See the note above: UrlHelper::clean_filename() runs for real.
		Functions\expect( 'wp_upload_bits' )
			->once()
			->andReturn(
				array(
					'error' => 'Upload directory is not writable',
				)
			);

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( 'fake-image-data' );

		Functions\expect( 'esc_html__' )
			->once()
			->with( 'The upload failed.', 'my-text-domain' )
			->andReturn( 'The upload failed.' );

		$instance = new ImportMediaImage();
		$method   = new \ReflectionMethod( ImportMediaImage::class, 'download_media' );

		$result = $method->invoke( $instance, 'https://example.com/image.jpg' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rest_upload_failed', $result->get_error_code() );
	}

	/**
	 * Test save_optional_meta method with all parameters.
	 */
	public function test_save_optional_meta_with_all_parameters() {
		// Define ABSPATH if not already defined to avoid require_once error.
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/fake/path/' );
		}

		Functions\expect( 'wp_generate_attachment_metadata' )
			->once()
			->with( 123, '/var/www/uploads/image.jpg' )
			->andReturn( array( 'width' => 800, 'height' => 600 ) );

		Functions\expect( 'wp_update_attachment_metadata' )
			->once()
			->with( 123, Mockery::type( 'array' ) )
			->andReturn( true );

		Functions\expect( 'wp_update_post' )
			->twice()
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, '_wp_attachment_image_alt', 'Test caption' )
			->andReturn( true );

		$instance   = new ImportMediaImage();
		$method     = new \ReflectionMethod( ImportMediaImage::class, 'save_optional_meta' );

		$parameters = array(
			'title'       => 'Test Title',
			'caption'     => 'Test caption',
			'description' => 'Test description',
		);

		$file = array(
			'file' => '/var/www/uploads/image.jpg',
		);

		$result = $method->invoke( $instance, 123, $parameters, $file );
		$this->assertNull( $result ); // Method returns void.
	}

	/**
	 * Test save_optional_meta method without optional parameters.
	 */
	public function test_save_optional_meta_without_optional_parameters() {
		// Define ABSPATH if not already defined to avoid require_once error.
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/fake/path/' );
		}

		Functions\expect( 'wp_generate_attachment_metadata' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_update_attachment_metadata' )
			->once()
			->andReturn( true );

		// Should not call wp_update_post or update_post_meta if no optional params.
		Functions\expect( 'wp_update_post' )
			->never();

		Functions\expect( 'update_post_meta' )
			->never();

		$instance   = new ImportMediaImage();
		$method     = new \ReflectionMethod( ImportMediaImage::class, 'save_optional_meta' );

		$parameters = array(
			'title'       => null,
			'caption'     => null,
			'description' => null,
		);

		$file = array(
			'file' => '/var/www/uploads/image.jpg',
		);

		$result = $method->invoke( $instance, 123, $parameters, $file );
		$this->assertNull( $result );
	}
}
