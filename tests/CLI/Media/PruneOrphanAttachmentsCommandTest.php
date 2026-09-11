<?php
/**
 * Tests for PruneOrphanAttachmentsCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\PruneOrphanAttachmentsCommand;
use FAToolkit\Media\OrphanAttachmentStore;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * PruneOrphanAttachmentsCommand: `wp fa:media prune-orphan-attachments`.
 *
 * Selection is OrphanAttachmentPlan's and is tested there; these tests cover
 * what the command owns: dry run by default, --execute, --product scoping,
 * the wired-orphan guard, file-deletion suppression, and the report.
 */
class PruneOrphanAttachmentsCommandTest extends TestCase {

	/**
	 * Reset the WP_CLI recorder.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
	}

	/**
	 * A `_fa_media` cell carrying the given image sha256 values.
	 *
	 * @param string ...$shas Image sha256 values.
	 * @return string
	 */
	private function cell( ...$shas ) {
		return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array_map(
				fn( $sha ) => array(
					'kind'   => 'image',
					'role'   => 'gallery',
					'url'    => 'https://ik.example.com/' . $sha . '.jpg',
					'sha256' => $sha,
				),
				$shas
			)
		);
	}

	/**
	 * A store over two products.
	 *
	 * Product 5: attachments 11 (current) and 12 (orphan, unwired).
	 * Product 7: attachments 20 (current), 21 (orphan still wired as thumbnail)
	 * and 22 (orphan, unwired).
	 *
	 * @param array $expected_ids Product ids the store must be asked for.
	 * @return \Mockery\MockInterface&OrphanAttachmentStore
	 */
	private function store( array $expected_ids = array() ) {
		$store = Mockery::mock( OrphanAttachmentStore::class );
		$store->shouldReceive( 'pointer_attachments' )
			->once()
			->with( $expected_ids )
			->andReturn(
				array(
					5 => array(
						11 => 'aaa',
						12 => 'old5',
					),
					7 => array(
						20 => 'ccc',
						21 => 'old7a',
						22 => 'old7b',
					),
				)
			);
		$store->shouldReceive( 'media_cells' )
			->once()
			->with( array( 5, 7 ) )
			->andReturn(
				array(
					5 => array( $this->cell( 'aaa' ) ),
					7 => array( $this->cell( 'ccc' ) ),
				)
			);
		$store->shouldReceive( 'wired_ids' )
			->once()
			->with( array( 5, 7 ) )
			->andReturn(
				array(
					5 => array( 11 ),
					7 => array( 21, 20 ),
				)
			);

		return $store;
	}

	/**
	 * Every line logged, in order.
	 *
	 * @return array<int, string>
	 */
	private function logs() {
		return array_map( fn( $call ) => $call['args'][0], \WP_CLI::get_calls( 'log' ) );
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new PruneOrphanAttachmentsCommand( Mockery::mock( OrphanAttachmentStore::class ) );

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertSame( 'fa:media prune-orphan-attachments', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'prune' ), $calls[0]['args'][1] );
	}

	/**
	 * Without --execute the command is a dry run: it reports per product and
	 * in total, deletes nothing, and says how to delete.
	 */
	public function test_dry_run_is_the_default_and_deletes_nothing() {
		Functions\expect( 'wp_delete_attachment' )->never();

		( new PruneOrphanAttachmentsCommand( $this->store() ) )->prune( array(), array() );

		$this->assertSame(
			array(
				'product 5 | pointer attachments 2 | current 1 | orphans 1 | wired orphans 0',
				'product 7 | pointer attachments 3 | current 1 | orphans 1 | wired orphans 1',
				'TOTAL products 2 | pointer attachments 5 | current 2 | orphans 2 | wired orphans 1 | skipped invalid cell 0 | skipped no cell 0 | deleted 0 | delete failures 0',
			),
			array_slice( $this->logs(), 0, 3 )
		);
		$this->assertSame( 'Dry run: nothing was deleted. Re-run with --execute to delete 2 orphan attachments.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * A wired orphan is reported as an anomaly with its ids, dry run or not.
	 */
	public function test_wired_orphans_are_reported_as_anomalies() {
		( new PruneOrphanAttachmentsCommand( $this->store() ) )->prune( array(), array() );

		$warnings = \WP_CLI::get_calls( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertSame( '1 orphan attachments are still wired to their product and were left in place: 21', $warnings[0]['args'][0] );
	}

	/**
	 * --execute force-deletes exactly the unwired orphans, never the wired
	 * one, and counts the deletions.
	 */
	public function test_execute_force_deletes_only_unwired_orphans() {
		$deleted = array();
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( $id, $force ) use ( &$deleted ) {
				$deleted[] = array( $id, $force );
				return (object) array( 'ID' => $id );
			}
		);

		( new PruneOrphanAttachmentsCommand( $this->store() ) )->prune( array(), array( 'execute' => true ) );

		$this->assertSame( array( array( 12, true ), array( 22, true ) ), $deleted );
		$this->assertStringEndsWith( '| deleted 2 | delete failures 0', $this->logs()[2] );
		$this->assertSame( 'Deleted 2 orphan attachments.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * Pointer attachments have no local file, but core still derives an
	 * uploads path from `_wp_attached_file` and would unlink a real file that
	 * happens to share the basename. File deletion is suppressed for the
	 * duration of the prune and restored afterwards.
	 */
	public function test_execute_suppresses_file_deletion_while_deleting() {
		$suppressed_during_delete = array();
		Functions\when( 'wp_delete_attachment' )->alias(
			function () use ( &$suppressed_during_delete ) {
				$suppressed_during_delete[] = false !== has_filter( 'wp_delete_file', '__return_empty_string' );
				return (object) array();
			}
		);

		( new PruneOrphanAttachmentsCommand( $this->store() ) )->prune( array(), array( 'execute' => true ) );

		$this->assertSame( array( true, true ), $suppressed_during_delete );
		$this->assertFalse( has_filter( 'wp_delete_file', '__return_empty_string' ), 'The suppression must not outlive the prune.' );
	}

	/**
	 * A deletion that returns false or null is a failure: counted and
	 * warned about, never counted as deleted.
	 */
	public function test_a_failed_deletion_is_counted_as_a_failure() {
		Functions\when( 'wp_delete_attachment' )->justReturn( false );

		( new PruneOrphanAttachmentsCommand( $this->store() ) )->prune( array(), array( 'execute' => true ) );

		$this->assertStringEndsWith( '| deleted 0 | delete failures 2', $this->logs()[2] );
		$this->assertContains( '2 orphan attachments could not be deleted: 12,22', array_map( fn( $call ) => $call['args'][0], \WP_CLI::get_calls( 'warning' ) ) );
	}

	/**
	 * --product scopes the read to the listed products, dropping anything
	 * that is not a positive integer.
	 */
	public function test_product_flag_scopes_the_read() {
		( new PruneOrphanAttachmentsCommand( $this->store( array( 5, 7 ) ) ) )->prune( array(), array( 'product' => '5, 7,abc,0' ) );

		$this->assertStringStartsWith( 'TOTAL products 2 |', $this->logs()[2] );
	}

	/**
	 * `--product` values that yield no valid id.
	 *
	 * A bare `--product` arrives from WP-CLI as boolean true.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public static function product_flags_with_no_valid_id() {
		return array(
			'non-numeric and zero' => array( 'abc,0' ),
			'empty value'          => array( '' ),
			'only zero'            => array( '0' ),
			'negative'             => array( '-5' ),
			'bare flag'            => array( true ),
		);
	}

	/**
	 * A `--product` flag that yields no valid id stops before any read.
	 *
	 * Without this, an empty scope reached the store as "no scope" and a
	 * mistyped `--product` pruned the whole catalogue (PR #98 review).
	 *
	 * @param mixed $value Raw `--product` value.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'product_flags_with_no_valid_id' )]
	public function test_a_product_flag_with_no_valid_id_reads_and_deletes_nothing( $value ) {
		$store = Mockery::mock( OrphanAttachmentStore::class );
		$store->shouldReceive( 'pointer_attachments' )->never();
		Functions\expect( 'wp_delete_attachment' )->never();

		( new PruneOrphanAttachmentsCommand( $store ) )->prune( array(), array( 'product' => $value, 'execute' => true ) );

		$this->assertSame( 'No valid product id in --product; nothing was read or deleted.', \WP_CLI::get_calls( 'warning' )[0]['args'][0] );
		$this->assertSame( array(), $this->logs() );
		$this->assertCount( 0, \WP_CLI::get_calls( 'success' ) );
	}

	/**
	 * Skipped products are listed and counted, never pruned.
	 */
	public function test_skipped_products_are_reported() {
		$store = Mockery::mock( OrphanAttachmentStore::class );
		$store->shouldReceive( 'pointer_attachments' )->andReturn(
			array(
				5 => array( 11 => 'aaa' ),
				7 => array( 20 => 'ccc' ),
			)
		);
		$store->shouldReceive( 'media_cells' )->andReturn( array( 5 => array( '[{"sha256":"aaa"' ) ) );
		$store->shouldReceive( 'wired_ids' )->andReturn( array() );
		Functions\expect( 'wp_delete_attachment' )->never();

		( new PruneOrphanAttachmentsCommand( $store ) )->prune( array(), array( 'execute' => true ) );

		$this->assertSame(
			array(
				'product 5 | pointer attachments 1 | skipped: invalid _fa_media',
				'product 7 | pointer attachments 1 | skipped: no _fa_media',
				'TOTAL products 2 | pointer attachments 2 | current 0 | orphans 0 | wired orphans 0 | skipped invalid cell 1 | skipped no cell 1 | deleted 0 | delete failures 0',
			),
			$this->logs()
		);
	}

	/**
	 * With no pointer attachment in scope the command says so and stops.
	 */
	public function test_nothing_in_scope_warns_and_stops() {
		$store = Mockery::mock( OrphanAttachmentStore::class );
		$store->shouldReceive( 'pointer_attachments' )->once()->andReturn( array() );
		$store->shouldReceive( 'media_cells' )->never();

		( new PruneOrphanAttachmentsCommand( $store ) )->prune( array(), array() );

		$this->assertSame( 'No pointer attachments in scope.', \WP_CLI::get_calls( 'warning' )[0]['args'][0] );
		$this->assertSame( array(), $this->logs() );
	}
}
