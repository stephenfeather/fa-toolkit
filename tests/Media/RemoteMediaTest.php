<?php
/**
 * Tests for RemoteMedia.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\RemoteMedia;
use FAToolkit\Tests\TestCase;

/**
 * Test case for RemoteMedia.
 *
 * The contract under test is the `_fa_media` postmeta cell produced by the
 * Akeneo export. Measured against the real 71,430-row export, every entry
 * carries role/kind/sha256/title/url; `position` appears on gallery entries
 * and never on heroes; there is exactly one hero per product with media, and
 * zero products carrying images but no hero. Non-image entries (60 documents)
 * are present on purpose, so filtering on kind is the caller's job and this
 * class does it.
 */
class RemoteMediaTest extends TestCase {

	/**
	 * One hero and two gallery images, deliberately out of order.
	 *
	 * @return string
	 */
	private function fixture() {
		return wp_json_encode(
			array(
				array(
					'role'   => 'gallery',
					'kind'   => 'image',
					'url'    => 'https://cdn.test/b.jpg',
					'title'  => 'b.jpg',
					'sha256' => 'bbb',
					// Deliberately the higher position, listed first.
					'position' => 2,
				),
				array(
					'role'   => 'hero',
					'kind'   => 'image',
					'url'    => 'https://cdn.test/hero.jpg',
					'title'  => 'hero.jpg',
					'sha256' => 'aaa',
				),
				array(
					'role'     => 'gallery',
					'kind'     => 'image',
					'url'      => 'https://cdn.test/a.jpg',
					'title'    => 'a.jpg',
					'sha256'   => 'ccc',
					'position' => 1,
				),
			)
		);
	}

	/**
	 * Test that an empty cell yields nothing rather than an error.
	 *
	 * The export writes an EMPTY CELL, not "[]", for a product with no media,
	 * so the common case must be cheap and safe.
	 *
	 * @return void
	 */
	public function test_empty_cell_yields_no_entries() {
		$media = RemoteMedia::from_meta( '' );

		$this->assertFalse( $media->has_images() );
		$this->assertNull( $media->hero() );
		$this->assertSame( array(), $media->gallery() );
	}

	/**
	 * Test that malformed JSON is survived rather than fataled on.
	 *
	 * @return void
	 */
	public function test_malformed_json_yields_no_entries() {
		$media = RemoteMedia::from_meta( '{not json' );

		$this->assertFalse( $media->has_images() );
		$this->assertNull( $media->hero() );
	}

	/**
	 * Test that the hero is found by role, not by array position.
	 *
	 * @return void
	 */
	public function test_hero_is_selected_by_role_not_array_order() {
		$media = RemoteMedia::from_meta( $this->fixture() );

		$hero = $media->hero();

		$this->assertNotNull( $hero );
		$this->assertSame( 'https://cdn.test/hero.jpg', $hero['url'] );
		$this->assertSame( 'aaa', $hero['sha256'] );
	}

	/**
	 * Test that gallery entries come back ordered by position ascending.
	 *
	 * @return void
	 */
	public function test_gallery_is_ordered_by_position() {
		$media = RemoteMedia::from_meta( $this->fixture() );

		$urls = array_column( $media->gallery(), 'url' );

		$this->assertSame(
			array( 'https://cdn.test/a.jpg', 'https://cdn.test/b.jpg' ),
			$urls,
			'Gallery must sort by position, not by the order in the JSON.'
		);
	}

	/**
	 * Test that the hero is excluded from the gallery.
	 *
	 * @return void
	 */
	public function test_hero_is_not_repeated_in_gallery() {
		$media = RemoteMedia::from_meta( $this->fixture() );

		$this->assertNotContains( 'aaa', array_column( $media->gallery(), 'sha256' ) );
	}

	/**
	 * Test that non-image entries are excluded.
	 *
	 * The export carries documents in this array on purpose — dropping them
	 * upstream would force a re-export the day documents get a Woo slot.
	 * Filtering is this class's responsibility.
	 *
	 * @return void
	 */
	public function test_documents_are_excluded() {
		$json = wp_json_encode(
			array(
				array(
					'role'   => 'hero',
					'kind'   => 'image',
					'url'    => 'https://cdn.test/hero.jpg',
					'title'  => 'hero.jpg',
					'sha256' => 'aaa',
				),
				array(
					'role'     => 'gallery',
					'kind'     => 'document',
					'url'      => 'https://cdn.test/manual.pdf',
					'title'    => 'manual.pdf',
					'sha256'   => 'ddd',
					'position' => 1,
				),
			)
		);

		$media = RemoteMedia::from_meta( $json );

		$this->assertSame( array(), $media->gallery() );
		$this->assertCount( 1, $media->all() );
	}

	/**
	 * Test that a documents-only product reports no images.
	 *
	 * `_fa_media_urls` is empty for such a product while `_fa_media` is
	 * populated — the two columns disagree by design, and this is the case
	 * where that matters.
	 *
	 * @return void
	 */
	public function test_documents_only_product_has_no_images() {
		$json = wp_json_encode(
			array(
				array(
					'role'   => 'hero',
					'kind'   => 'document',
					'url'    => 'https://cdn.test/spec.pdf',
					'title'  => 'spec.pdf',
					'sha256' => 'eee',
				),
			)
		);

		$media = RemoteMedia::from_meta( $json );

		$this->assertFalse( $media->has_images() );
		$this->assertNull( $media->hero() );
	}

	/**
	 * Test that an entry missing a url or sha256 is discarded.
	 *
	 * Both are load-bearing: the url is what renders and the sha256 is half
	 * the attachment's identity key. An entry lacking either cannot produce a
	 * usable attachment, so it must not reach the creator.
	 *
	 * @return void
	 */
	public function test_entries_without_url_or_sha_are_discarded() {
		$json = wp_json_encode(
			array(
				array(
					'role'   => 'hero',
					'kind'   => 'image',
					'url'    => '',
					'title'  => 'broken',
					'sha256' => 'aaa',
				),
				array(
					'role'     => 'gallery',
					'kind'     => 'image',
					'url'      => 'https://cdn.test/x.jpg',
					'title'    => 'x',
					'position' => 1,
				),
			)
		);

		$media = RemoteMedia::from_meta( $json );

		$this->assertFalse( $media->has_images() );
		$this->assertSame( array(), $media->all() );
	}

	/**
	 * Test that all() returns the hero first, then gallery by position.
	 *
	 * This is the order the creator writes attachments in, so it is the order
	 * the storefront ends up showing.
	 *
	 * @return void
	 */
	public function test_all_returns_hero_first_then_gallery_in_order() {
		$media = RemoteMedia::from_meta( $this->fixture() );

		$this->assertSame(
			array( 'aaa', 'ccc', 'bbb' ),
			array_column( $media->all(), 'sha256' )
		);
	}
}
