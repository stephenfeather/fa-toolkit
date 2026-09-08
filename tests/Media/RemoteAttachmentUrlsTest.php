<?php
/**
 * Tests for RemoteAttachmentUrls.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\RemoteAttachmentUrls;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Test case for RemoteAttachmentUrls.
 *
 * These attachments have no file on disk — s3 is the system of record — so
 * every URL WordPress would normally derive from a local path has to be
 * answered by a filter instead.
 */
class RemoteAttachmentUrlsTest extends TestCase {

	private const URL = 'https://ik.imagekit.io/featherarms/s3/files/Products/x/y.jpg';

	/**
	 * Stub get_post_meta for one attachment.
	 *
	 * @param int         $id     Attachment id.
	 * @param string      $url    Remote url, or '' for none.
	 * @param int|string  $width  Stored width.
	 * @param int|string  $height Stored height.
	 * @return void
	 */
	private function stub_meta( $id, $url, $width = '', $height = '' ) {
		Functions\when( 'wp_basename' )->alias(
			function ( $path ) {
				return basename( parse_url( $path, PHP_URL_PATH ) ?? $path );
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) use ( $id, $url, $width, $height ) {
				if ( $post_id !== $id ) {
					return '';
				}
				$map = array(
					'_fa_remote_url'    => $url,
					'_fa_remote_width'  => $width,
					'_fa_remote_height' => $height,
				);
				return $map[ $key ] ?? '';
			}
		);
	}

	/**
	 * Test that the constructor registers all four filters as bound callbacks.
	 *
	 * Four, not three. The Store API calls wp_get_attachment_image_sizes()
	 * alongside wp_get_attachment_image_srcset() when building product image
	 * responses, and both read attachment metadata these attachments do not
	 * have. Missing the sizes filter ships empty sizes attributes on every
	 * product image, which nothing fails on and nobody notices for weeks.
	 *
	 * @return void
	 */
	public function test_constructor_registers_all_four_filters() {
		$captured = array();

		foreach ( array( 'wp_get_attachment_url', 'image_downsize', 'wp_calculate_image_srcset', 'wp_calculate_image_sizes' ) as $hook ) {
			Filters\expectAdded( $hook )
				->once()
				->whenHappen(
					function ( $callback ) use ( &$captured, $hook ) {
						$captured[ $hook ] = $callback;
					}
				);
		}

		$urls = new RemoteAttachmentUrls();

		$this->assertCount( 4, $captured );
		foreach ( $captured as $hook => $callback ) {
			$this->assertIsArray( $callback, "Callback for {$hook} must be a bound array callback." );
			$this->assertSame( $urls, $callback[0], "Callback for {$hook} is bound to the wrong instance." );
			$this->assertIsCallable( $callback );
		}
	}

	/**
	 * Test that a remote attachment's url is replaced.
	 *
	 * @return void
	 */
	public function test_attachment_url_returns_the_remote_url() {
		$this->stub_meta( 7, self::URL );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( self::URL, $urls->attachment_url( 'http://local/wp-content/uploads/y.jpg', 7 ) );
	}

	/**
	 * Test that a normal local attachment is left alone.
	 *
	 * The filters run on every attachment in the site, not only ours, so not
	 * touching an ordinary upload is the more important half of the contract.
	 *
	 * @return void
	 */
	public function test_local_attachment_url_is_untouched() {
		$this->stub_meta( 7, '' );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( 'http://local/uploads/y.jpg', $urls->attachment_url( 'http://local/uploads/y.jpg', 7 ) );
	}

	/**
	 * Test that a known-size image downsizes through an ImageKit transform.
	 *
	 * @return void
	 */
	public function test_downsize_uses_a_transform_when_dimensions_are_known() {
		$this->stub_meta( 7, self::URL, 1000, 500 );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			array( 'medium' => array( 'width' => 300, 'height' => 300, 'crop' => false ) )
		);

		$urls = new RemoteAttachmentUrls();

		$result = $urls->downsize( false, 7, 'medium' );

