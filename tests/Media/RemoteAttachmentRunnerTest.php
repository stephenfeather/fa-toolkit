<?php
/**
 * Tests for RemoteAttachmentRunner.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\RemoteAttachmentRunner;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * RemoteAttachmentRunner: the product loop shared by the CLI command and the
 * after-import listener. Product selection, the reachability probe, the
 * finder lookup, and the run totals live here; per-product attachment logic
 * stays in RemoteAttachmentCreator.
 */
class RemoteAttachmentRunnerTest extends TestCase {

	private const SAFE_URL    = 'https://ik.example.com/s3/files/Products/a.jpg';
	private const SUSPECT_URL = 'https://ik.example.com/s3/2023/4/b.jpg';

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
	 * A `_fa_media` cell with one hero image.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private function cell( $url ) {
		return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				array(
					'kind'   => 'image',
					'role'   => 'hero',
					'url'    => $url,
					'sha256' => 'abc123',
				),
			)
		);
	}

	/**
	 * A runner whose finder never finds anything.
	 *
	 * @return RemoteAttachmentRunner
	 */
	private function runner_finding_nothing() {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'FIND-SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->with( 'FIND-SQL' )->andReturn( null );
		return new RemoteAttachmentRunner();
	}

	/**
	 * products_with_media() selects on the presence of a non-empty `_fa_media`
	 * cell, ascending by id, with no limit clause when none is asked for.
	 */
	public function test_products_with_media_selects_non_empty_cells() {
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => false !== strpos( $sql, "meta_key = '_fa_media' AND meta_value <> ''" ) && false === strpos( $sql, 'LIMIT' ) ) )
			->andReturn( array( '3', '9' ) );

		$this->assertSame( array( 3, 9 ), ( new RemoteAttachmentRunner() )->products_with_media() );
	}

	/**
	 * A limit is prepared into the query, never interpolated.
	 */
	public function test_products_with_media_prepares_the_limit() {
		$this->wpdb->shouldReceive( 'prepare' )->once()->with( ' LIMIT %d', 5 )->andReturn( ' LIMIT 5' );
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => str_ends_with( $sql, 'ORDER BY post_id ASC LIMIT 5' ) ) )
			->andReturn( array() );

		$this->assertSame( array(), ( new RemoteAttachmentRunner() )->products_with_media( 5 ) );
	}

	/**
	 * unattached_products_with_media() narrows to products carrying media that
	 * have no pointer attachment child yet, so a listener only pays for new media.
	 */
	public function test_unattached_products_with_media_excludes_products_with_a_pointer_attachment() {
		$this->wpdb->shouldReceive( 'prepare' )->once()->with( ' LIMIT %d', 200 )->andReturn( ' LIMIT 200' );
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "meta_key = '_fa_media' AND m.meta_value <> ''" )
						&& false !== strpos( $sql, "meta_key = '_fa_media_sha256'" )
						&& false !== strpos( $sql, "post_type = 'attachment'" )
						&& false !== strpos( $sql, 'NOT EXISTS' )
						&& str_ends_with( $sql, 'LIMIT 200' )
				)
			)
			->andReturn( array( '12' ) );

		$this->assertSame( array( 12 ), ( new RemoteAttachmentRunner() )->unattached_products_with_media( 200 ) );
	}

	/**
	 * products_without_media_cell() counts products that carry no `_fa_media`
	 * key at all, which is how an unmapped import profile shows up.
	 */
	public function test_products_without_media_cell_counts_absent_keys() {
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "post_type = 'product'" )
						&& false !== strpos( $sql, "meta_key = '_fa_media'" )
						&& false !== strpos( $sql, 'NOT EXISTS' )
				)
			)
			->andReturn( '41' );

		$this->assertSame( 41, ( new RemoteAttachmentRunner() )->products_without_media_cell() );
	}

	/**
	 * find_existing() looks up an attachment by (parent product, sha256) through
	 * a prepared statement and returns an int, 0 when absent.
	 */
	public function test_find_existing_queries_by_product_and_sha() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "m.meta_key = '_fa_media_sha256'" )
						&& false !== strpos( $sql, 'p.post_parent = %d' )
						&& false !== strpos( $sql, 'm.meta_value = %s' )
				),
				5,
				'abc123'
			)
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED' )->andReturn( '42' );

		$this->assertSame( 42, ( new RemoteAttachmentRunner() )->find_existing( 5, 'abc123' ) );
	}

	/**
	 * A dry run over a product with a safe-shaped hero counts one would-be
	 * creation, probes nothing, and leaves no product stranded.
	 */
	public function test_run_dry_counts_would_be_creations_without_probing_safe_urls() {
		$runner = $this->runner_finding_nothing();
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( self::SAFE_URL ) );
		Functions\expect( 'wp_remote_get' )->never();

		$result = $runner->run( array( 5 ), true, false );

		$this->assertSame( 1, $result['products'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 0, $result['existing'] );
		$this->assertSame( 0, $result['unreachable'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( array(), $result['stranded'] );
		$this->assertSame( 0, $result['no_media'] );
	}

	/**
	 * A product whose cell is empty is counted as no_media and touches nothing.
	 */
	public function test_run_counts_products_whose_cell_parses_to_no_images() {
		$runner = $this->runner_finding_nothing();
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$result = $runner->run( array( 5, 6 ), true, false );

		$this->assertSame( 2, $result['products'] );
		$this->assertSame( 2, $result['no_media'] );
		$this->assertSame( 0, $result['created'] );
	}

	/**
	 * A dead suspect-shaped URL is probed with the ImageKit transform, strands
	 * the product, and clears its wiring on a real run.
	 */
	public function test_run_strands_a_product_whose_only_url_is_dead() {
		$runner = $this->runner_finding_nothing();
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( self::SUSPECT_URL ) );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( self::SUSPECT_URL . '?tr=w-10', array( 'timeout' => 20 ) )
			->andReturn( 'response' );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 404 );
		Functions\expect( 'delete_post_meta' )->once()->with( 9, '_thumbnail_id' );
		Functions\expect( 'delete_post_meta' )->once()->with( 9, '_product_image_gallery' );

		$result = $runner->run( array( 9 ), false, false );

		$this->assertSame( 1, $result['unreachable'] );
		$this->assertSame( array( 9 ), $result['stranded'] );
	}

	/**
	 * recheck_all probes every URL; a query string is joined with `&`.
	 */
	public function test_run_recheck_all_probes_safe_urls_and_joins_query_strings() {
		$runner = $this->runner_finding_nothing();
		$url    = self::SAFE_URL . '?v=2';
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( $url ) );
		Functions\expect( 'wp_remote_get' )->once()->with( $url . '&tr=w-10', array( 'timeout' => 20 ) )->andReturn( 'r' );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );

		$result = $runner->run( array( 5 ), true, true );

		$this->assertSame( 1, $result['created'] );
	}

	/**
	 * The per-product callback fires once per product, after that product.
	 */
	public function test_run_invokes_the_tick_callback_once_per_product() {
		$runner = $this->runner_finding_nothing();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$ticks = 0;

		$runner->run(
			array( 1, 2, 3 ),
			true,
			false,
			function () use ( &$ticks ) {
				++$ticks;
			}
		);

		$this->assertSame( 3, $ticks );
	}
}
