<?php
/**
 * Tests for RemoteAttachmentCreator.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\RemoteAttachmentCreator;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test case for RemoteAttachmentCreator.
 *
 * The creator turns one product's `_fa_media` cell into attachment rows and
 * wires them to WooCommerce. It is keyed on (product id, sha256) so that a
 * re-run is a no-op, which is what makes it safe to stop and resume across
 * 32,332 products.
 */
class RemoteAttachmentCreatorTest extends TestCase {

	private const HERO = 'https://cdn.test/hero.jpg';
	private const GAL  = 'https://cdn.test/g1.jpg';

	/**
	 * A media cell with a hero and one gallery image.
	 *
	 * @param array $extra_hero Extra keys to merge into the hero entry.
	 * @return string
	 */
	private function cell( array $extra_hero = array() ) {
		return wp_json_encode(
			array(
				array_merge(
					array(
						'role'   => 'hero',
						'kind'   => 'image',
						'url'    => self::HERO,
						'title'  => 'hero.jpg',
						'sha256' => 'aaa',
					),
					$extra_hero
				),
				array(
					'role'     => 'gallery',
					'kind'     => 'image',
					'url'      => self::GAL,
					'title'    => 'g1.jpg',
					'sha256'   => 'bbb',
					'position' => 1,
				),
			)
		);
	}