		$this->assertIsArray( $result );
		$this->assertSame( self::URL . '?tr=w-300', $result[0] );
		$this->assertSame( 300, $result[1] );
		// 1000x500 constrained to width 300 is 150 tall. A wrong height here
		// is worse than none: browsers derive aspect-ratio from these and a
		// bad ratio distorts the image as well as shifting the layout.
		$this->assertSame( 150, $result[2] );
		$this->assertTrue( $result[3] );
	}

	/**
	 * Test that an unknown-size image declines to downsize rather than guess.
	 *
	 * Dimensions are absent for 76% of images until the fetcher captures them.
	 * Returning false means WordPress serves the original URL with no width or
	 * height attributes: heavier, and it shifts layout, but it is honest.
	 * Fabricating a height would distort the image as well.
	 *
	 * @return void
	 */
	public function test_downsize_declines_when_dimensions_are_unknown() {
		$this->stub_meta( 7, self::URL );

		$urls = new RemoteAttachmentUrls();

		$this->assertFalse( $urls->downsize( false, 7, 'medium' ) );
	}

	/**
	 * Test that downsize ignores attachments that are not ours.
	 *
	 * @return void
	 */
	public function test_downsize_ignores_local_attachments() {
		$this->stub_meta( 7, '' );

		$urls = new RemoteAttachmentUrls();

		$this->assertFalse( $urls->downsize( false, 7, 'medium' ) );
	}

	/**
	 * Test that srcset candidates are generated from transforms.
	 *
	 * @return void
	 */
	public function test_srcset_is_built_from_transforms() {
		$this->stub_meta( 7, self::URL, 1200, 600 );

		$urls = new RemoteAttachmentUrls();

		$sources = $urls->srcset( array(), array(), '', array(), 7 );

		$this->assertNotEmpty( $sources );
		foreach ( $sources as $width => $source ) {
			$this->assertSame( $width, $source['value'] );
			$this->assertSame( 'w', $source['descriptor'] );
			$this->assertStringContainsString( '?tr=w-' . $width, $source['url'] );
			// Never advertise a candidate wider than the original: the browser
			// would pick it and get an upscale.
			$this->assertLessThanOrEqual( 1200, $width );
		}
	}

	/**
	 * Test that srcset is empty when dimensions are unknown.
	 *
	 * Without the original width there is no way to know which candidates are
	 * upscales, and an upscaled candidate is worse than no srcset.
	 *
	 * @return void
	 */
	public function test_srcset_is_empty_without_known_width() {
		$this->stub_meta( 7, self::URL );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( array(), $urls->srcset( array(), array(), '', array(), 7 ) );
	}

	/**
	 * Test that srcset leaves local attachments alone.
	 *
	 * @return void
	 */
	public function test_srcset_passes_through_for_local_attachments() {
		$this->stub_meta( 7, '' );
		$existing = array( 300 => array( 'url' => 'x', 'descriptor' => 'w', 'value' => 300 ) );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( $existing, $urls->srcset( $existing, array(), '', array(), 7 ) );
	}

	/**
	 * Test that a sizes attribute is produced for remote attachments.
	 *
	 * @return void
	 */
	public function test_sizes_is_generated_for_remote_attachments() {
		$this->stub_meta( 7, self::URL, 1200, 600 );

		$urls = new RemoteAttachmentUrls();

		$sizes = $urls->sizes( '', array( 600, 300 ), '', array(), 7 );

		$this->assertStringContainsString( '600px', $sizes );
	}

	/**
	 * Test that sizes leaves local attachments alone.
	 *
	 * @return void
	 */
	public function test_sizes_passes_through_for_local_attachments() {
		$this->stub_meta( 7, '' );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( '(max-width: 99px) 100vw, 99px', $urls->sizes( '(max-width: 99px) 100vw, 99px', array( 99, 99 ), '', array(), 7 ) );
	}

	/**
	 * Test that the metadata filter is registered.
	 *
	 * This is the one that makes the srcset and sizes filters reachable at all.
	 * Core bails out of wp_calculate_image_srcset() when $image_meta has no
	 * 'sizes'/'file', and out of wp_calculate_image_sizes() when a named size
	 * resolves to no width — both BEFORE applying their own filters. Without
	 * synthetic metadata those two callbacks are dead in production while
	 * passing every direct-invocation test.
	 *
	 * @return void
	 */
	public function test_registers_the_metadata_filters() {
		$captured = array();

		foreach ( array( 'wp_get_attachment_metadata', 'wp_calculate_image_srcset_meta' ) as $hook ) {
			Filters\expectAdded( $hook )
				->once()
				->whenHappen(
					function ( $callback ) use ( &$captured, $hook ) {
						$captured[ $hook ] = $callback;
					}
				);
		}

		$urls = new RemoteAttachmentUrls();

		$this->assertCount( 2, $captured );
		foreach ( $captured as $hook => $callback ) {
			$this->assertSame( $urls, $callback[0], "Callback for {$hook} is bound to the wrong instance." );
		}
	}

	/**
	 * Test that synthetic metadata clears core's guards.
	 *
	 * @return void
	 */
	public function test_synthetic_metadata_satisfies_cores_guards() {
		$this->stub_meta( 7, self::URL, 1200, 600 );

		$urls = new RemoteAttachmentUrls();
		$meta = $urls->attachment_metadata( false, 7 );

		$this->assertIsArray( $meta );
		// The two conditions wp_calculate_image_srcset() tests before it will
		// proceed far enough to apply its filter.
		$this->assertNotEmpty( $meta['sizes'] );
		$this->assertArrayHasKey( 'file', $meta );
		$this->assertGreaterThanOrEqual( 4, strlen( $meta['file'] ) );
		$this->assertSame( 1200, $meta['width'] );
		$this->assertSame( 600, $meta['height'] );
	}

	/**
	 * Test that a small original still yields metadata that clears the guard.
	 *
	 * @return void
	 */
	public function test_small_image_still_yields_non_empty_sizes() {
		$this->stub_meta( 7, self::URL, 80, 80 );

		$urls = new RemoteAttachmentUrls();
		$meta = $urls->attachment_metadata( false, 7 );

		$this->assertNotEmpty( $meta['sizes'], 'An image smaller than every candidate must still clear the guard.' );
	}

	/**
	 * Test that metadata is not invented for local attachments.
	 *
	 * @return void
	 */
	public function test_metadata_untouched_for_local_attachments() {
		$this->stub_meta( 7, '' );

		$urls = new RemoteAttachmentUrls();

		$this->assertFalse( $urls->attachment_metadata( false, 7 ) );
	}

	/**
	 * Test that metadata is not invented when dimensions are unknown.
	 *
	 * @return void
	 */
	public function test_metadata_untouched_without_dimensions() {
		$this->stub_meta( 7, self::URL );

		$urls = new RemoteAttachmentUrls();

		$this->assertFalse( $urls->attachment_metadata( false, 7 ) );
	}

	/**
	 * Test that srcset_meta fills in for callers that pass metadata directly.
	 *
	 * @return void
	 */
	public function test_srcset_meta_supplies_missing_metadata() {
		$this->stub_meta( 7, self::URL, 1200, 600 );

		$urls = new RemoteAttachmentUrls();
		$meta = $urls->srcset_meta( array(), array( 600, 300 ), self::URL, 7 );

		$this->assertNotEmpty( $meta['sizes'] );
	}

	/**
	 * Test that srcset_meta leaves real metadata alone.
	 *
	 * @return void
	 */
	public function test_srcset_meta_leaves_real_metadata_alone() {
		$this->stub_meta( 7, self::URL, 1200, 600 );
		$real = array( 'file' => 'real.jpg', 'sizes' => array( 'medium' => array( 'width' => 300 ) ) );

		$urls = new RemoteAttachmentUrls();

		$this->assertSame( $real, $urls->srcset_meta( $real, array( 600, 300 ), self::URL, 7 ) );
	}

	/**
	 * Test that a url already carrying a query string is not corrupted.
	 *
	 * Appending "?tr=" to a url that already has "?" produces two of them and
	 * a 4xx, which would read as a dead image rather than a bad request.
	 *
	 * @return void
	 */
	public function test_transform_respects_an_existing_query_string() {
		$url = 'https://cdn.test/a.jpg?v=2';
		$this->stub_meta( 7, $url, 1000, 500 );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			array( 'medium' => array( 'width' => 300, 'height' => 300, 'crop' => false ) )
		);

		$urls   = new RemoteAttachmentUrls();
		$result = $urls->downsize( false, 7, 'medium' );

		$this->assertSame( $url . '&tr=w-300', $result[0] );
		$this->assertSame( 1, substr_count( $result[0], '?' ) );
	}

	/**
	 * Test that a named size produces a sizes attribute.
	 *
	 * WordPress passes named sizes as strings here, not only arrays. Handling
	 * arrays alone reintroduces the empty sizes attribute this class exists to
	 * prevent.
	 *
	 * @return void
	 */
	public function test_sizes_handles_a_named_size() {
		$this->stub_meta( 7, self::URL, 1200, 600 );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			array( 'woocommerce_thumbnail' => array( 'width' => 324, 'height' => 324, 'crop' => true ) )
		);

		$urls = new RemoteAttachmentUrls();

		$this->assertStringContainsString( '324px', $urls->sizes( '', 'woocommerce_thumbnail', '', array(), 7 ) );
	}
}
