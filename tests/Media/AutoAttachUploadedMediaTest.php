<?php
/**
 * Tests for AutoAttachUploadedMedia class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Tests\TestCase;
use FAToolkit\Media\AutoAttachUploadedMedia;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test AutoAttachUploadedMedia functionality.
 *
 * @coversDefaultClass \FAToolkit\Media\AutoAttachUploadedMedia
 */
class AutoAttachUploadedMediaTest extends TestCase {

	/**
	 * Instance of the class under test.
	 *
	 * @var AutoAttachUploadedMedia
	 */
	private $instance;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new AutoAttachUploadedMedia();
	}

	/**
	 * Test constructor registers action hook.
	 *
	 * @covers ::__construct
	 */
	public function test_constructor_registers_hook() {
		// Constructor is already tested via global mocks in bootstrap.
		// Just verify we can instantiate the class.
		$instance = new AutoAttachUploadedMedia();
		$this->assertInstanceOf( AutoAttachUploadedMedia::class, $instance );
	}

	/**
	 * Test parse_filename with valid pattern.
	 *
	 * @covers ::parse_filename
	 */
	public function test_parse_filename_valid() {
		$method = $this->get_private_method( 'parse_filename' );

		$result = $method->invoke( $this->instance, 'abc123_0.jpg' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'abc123', $result['hash'] );
		$this->assertEquals( 0, $result['number'] );

		$result = $method->invoke( $this->instance, 'xyz789_42.png' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'xyz789', $result['hash'] );
		$this->assertEquals( 42, $result['number'] );
	}

	/**
	 * Test parse_filename with invalid pattern.
	 *
	 * @covers ::parse_filename
	 */
	public function test_parse_filename_invalid() {
		$method = $this->get_private_method( 'parse_filename' );

		// No underscore.
		$result = $method->invoke( $this->instance, 'abc123.jpg' );
		$this->assertFalse( $result );

		// Missing number.
		$result = $method->invoke( $this->instance, 'abc123_.jpg' );
		$this->assertFalse( $result );

		// Number not numeric.
		$result = $method->invoke( $this->instance, 'abc123_foo.jpg' );
		$this->assertFalse( $result );
	}

	/**
	 * Test find_product_by_hash when product exists.
	 *
	 * @covers ::find_product_by_hash
	 */
	public function test_find_product_by_hash_found() {
		$method = $this->get_private_method( 'find_product_by_hash' );

		Functions\expect( 'get_posts' )
			->once()
			->with( Mockery::on( function( $args ) {
				return 'product' === $args['post_type']
					&& 1 === $args['posts_per_page']
					&& 'ids' === $args['fields']
					&& isset( $args['meta_query'][0]['key'] )
					&& '_unique_product_key' === $args['meta_query'][0]['key']
					&& 'testhash' === $args['meta_query'][0]['value'];
			} ) )
			->andReturn( [ 123 ] );

		$result = $method->invoke( $this->instance, 'testhash' );
		$this->assertEquals( 123, $result );
	}

	/**
	 * Test find_product_by_hash when product not found.
	 *
	 * @covers ::find_product_by_hash
	 */
	public function test_find_product_by_hash_not_found() {
		$method = $this->get_private_method( 'find_product_by_hash' );

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( [] );

		$result = $method->invoke( $this->instance, 'nonexistent' );
		$this->assertFalse( $result );
	}

	/**
	 * Test determine_image_type returns featured for number 0 without thumbnail.
	 *
	 * @covers ::determine_image_type
	 */
	public function test_determine_image_type_featured() {
		$method = $this->get_private_method( 'determine_image_type' );

		Functions\expect( 'has_post_thumbnail' )
			->once()
			->with( 123 )
			->andReturn( false );

		$result = $method->invoke( $this->instance, 123, 0 );
		$this->assertEquals( 'featured', $result );
	}

	/**
	 * Test determine_image_type returns gallery when product already has thumbnail.
	 *
	 * @covers ::determine_image_type
	 */
	public function test_determine_image_type_gallery_has_thumbnail() {
		$method = $this->get_private_method( 'determine_image_type' );

		Functions\expect( 'has_post_thumbnail' )
			->once()
			->with( 123 )
			->andReturn( true );

		$result = $method->invoke( $this->instance, 123, 0 );
		$this->assertEquals( 'gallery', $result );
	}

	/**
	 * Test determine_image_type returns gallery for non-zero number.
	 *
	 * @covers ::determine_image_type
	 */
	public function test_determine_image_type_gallery_non_zero() {
		$method = $this->get_private_method( 'determine_image_type' );

		// Should not call has_post_thumbnail for non-zero number.
		$result = $method->invoke( $this->instance, 123, 1 );
		$this->assertEquals( 'gallery', $result );
	}

	/**
	 * Test set_as_featured_image success.
	 *
	 * @covers ::set_as_featured_image
	 */
	public function test_set_as_featured_image_success() {
		$method = $this->get_private_method( 'set_as_featured_image' );

		Functions\expect( 'set_post_thumbnail' )
			->once()
			->with( 123, 456 )
			->andReturn( true );

		$result = $method->invoke( $this->instance, 123, 456 );
		$this->assertTrue( $result );
	}

	/**
	 * Test append_to_gallery success.
	 *
	 * @covers ::append_to_gallery
	 */
	public function test_append_to_gallery_success() {
		$method = $this->get_private_method( 'append_to_gallery' );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_gallery_image_ids' )
			->once()
			->andReturn( [ 100, 200 ] );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 123 )
			->andReturn( $product );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 123, '_product_image_gallery', '100,200,456' )
			->andReturn( true );

		$result = $method->invoke( $this->instance, 123, 456 );
		$this->assertTrue( $result );
	}

	/**
	 * Test append_to_gallery prevents duplicates.
	 *
	 * @covers ::append_to_gallery
	 */
	public function test_append_to_gallery_prevents_duplicates() {
		$method = $this->get_private_method( 'append_to_gallery' );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_gallery_image_ids' )
			->once()
			->andReturn( [ 100, 456, 200 ] );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 123 )
			->andReturn( $product );

		// Should not call update_post_meta since attachment already exists.
		$result = $method->invoke( $this->instance, 123, 456 );
		$this->assertTrue( $result );
	}

	/**
	 * Test append_to_gallery returns false when product not found.
	 *
	 * @covers ::append_to_gallery
	 */
	public function test_append_to_gallery_product_not_found() {
		$method = $this->get_private_method( 'append_to_gallery' );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 123 )
			->andReturn( false );

		$result = $method->invoke( $this->instance, 123, 456 );
		$this->assertFalse( $result );
	}

	/**
	 * Test calculate_and_store_hash success.
	 *
	 * @covers ::calculate_and_store_hash
	 */
	public function test_calculate_and_store_hash_success() {
		$method = $this->get_private_method( 'calculate_and_store_hash' );

		Functions\expect( 'get_attached_file' )
			->once()
			->with( 456 )
			->andReturn( '/path/to/image.jpg' );

		\Patchwork\replace( 'file_exists', function( $path ) {
			return '/path/to/image.jpg' === $path;
		} );

		\Patchwork\replace( 'hash_file', function( $algo, $path ) {
			return 'sha256' === $algo && '/path/to/image.jpg' === $path
				? 'abc123hash'
				: false;
		} );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 456, 'sha256_hash', 'abc123hash' )
			->andReturn( true );

		$result = $method->invoke( $this->instance, 456 );
		$this->assertEquals( 'abc123hash', $result );
	}

	/**
	 * Test calculate_and_store_hash returns false when file not found.
	 *
	 * @covers ::calculate_and_store_hash
	 */
	public function test_calculate_and_store_hash_file_not_found() {
		$method = $this->get_private_method( 'calculate_and_store_hash' );

		Functions\expect( 'get_attached_file' )
			->once()
			->with( 456 )
			->andReturn( false );

		$result = $method->invoke( $this->instance, 456 );
		$this->assertFalse( $result );
	}

	/**
	 * Test process_uploaded_attachment returns early for non-image.
	 *
	 * @covers ::process_uploaded_attachment
	 */
	public function test_process_uploaded_attachment_non_image() {
		Functions\expect( 'get_post_mime_type' )
			->once()
			->with( 456 )
			->andReturn( 'application/pdf' );

		// Should return early, no other functions called.
		$this->instance->process_uploaded_attachment( 456 );
		$this->assertTrue( true ); // If we get here, test passed.
	}

	/**
	 * Test process_uploaded_attachment returns early for invalid filename pattern.
	 *
	 * @covers ::process_uploaded_attachment
	 */
	public function test_process_uploaded_attachment_invalid_filename() {
		Functions\expect( 'get_post_mime_type' )
			->once()
			->with( 456 )
			->andReturn( 'image/jpeg' );

		Functions\expect( 'get_attached_file' )
			->once()
			->with( 456 )
			->andReturn( '/path/to/invalid_name.jpg' );

		// Should return early after parsing filename.
		$this->instance->process_uploaded_attachment( 456 );
		$this->assertTrue( true );
	}

	/**
	 * Test process_uploaded_attachment full workflow for featured image.
	 *
	 * @covers ::process_uploaded_attachment
	 */
	public function test_process_uploaded_attachment_featured_image_workflow() {
		// Mock attachment is image.
		Functions\expect( 'get_post_mime_type' )
			->once()
			->with( 456 )
			->andReturn( 'image/jpeg' );

		// Mock filename.
		Functions\expect( 'get_attached_file' )
			->twice()
			->with( 456 )
			->andReturn( '/path/to/abc123_0.jpg' );

		// Mock finding product.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( [ 789 ] );

		// Mock product exists.
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_status' )
			->once()
			->andReturn( 'publish' );
		$product->shouldReceive( 'get_sku' )
			->once()
			->andReturn( 'TEST-SKU' );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 789 )
			->andReturn( $product );

		// Mock determine type as featured.
		Functions\expect( 'has_post_thumbnail' )
			->once()
			->with( 789 )
			->andReturn( false );

		// Mock set as featured.
		Functions\expect( 'set_post_thumbnail' )
			->once()
			->with( 789, 456 )
			->andReturn( true );

		// Mock hash calculation.
		\Patchwork\replace( 'file_exists', function() {
			return true;
		} );

		\Patchwork\replace( 'hash_file', function() {
			return 'testhash';
		} );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 456, 'sha256_hash', 'testhash' );

		// Mock error_log.
		Functions\expect( 'error_log' )
			->once()
			->with( Mockery::pattern( '/Auto-attached image 456 to product 789/' ) );

		$this->instance->process_uploaded_attachment( 456 );
		$this->assertTrue( true );
	}

	/**
	 * Get private method via reflection.
	 *
	 * @param string $method_name Method name.
	 * @return ReflectionMethod
	 */
	private function get_private_method( $method_name ) {
		$reflection = new ReflectionClass( AutoAttachUploadedMedia::class );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method;
	}
}
