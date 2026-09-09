<?php
/**
 * Tests for AutoAttachUploadedMedia class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoFileScopeInstantiation;
use FAToolkit\Media\AutoAttachUploadedMedia;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\CoversMethod;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test AutoAttachUploadedMedia functionality.
 */
#[CoversMethod( AutoAttachUploadedMedia::class, '__construct' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'parse_filename' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'find_product_by_hash' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'determine_image_type' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'set_as_featured_image' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'append_to_gallery' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'calculate_and_store_hash' )]
#[CoversMethod( AutoAttachUploadedMedia::class, 'process_uploaded_attachment' )]
class AutoAttachUploadedMediaTest extends TestCase {

	use AssertsNoFileScopeInstantiation;

	/**
	 * Instance of the class under test.
	 *
	 * @var AutoAttachUploadedMedia
	 */
	private $instance;

	/**
	 * The class file must not construct itself at include time.
	 *
	 * fa-toolkit.php:78 already instantiates this class. A second, file-scope
	 * construction registers the constructor's `add_attachment` callback twice
	 * — WordPress keys object-method callbacks on spl_object_hash(), so both
	 * registrations survive and every media upload runs
	 * process_uploaded_attachment twice.
	 *
	 * For the consequence of running twice, see
	 * test_second_run_reroutes_featured_image_into_the_gallery.
	 *
	 * Issue #18, row 1.
	 *
	 * @return void
	 */
	public function test_class_file_does_not_instantiate_at_file_scope() {
		$this->assertNoFileScopeInstantiation(
			dirname( __DIR__, 2 ) . '/src/Media/class-autoattachuploadedmedia.php'
		);
	}

	/**
	 * A second run routes the same attachment into the gallery as well.
	 *
	 * This documents WHY double registration is harmful here rather than merely
	 * wasteful, and it is a sharper consequence than "the handler runs twice".
	 *
	 * determine_image_type() is state-dependent
	 * (src/Media/class-autoattachuploadedmedia.php:170-178): an image numbered 0
	 * becomes 'featured' only while the product has no featured image. So for a
	 * `{hash}_0.jpg` upload onto a product with no thumbnail:
	 *
	 *   run 1 - no thumbnail yet  -> 'featured' -> set_post_thumbnail()
	 *   run 2 - thumbnail now set -> 'gallery'  -> append_to_gallery( same id )
	 *
	 * The one uploaded image ends up BOTH the featured image AND a gallery
	 * entry, which is visible on the product page. Note that the individual
	 * write methods are each idempotent — append_to_gallery() explicitly guards
	 * against duplicates at :213 — so the defect is not a repeated write. It is
	 * the branch flipping, because the first run changed the state the second
	 * run reads.
	 *
	 * Issue #18, row 1.
	 *
	 * @return void
	 */
	public function test_second_run_reroutes_featured_image_into_the_gallery() {
		$method = $this->get_private_method( 'determine_image_type' );

		// Run 1: product has no featured image yet.
		Functions\expect( 'has_post_thumbnail' )
			->once()
			->with( 123 )
			->andReturn( false );

		$this->assertSame( 'featured', $method->invoke( $this->instance, 123, 0 ) );

		// Run 2: the first run set the thumbnail, so the branch flips.
		Functions\expect( 'has_post_thumbnail' )
			->once()
			->with( 123 )
			->andReturn( true );

		$this->assertSame(
			'gallery',
			$method->invoke( $this->instance, 123, 0 ),
			'The second registration re-routes the same image into the gallery.'
		);
	}

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new AutoAttachUploadedMedia();
	}

	/**
	 * Test constructor registers the attachment hook as a bound callback.
	 *
	 * This test used to assert only that the class could be instantiated, on
	 * the stated grounds that "the constructor is already tested via global
	 * mocks in bootstrap". No such mocks exist: tests/bootstrap.php declares
	 * no Functions\when() stubs, and never usefully did (issue #34, PR #60).
	 * A constructor that registered nothing, or registered a bare string that
	 * WordPress would fatal on at dispatch, passed that version of the test.
	 *
	 * @return void
	 */
	public function test_constructor_registers_hook() {
		$captured = array();

		Actions\expectAdded( 'add_attachment' )->once()->whenHappen(
			function ( $callback, $priority, $accepted_args ) use ( &$captured ) {
				$captured = array( $callback, $priority, $accepted_args );
			}
		);

		$instance = new AutoAttachUploadedMedia();

		$this->assertSame(
			array( $instance, 'process_uploaded_attachment' ),
			$captured[0],
			'add_attachment must be bound to the instance that registered it.'
		);
		$this->assertIsCallable( $captured[0] );
		$this->assertSame( 10, $captured[1] );
		$this->assertSame( 1, $captured[2] );
	}

	/**
	 * Test parse_filename with valid pattern.
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
	 */
	public function test_determine_image_type_gallery_non_zero() {
		$method = $this->get_private_method( 'determine_image_type' );

		// Should not call has_post_thumbnail for non-zero number.
		$result = $method->invoke( $this->instance, 123, 1 );
		$this->assertEquals( 'gallery', $result );
	}

	/**
	 * Test set_as_featured_image success.
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
		return $method;
	}
}
