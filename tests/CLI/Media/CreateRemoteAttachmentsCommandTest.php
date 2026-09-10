<?php
/**
 * Tests for CreateRemoteAttachmentsCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\CreateRemoteAttachmentsCommand;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * CreateRemoteAttachmentsCommand: `wp fa:media create-remote-attachments`.
 *
 * The attachment logic lives in RemoteAttachmentCreator and is tested there.
 * These tests cover what the command owns: product selection, the
 * reachability probe and its suspect-shape shortcut, the finder SQL, and the
 * run summary. Issue #24.
 */
class CreateRemoteAttachmentsCommandTest extends TestCase {

	/**
	 * A hero URL of the shape measured as always reachable (not probed).
	 */
	private const SAFE_URL = 'https://ik.example.com/s3/files/Products/a.jpg';

	/**
	 * A hero URL of the shape known to contain dead links (probed).
	 */
	private const SUSPECT_URL = 'https://ik.example.com/s3/2023/4/b.jpg';

	/**
	 * Mocked $wpdb.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * Install a $wpdb mock and reset the WP_CLI recorder.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();

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
	 * @param string $sha Image sha256.
	 * @return string JSON.
	 */
	private function cell( $url, $sha = 'abc123' ) {
		return wp_json_encode_for_test(
			array(
				array(
					'kind'   => 'image',
					'role'   => 'hero',
					'url'    => $url,
					'sha256' => $sha,
				),
			)
		);
	}

	/**
	 * Stub the progress bar with a no-op object.
	 */
	private function stub_progress_bar() {
		Functions\when( 'WP_CLI\Utils\make_progress_bar' )->justReturn(
			new class() {
				/**
				 * No-op.
				 */
				public function tick() {}
				/**
				 * No-op.
				 */
				public function finish() {}
			}
		);
	}

	/**
	 * Make the finder report no existing attachment for any pair.
	 */
	private function finder_finds_nothing() {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'FIND-SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->with( 'FIND-SQL' )->andReturn( null );
	}

	/**
	 * The summary line logged by the run.
	 *
	 * @return string
	 */
	private function summary_line() {
		foreach ( \WP_CLI::get_calls( 'log' ) as $call ) {
			if ( 0 === strpos( $call['args'][0], 'products ' ) ) {
				return $call['args'][0];
			}
		}
		$this->fail( 'No summary line was logged.' );
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new CreateRemoteAttachmentsCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media create-remote-attachments', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'create' ), $calls[0]['args'][1] );
	}