	/**
	 * Wire up the WordPress functions the creator touches.
	 *
	 * @param array $existing Map of "productid:sha" => attachment id already present.
	 * @return array Captured writes, by reference-ish accumulation.
	 */
	private function stub_wp( array $existing = array() ) {
		$state = array(
			'inserted'  => array(),
			'meta'      => array(),
			'next_id'   => 100,
			'existing'  => $existing,
			'reachable' => true,
		);
		$ref   = new \ArrayObject( $state );

		Functions\when( 'wp_insert_attachment' )->alias(
			function ( $args, $file = false, $parent = 0 ) use ( $ref ) {
				$id = $ref['next_id'];
				$ref['next_id'] = $id + 1;
				$inserted       = $ref['inserted'];
				$inserted[ $id ] = array( 'args' => $args, 'parent' => $parent );
				$ref['inserted'] = $inserted;
				return $id;
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( $ref ) {
				$meta = $ref['meta'];
				$meta[ $post_id . ':' . $key ] = $value;
				$ref['meta'] = $meta;
				return true;
			}
		);

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_update_post' )->justReturn( 1 );

		return $ref;
	}

	/**
	 * A finder that reports no existing attachment.
	 *
	 * @return callable
	 */
	private function finds_nothing() {
		return function ( $product_id, $sha ) {
			return 0;
		};
	}

	/**
	 * Test that a hero and gallery image both become attachments.
	 *
	 * @return void
	 */
	public function test_creates_an_attachment_per_image() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 2, $result['created'] );
		$this->assertCount( 2, $ref['inserted'] );
	}

	/**
	 * Test that each attachment stores its remote url and identity.
	 *
	 * @return void
	 */
	public function test_stores_remote_url_and_sha_on_each_attachment() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$meta = $ref['meta'];
		$this->assertSame( self::HERO, $meta['100:_fa_remote_url'] );
		$this->assertSame( 'aaa', $meta['100:_fa_media_sha256'] );
		$this->assertSame( self::GAL, $meta['101:_fa_remote_url'] );
		$this->assertSame( 'bbb', $meta['101:_fa_media_sha256'] );
	}

	/**
	 * Test that the product's thumbnail and gallery are wired to WooCommerce.
	 *
	 * @return void
	 */
	public function test_sets_thumbnail_and_gallery_on_the_product() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$meta = $ref['meta'];
		$this->assertSame( 100, $meta['55:_thumbnail_id'] );
		$this->assertSame( '101', $meta['55:_product_image_gallery'] );
	}

	/**
	 * Test that attachments are parented to the product.
	 *
	 * post_parent is the real product, not 0: the per-pair design makes that
	 * true, and attachment cleanup tools reason about parentage. Leaving it 0
	 * would make every one of these look orphaned.
	 *
	 * @return void
	 */
	public function test_attachments_are_parented_to_the_product() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		foreach ( $ref['inserted'] as $row ) {
			$this->assertSame( 55, $row['parent'] );
		}
	}

	/**
	 * Test that a second run creates nothing.
	 *
	 * This is what makes the command safe to stop and resume mid-catalogue.
	 *
	 * @return void
	 */
	public function test_rerun_is_a_noop() {
		$ref = $this->stub_wp();

		$finder = function ( $product_id, $sha ) {
			return 'aaa' === $sha ? 900 : 901;
		};

		$creator = new RemoteAttachmentCreator( $finder, function () { return true; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 2, $result['existing'] );
		$this->assertCount( 0, $ref['inserted'] );

		// The wiring is still asserted, so a product whose attachments exist
		// but whose thumbnail was lost gets repaired rather than skipped.
		$meta = $ref['meta'];
		$this->assertSame( 900, $meta['55:_thumbnail_id'] );
		$this->assertSame( '901', $meta['55:_product_image_gallery'] );
	}

	/**
	 * Test that a dead URL produces no attachment.
	 *
	 * A pointer attachment to a 404 renders a broken image and looks
	 * deliberate. Absent is honest, and self-heals on a re-run once the URL is
	 * repaired upstream — which is why reachability failures are never cached.
	 *
	 * @return void
	 */
	public function test_dead_url_creates_no_attachment() {
		$ref = $this->stub_wp();

		$probe = function ( $url ) {
			return self::HERO !== $url;
		};

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), $probe );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['unreachable'] );
		$this->assertCount( 1, $ref['inserted'] );
	}

	/**
	 * Test that a dead hero promotes the next reachable image.
	 *
	 * Worth only 19 products on the real data — the bad URLs cluster by
	 * product, so a product with a dead hero usually has a dead gallery too.
	 * It costs nothing when there is nothing to promote.
	 *
	 * @return void
	 */
	public function test_dead_hero_promotes_the_next_reachable_image() {
		$ref = $this->stub_wp();

		$probe = function ( $url ) {
			return self::HERO !== $url;
		};

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), $probe );
		$creator->create_for_product( 55, $this->cell() );

		$meta = $ref['meta'];
		$this->assertSame( 100, $meta['55:_thumbnail_id'], 'The surviving gallery image must become the thumbnail.' );
		$this->assertArrayNotHasKey( '55:_product_image_gallery', $meta );
	}

	/**
	 * Test that a product with no reachable image is reported, not wired.
	 *
	 * @return void
	 */
	public function test_product_with_no_reachable_image_is_reported() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return false; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 0, $result['created'] );
		$this->assertTrue( $result['no_usable_image'] );
		$this->assertCount( 0, $ref['inserted'] );
		$this->assertArrayNotHasKey( '55:_thumbnail_id', $ref['meta'] );
	}

	/**
	 * Test that dimensions and alt are stored when the entry carries them.
	 *
	 * @return void
	 */
	public function test_dimensions_and_alt_are_stored_when_present() {
		$ref = $this->stub_wp();

		$cell = $this->cell(
			array(
				'width'  => 1200,
				'height' => 800,
				'alt'    => 'Federal Premium Brass Cases .270 WSM',
			)
		);

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $cell );

		$meta = $ref['meta'];
		$this->assertSame( 1200, $meta['100:_fa_remote_width'] );
		$this->assertSame( 800, $meta['100:_fa_remote_height'] );
		$this->assertSame( 'Federal Premium Brass Cases .270 WSM', $meta['100:_wp_attachment_image_alt'] );
	}

	/**
	 * Test that alt is not written when the entry has none.
	 *
	 * Only 23% of pairs have curated alt. For the rest the field is left
	 * unset rather than filled with the vendor filename, which a screen
	 * reader would announce verbatim.
	 *
	 * @return void
	 */
	public function test_alt_is_not_invented_from_the_title() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertArrayNotHasKey( '100:_wp_attachment_image_alt', $ref['meta'] );
	}

	/**
	 * Test that a product with an empty media cell is skipped cheaply.
	 *
	 * @return void
	 */
	public function test_empty_cell_is_skipped() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$result  = $creator->create_for_product( 55, '' );

		$this->assertSame( 0, $result['created'] );
		$this->assertFalse( $result['no_usable_image'] );
		$this->assertCount( 0, $ref['inserted'] );
	}

	/**
	 * Test that dry run creates nothing but still reports what it would do.
	 *
	 * @return void
	 */
	public function test_dry_run_creates_nothing() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->set_dry_run( true );
		$result = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 2, $result['created'] );
		$this->assertCount( 0, $ref['inserted'] );
		$this->assertSame( array(), $ref['meta'] );
	}
}
