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
}