	/**
	 * With no product carrying `_fa_media` the run warns and stops before any
	 * progress bar or creator work.
	 */
	public function test_warns_and_stops_when_no_product_carries_media() {
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => false !== strpos( $sql, "meta_key = '_fa_media'" ) && false === strpos( $sql, 'LIMIT' ) ) )
			->andReturn( array() );
		Functions\expect( 'WP_CLI\Utils\make_progress_bar' )->never();

		( new CreateRemoteAttachmentsCommand() )->create( array(), array() );

		$warnings = \WP_CLI::get_calls( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'No products carry _fa_media', $warnings[0]['args'][0] );
		$this->assertCount( 0, \WP_CLI::get_calls( 'success' ) );
	}

	/**
	 * `--limit` is appended through $wpdb->prepare, not interpolated.
	 */
	public function test_limit_is_prepared_into_the_selection_query() {
		$this->stub_progress_bar();
		$this->wpdb->shouldReceive( 'prepare' )->once()->with( ' LIMIT %d', 3 )->andReturn( ' LIMIT 3' );
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => str_ends_with( $sql, 'ORDER BY post_id ASC LIMIT 3' ) ) )
			->andReturn( array( '7' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'limit' => '3', 'dry-run' => true ) );

		$this->assertStringStartsWith( 'products 1 |', $this->summary_line() );
	}

	/**
	 * `--product` accepts a comma-separated list, tolerates spaces, and drops
	 * anything that is not a positive integer.
	 */
	public function test_product_flag_selects_ids_without_querying() {
		$this->stub_progress_bar();
		$this->wpdb->shouldReceive( 'get_col' )->never();
		$seen = array();
		Functions\when( 'get_post_meta' )->alias(
			function ( $id ) use ( &$seen ) {
				$seen[] = $id;
				return '';
			}
		);

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'product' => '5, 6,abc,0', 'dry-run' => true ) );

		$this->assertSame( array( 5, 6 ), $seen );
		$this->assertStringStartsWith( 'products 2 |', $this->summary_line() );
	}

	/**
	 * A dry run counts the attachments it would create, writes nothing, and
	 * does not probe URLs of the shape measured as reachable.
	 */
	public function test_dry_run_reports_would_be_creations_without_probing_safe_urls() {
		$this->stub_progress_bar();
		$this->finder_finds_nothing();
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( self::SAFE_URL ) );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_insert_attachment' )->never();

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'product' => '5', 'dry-run' => true ) );

		$this->assertSame(
			'products 1 | attachments created 1 | already present 0 | unreachable urls 0 | insert failures 0',
			$this->summary_line()
		);
		$this->assertCount( 0, \WP_CLI::get_calls( 'warning' ) );
		$this->assertSame( 'Dry run: nothing was written.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * A suspect-shaped URL is probed with a tiny ImageKit transform, and a
	 * non-200 answer leaves the product stranded: reported, wiring cleared.
	 */
	public function test_dead_suspect_url_strands_the_product_and_clears_its_wiring() {
		$this->stub_progress_bar();
		$this->finder_finds_nothing();
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( self::SUSPECT_URL ) );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( self::SUSPECT_URL . '?tr=w-10', array( 'timeout' => 20 ) )
			->andReturn( 'response' );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->with( 'response' )->andReturn( 404 );
		Functions\expect( 'delete_post_meta' )->once()->with( 9, '_thumbnail_id' );
		Functions\expect( 'delete_post_meta' )->once()->with( 9, '_product_image_gallery' );

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'product' => '9' ) );

		$this->assertStringContainsString( 'unreachable urls 1', $this->summary_line() );
		$warnings = \WP_CLI::get_calls( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( '1 products have no reachable image:', $warnings[0]['args'][0] );
		$logs = \WP_CLI::get_calls( 'log' );
		$this->assertSame( '9', end( $logs )['args'][0], 'The stranded product ids are logged after the warning.' );
		$this->assertSame( 'Done.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * `--recheck-all` probes every URL, and a URL that already carries a query
	 * string gets the transform appended with `&`, not a second `?`.
	 */
	public function test_recheck_all_probes_safe_urls_and_joins_query_strings_correctly() {
		$this->stub_progress_bar();
		$this->finder_finds_nothing();
		$url = self::SAFE_URL . '?v=2';
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( $url ) );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( $url . '&tr=w-10', array( 'timeout' => 20 ) )
			->andReturn( 'response' );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'product' => '5', 'dry-run' => true, 'recheck-all' => true ) );

		$this->assertStringContainsString( 'attachments created 1', $this->summary_line() );
	}

	/**
	 * An attachment the finder already knows is counted as present, not created.
	 */
	public function test_existing_attachment_is_counted_as_present() {
		$this->stub_progress_bar();
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'FIND-SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->with( 'FIND-SQL' )->andReturn( '42' );
		Functions\when( 'get_post_meta' )->justReturn( $this->cell( self::SAFE_URL ) );

		( new CreateRemoteAttachmentsCommand() )->create( array(), array( 'product' => '5', 'dry-run' => true ) );

		$this->assertStringContainsString( 'attachments created 0 | already present 1', $this->summary_line() );
	}

	/**
	 * find_existing() looks up an attachment by (parent product, sha) through
	 * a prepared statement and returns its id as an int, 0 when absent.
	 */
	public function test_find_existing_queries_by_product_and_sha() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "m.meta_key = '_fa_media_sha256'" )
						&& false !== strpos( $sql, "p.post_type = 'attachment'" )
						&& false !== strpos( $sql, 'p.post_parent = %d' )
						&& false !== strpos( $sql, 'm.meta_value = %s' )
				),
				5,
				'abc123'
			)
			->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED' )->andReturn( '42' );

		$this->assertSame( 42, ( new CreateRemoteAttachmentsCommand() )->find_existing( 5, 'abc123' ) );
	}

	/**
	 * A missing row yields 0, not null or false.
	 */
	public function test_find_existing_returns_zero_when_absent() {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$this->assertSame( 0, ( new CreateRemoteAttachmentsCommand() )->find_existing( 5, 'nope' ) );
	}
}

/**
 * JSON encode without depending on WordPress' wp_json_encode().
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_test( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
