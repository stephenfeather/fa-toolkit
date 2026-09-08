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
			'deleted'   => array(),
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

		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( $ref ) {
				$meta = $ref['meta'];
				unset( $meta[ $post_id . ':' . $key ] );
				$ref['meta']    = $meta;
				$deleted        = $ref['deleted'];
				$deleted[]      = $post_id . ':' . $key;
				$ref['deleted'] = $deleted;
				return true;
			}
		);

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'is_wp_error' )->justReturn( false );

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

	/**
	 * Test that stale wiring is cleared when every url has died.
	 *
	 * Without this, --recheck-all cannot repair the case it exists for: a
	 * product whose images were fine at import and have since 404'd keeps
	 * rendering broken images, because _thumbnail_id still points at them.
	 *
	 * @return void
	 */
	public function test_all_urls_dead_clears_existing_wiring() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return false; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertContains( '55:_thumbnail_id', (array) $ref['deleted'] );
		$this->assertContains( '55:_product_image_gallery', (array) $ref['deleted'] );
	}

	/**
	 * Test that a product with no media at all is left completely alone.
	 *
	 * An empty cell means "nothing to say about this product's images", not
	 * "this product has no images" — a product may have had its thumbnail set
	 * by hand, and an empty import must not strip it.
	 *
	 * @return void
	 */
	public function test_empty_cell_does_not_clear_wiring() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return false; } );
		$creator->create_for_product( 55, '' );

		$this->assertSame( array(), (array) $ref['deleted'] );
	}

	/**
	 * Test that a gallery emptied upstream stops being referenced.
	 *
	 * @return void
	 */
	public function test_single_image_clears_a_previous_gallery() {
		$ref = $this->stub_wp();

		$json = wp_json_encode(
			array(
				array(
					'role'   => 'hero',
					'kind'   => 'image',
					'url'    => self::HERO,
					'title'  => 'hero.jpg',
					'sha256' => 'aaa',
				),
			)
		);

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $json );

		$this->assertContains( '55:_product_image_gallery', (array) $ref['deleted'] );
	}

	/**
	 * Test that a re-run enriches an attachment created before the data existed.
	 *
	 * Attachments made before `_fa_media` carried dimensions and alt would
	 * otherwise stay on the degraded path permanently: the enrichment would
	 * land in postmeta and never reach the attachment, because insert() is
	 * only reached for attachments that do not yet exist.
	 *
	 * @return void
	 */
	public function test_rerun_refreshes_dimensions_and_alt_on_existing_attachments() {
		$ref = $this->stub_wp();

		$finder = function ( $product_id, $sha ) {
			return 'aaa' === $sha ? 900 : 901;
		};

		$cell = $this->cell(
			array(
				'width'  => 1200,
				'height' => 800,
				'alt'    => 'Burris XTR III Scope 3.3-18x50mm SCR 2 MIL Illum',
			)
		);

		$creator = new RemoteAttachmentCreator( $finder, function () { return true; } );
		$result  = $creator->create_for_product( 55, $cell );

		$this->assertSame( 2, $result['existing'] );

		$meta = $ref['meta'];
		$this->assertSame( 1200, $meta['900:_fa_remote_width'] );
		$this->assertSame( 800, $meta['900:_fa_remote_height'] );
		$this->assertSame( 'Burris XTR III Scope 3.3-18x50mm SCR 2 MIL Illum', $meta['900:_wp_attachment_image_alt'] );
	}

	/**
	 * Test that a changed url on an unchanged image is picked up.
	 *
	 * 2,306 images live at more than one s3 key with identical bytes, so the
	 * sha can stay put while the url moves.
	 *
	 * @return void
	 */
	public function test_rerun_refreshes_a_changed_url() {
		$ref = $this->stub_wp();

		// Distinct ids per sha: one finder id for both would let the gallery
		// entry overwrite the hero's meta and the assertion would test nothing.
		$finder  = function ( $product_id, $sha ) { return 'aaa' === $sha ? 900 : 901; };
		$creator = new RemoteAttachmentCreator( $finder, function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertSame( self::HERO, $ref['meta']['900:_fa_remote_url'] );
	}

	/**
	 * Test that a dry run does not report a healthy product as unusable.
	 *
	 * @return void
	 */
	public function test_dry_run_does_not_report_no_usable_image() {
		$this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->set_dry_run( true );
		$result = $creator->create_for_product( 55, $this->cell() );

		$this->assertFalse( $result['no_usable_image'] );
	}

	/**
	 * Test that a dry run with one dead image still reports the product usable.
	 *
	 * @return void
	 */
	public function test_dry_run_with_one_dead_image_is_still_usable() {
		$this->stub_wp();

		$probe   = function ( $url ) { return self::HERO !== $url; };
		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), $probe );
		$creator->set_dry_run( true );
		$result = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 1, $result['created'] );
		$this->assertFalse( $result['no_usable_image'] );
	}

	/**
	 * Test that a failed insert wires nothing.
	 *
	 * wp_insert_attachment() returns 0 or a WP_Error on failure. Writing meta
	 * against that would land on post 0 and wire the product to an attachment
	 * that does not exist.
	 *
	 * @return void
	 */
	public function test_failed_insert_is_not_wired() {
		$ref = $this->stub_wp();
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertArrayNotHasKey( '55:_thumbnail_id', (array) $ref['meta'] );
		$this->assertArrayNotHasKey( '0:_fa_remote_url', (array) $ref['meta'] );
	}

	/**
	 * Test that the mime type follows the file extension.
	 *
	 * Hard-coding image/jpeg would mislabel every png in the catalogue.
	 *
	 * @return void
	 */
	public function test_mime_type_follows_the_extension() {
		$ref = $this->stub_wp();

		$json = wp_json_encode(
			array(
				array(
					'role'   => 'hero',
					'kind'   => 'image',
					'url'    => 'https://cdn.test/a.png?v=2',
					'title'  => 'a.png',
					'sha256' => 'aaa',
				),
			)
		);

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $json );

		$inserted = (array) $ref['inserted'];
		$row      = reset( $inserted );
		$this->assertSame( 'image/png', $row['args']['post_mime_type'] );
	}

	/**
	 * Test that a failed insert is not counted as created.
	 *
	 * `created` is the number an operator reads to decide whether a run
	 * worked. Counting an attachment that was never made reports success for
	 * a product that ended up with nothing.
	 *
	 * @return void
	 */
	public function test_failed_insert_is_not_counted_as_created() {
		$this->stub_wp();
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 2, $result['failed'] );
	}

	/**
	 * Test that all-inserts-failed is reported, not silently passed over.
	 *
	 * Every URL was reachable, so the unreachable count is zero — the product
	 * still ended up with no images and has to appear in the report.
	 *
	 * @return void
	 */
	public function test_all_inserts_failing_reports_no_usable_image() {
		$this->stub_wp();
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertTrue( $result['no_usable_image'] );
		$this->assertSame( 0, $result['unreachable'] );
	}

	/**
	 * Test that a failed insert does NOT clear existing wiring.
	 *
	 * The distinction that matters: a dead URL is a durable fact about the
	 * image and justifies clearing, but a failed insert is a transient fact
	 * about this run. Deleting a product's wiring because our own write failed
	 * turns a retryable error into data loss.
	 *
	 * @return void
	 */
	public function test_failed_insert_does_not_clear_existing_wiring() {
		$ref = $this->stub_wp();
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertSame( array(), (array) $ref['deleted'], 'A transient write failure must not destroy wiring.' );
	}

	/**
	 * Test that a WP_Error from wp_insert_attachment is handled like a failure.
	 *
	 * @return void
	 */
	public function test_wp_error_insert_is_treated_as_failure() {
		$this->stub_wp();
		Functions\when( 'wp_insert_attachment' )->justReturn( 'error-object' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$result  = $creator->create_for_product( 55, $this->cell() );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertTrue( $result['no_usable_image'] );
	}

	/**
	 * Test that WordPress's own metadata rows are STORED, not filtered.
	 *
	 * This is the defect the first live batch found. `wp_get_attachment_metadata()`
	 * returns false before applying its own filter when `_wp_attachment_metadata`
	 * is missing, so a filter cannot supply metadata for an attachment that has
	 * none. Without a real row, `srcset` and `sizes` are silently absent on every
	 * rendered page while the unit tests for those callbacks pass, because they
	 * call the callbacks directly and nothing checks that WordPress reaches them.
	 *
	 * @return void
	 */
	public function test_stores_real_wordpress_attachment_metadata() {
		$ref = $this->stub_wp();

		$cell = $this->cell( array( 'width' => 1200, 'height' => 600 ) );

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $cell );

		$meta = (array) $ref['meta'];

		$this->assertArrayHasKey( '100:_wp_attachment_metadata', $meta );
		$stored = $meta['100:_wp_attachment_metadata'];

		// The two conditions core checks before it will proceed.
		$this->assertIsArray( $stored );
		$this->assertNotEmpty( $stored['sizes'] );
		$this->assertArrayHasKey( 'file', $stored );
		$this->assertGreaterThanOrEqual( 4, strlen( $stored['file'] ) );

		$this->assertSame( 1200, $stored['width'] );
		$this->assertSame( 600, $stored['height'] );

		// No candidate may exceed the original width.
		foreach ( $stored['sizes'] as $size ) {
			$this->assertLessThanOrEqual( 1200, $size['width'] );
		}
	}

	/**
	 * Test that _wp_attached_file is written.
	 *
	 * wp_attachment_is() calls get_attached_file() and returns false the moment
	 * it is empty, so without this wp_attachment_is_image() is false for every
	 * pointer attachment and anything gating on it silently skips them.
	 *
	 * @return void
	 */
	public function test_stores_attached_file_so_wordpress_treats_it_as_an_image() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell( array( 'width' => 800, 'height' => 400 ) ) );

		$this->assertSame( 'hero.jpg', ( (array) $ref['meta'] )['100:_wp_attached_file'] );
	}

	/**
	 * Test that an image smaller than every candidate still gets a sizes entry.
	 *
	 * An empty `sizes` array fails core's guard just as a missing row does.
	 *
	 * @return void
	 */
	public function test_small_image_still_gets_a_sizes_entry() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell( array( 'width' => 80, 'height' => 40 ) ) );

		$stored = ( (array) $ref['meta'] )['100:_wp_attachment_metadata'];
		$this->assertNotEmpty( $stored['sizes'] );
	}

	/**
	 * Test that no WordPress metadata is invented without real dimensions.
	 *
	 * @return void
	 */
	public function test_no_wordpress_metadata_without_dimensions() {
		$ref = $this->stub_wp();

		$creator = new RemoteAttachmentCreator( $this->finds_nothing(), function () { return true; } );
		$creator->create_for_product( 55, $this->cell() );

		$this->assertArrayNotHasKey( '100:_wp_attachment_metadata', (array) $ref['meta'] );
		$this->assertArrayNotHasKey( '100:_wp_attached_file', (array) $ref['meta'] );
	}
}
