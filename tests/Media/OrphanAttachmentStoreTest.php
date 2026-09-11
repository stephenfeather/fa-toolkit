<?php
/**
 * Tests for OrphanAttachmentStore.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\OrphanAttachmentStore;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * OrphanAttachmentStore: the three bulk reads the orphan prune needs. Every
 * read is a handful of queries, never one per attachment.
 */
class OrphanAttachmentStoreTest extends TestCase {

	/**
	 * Mocked $wpdb.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * Install a $wpdb mock.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb           = Mockery::mock( 'wpdb_for_test' );
		$this->wpdb->postmeta = 'wp_postmeta';
		$this->wpdb->posts    = 'wp_posts';
		$GLOBALS['wpdb']      = $this->wpdb;
	}

	/**
	 * Drop the $wpdb mock.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Only pointer attachments are ever selected: the query requires an
	 * attachment post with a `_fa_media_sha256` row and a parent, so an
	 * ordinary uploaded attachment can never reach the prune.
	 */
	public function test_pointer_attachments_selects_only_attachments_carrying_a_media_sha() {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "INNER JOIN wp_postmeta s ON s.post_id = a.ID AND s.meta_key = '_fa_media_sha256'" )
						&& false !== strpos( $sql, "a.post_type = 'attachment'" )
						&& false !== strpos( $sql, 'a.post_parent > 0' )
						&& false === strpos( $sql, 'IN (' )
				),
				'ARRAY_A'
			)
			->andReturn(
				array(
					array( 'ID' => '11', 'post_parent' => '5', 'sha256' => 'aaa' ),
					array( 'ID' => '12', 'post_parent' => '5', 'sha256' => 'bbb' ),
					array( 'ID' => '20', 'post_parent' => '7', 'sha256' => 'ccc' ),
				)
			);

		$this->assertSame(
			array(
				5 => array( 11 => 'aaa', 12 => 'bbb' ),
				7 => array( 20 => 'ccc' ),
			),
			( new OrphanAttachmentStore() )->pointer_attachments( array() )
		);
	}

	/**
	 * A product list is prepared into the query as integer placeholders.
	 */
	public function test_pointer_attachments_prepares_a_product_list() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( $sql ) => false !== strpos( $sql, 'a.post_parent IN (%d,%d)' ) ), 5, 7 )
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'PREPARED', 'ARRAY_A' )->andReturn( array() );

		$this->assertSame( array(), ( new OrphanAttachmentStore() )->pointer_attachments( array( 5, 7 ) ) );
	}

	/**
	 * Cells come back per product as raw strings, every row kept, so a
	 * product with two `_fa_media` rows is judged on both.
	 */
	public function test_media_cells_groups_raw_cells_by_product() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( $sql ) => false !== strpos( $sql, "meta_key = '_fa_media' AND post_id IN (%d,%d)" ) ), 5, 7 )
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED', 'ARRAY_A' )
			->andReturn(
				array(
					array( 'post_id' => '5', 'meta_value' => '[]' ),
					array( 'post_id' => '5', 'meta_value' => '[1]' ),
				)
			);

		$this->assertSame( array( 5 => array( '[]', '[1]' ) ), ( new OrphanAttachmentStore() )->media_cells( array( 5, 7 ) ) );
	}

	/**
	 * Wiring merges the thumbnail and the gallery list into one id set per product.
	 */
	public function test_wired_ids_merges_thumbnail_and_gallery() {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED', 'ARRAY_A' )
			->andReturn(
				array(
					array( 'post_id' => '5', 'meta_key' => '_thumbnail_id', 'meta_value' => '11' ),
					array( 'post_id' => '5', 'meta_key' => '_product_image_gallery', 'meta_value' => '12,13' ),
				)
			);

		$this->assertSame( array( 5 => array( 11, 12, 13 ) ), ( new OrphanAttachmentStore() )->wired_ids( array( 5 ) ) );
	}

	/**
	 * Long product lists are read in chunks, so no single IN list grows with
	 * the catalogue.
	 */
	public function test_media_cells_reads_long_product_lists_in_chunks() {
		$this->wpdb->shouldReceive( 'prepare' )->times( 3 )->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_results' )->times( 3 )->andReturn( array() );

		( new OrphanAttachmentStore() )->media_cells( range( 1, 1001 ) );
	}
}
