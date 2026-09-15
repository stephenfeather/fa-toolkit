<?php
/**
 * Tests for RemoteAttachmentMetadata.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\RemoteAttachmentMetadata;
use FAToolkit\Tests\TestCase;

/**
 * RemoteAttachmentMetadata: the attachment metadata WordPress needs before it
 * will treat a pointer attachment as an image. Shared by product pointers and
 * brand logos, so the two cannot drift.
 */
class RemoteAttachmentMetadataTest extends TestCase {

	/**
	 * Stub the two URL helpers the builder uses.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			array(
				'thumbnail' => array( 'width' => 150, 'height' => 150, 'crop' => true ),
				'medium'    => array( 'width' => 300, 'height' => 300, 'crop' => false ),
			)
		);
	}

	/**
	 * Mime type follows the extension, case-insensitively, and falls back to jpeg.
	 */
	public function test_mime_type_follows_the_extension() {
		$this->assertSame( 'image/png', RemoteAttachmentMetadata::mime_type( 'https://cdn.test/a/Logo.PNG?tr=w-10' ) );
		$this->assertSame( 'image/webp', RemoteAttachmentMetadata::mime_type( 'https://cdn.test/a.webp' ) );
		$this->assertSame( 'image/jpeg', RemoteAttachmentMetadata::mime_type( 'https://cdn.test/a' ) );
	}

	/**
	 * The metadata file is the attached file given; sizes name the basename.
	 */
	public function test_metadata_uses_the_given_attached_file_and_basename_sizes() {
		$meta = RemoteAttachmentMetadata::build( 'https://cdn.test/files/product_brands/Glock-Logo.png', 400, 200, 'fa-remote/product_brands/Glock-Logo.png' );

		$this->assertSame( 400, $meta['width'] );
		$this->assertSame( 200, $meta['height'] );
		$this->assertSame( 'fa-remote/product_brands/Glock-Logo.png', $meta['file'] );
		$this->assertNotSame( array(), $meta['sizes'] );

		foreach ( $meta['sizes'] as $size ) {
			$this->assertSame( 'Glock-Logo.png', $size['file'] );
			$this->assertSame( 'image/png', $size['mime-type'] );
			$this->assertLessThanOrEqual( 400, $size['width'] );
		}
	}

	/**
	 * An image smaller than every candidate still gets one size entry, so
	 * core's srcset guard clears.
	 */
	public function test_an_image_smaller_than_every_candidate_gets_one_full_size() {
		$meta = RemoteAttachmentMetadata::build( 'https://cdn.test/tiny.jpg', 20, 10, 'tiny.jpg' );

		$this->assertSame(
			array(
				'fa-full' => array(
					'file'      => 'tiny.jpg',
					'width'     => 20,
					'height'    => 10,
					'mime-type' => 'image/jpeg',
				),
			),
			$meta['sizes']
		);
	}
}
