<?php
/**
 * Tests for AfterImportMediaAttachments.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FAToolkit\Media\AfterImportMediaAttachments;
use FAToolkit\Media\RemoteAttachmentRunner;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * AfterImportMediaAttachments: creates pointer attachments for newly imported
 * `_fa_media` once a Super Speedy Imports run finishes.
 *
 * SSI writes postmeta with direct SQL, so no per-product WordPress hook fires
 * during the import; `superspeedyimports_after_import_stages` fires once at
 * the end of every run (run-import.php:1206 in SSI 2.88.12) and is the seam.
 */
class AfterImportMediaAttachmentsTest extends TestCase {

	/**
	 * A runner mock.
	 *
	 * @return \Mockery\MockInterface&RemoteAttachmentRunner
	 */
	private function runner() {
		return Mockery::mock( RemoteAttachmentRunner::class );
	}

	/**
	 * A completed-run result.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function run_result( array $overrides = array() ) {
		return array_merge(
			array(
				'products'     => 0,
				'created'      => 0,
				'existing'     => 0,
				'unreachable'  => 0,
				'probe_failed' => 0,
				'failed'       => 0,
				'write_failed' => 0,
				'no_media'     => 0,
				'stranded'     => array(),
			),
			$overrides
		);
	}

	/**
	 * Stub the WordPress functions the listener reaches for while draining.
	 *
	 * @return void
	 */
	private function stub_wordpress() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
	}

	/**
	 * The constructor hooks the run onto SSI's after-stages action.
	 */
	public function test_constructor_registers_on_ssi_after_import_stages() {
		$listener = new AfterImportMediaAttachments( $this->runner() );

		$this->assertNotFalse( has_action( 'superspeedyimports_after_import_stages', array( $listener, 'run' ) ) );
	}

	/**
	 * The `fa_toolkit_after_import_media_enabled` filter, returning false,
	 * turns the listener into a no-op that touches no product.
	 */
	public function test_run_does_nothing_when_disabled_by_filter() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->never();
		$runner->shouldReceive( 'run' )->never();
		Filters\expectApplied( 'fa_toolkit_after_import_media_enabled' )->once()->with( true )->andReturn( false );

		$this->assertNull( ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() ) );
	}

	/**
	 * With nothing new to attach the listener still reports, so an unmapped
	 * profile shows up as a count of products with no cell rather than silence.
	 */
	public function test_run_reports_even_when_no_product_needs_work() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 200 )->andReturn( array() );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 70619 );
		$runner->shouldReceive( 'run' )->never();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		$reported = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) use ( &$reported ) {
				if ( 'fa_toolkit_after_import_media_run' === $hook ) {
					$reported[] = $args[0];
				}
			}
		);

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 0, $summary['products'] );
		$this->assertSame( 0, $summary['batches'] );
		$this->assertSame( 'empty', $summary['stopped'] );
		$this->assertSame( 70619, $summary['no_media_cell'] );
		$this->assertSame( array( $summary ), $reported );
	}

	/**
	 * Products carrying media with no attachment yet are run through the
	 * creator, never in dry-run, never probing safe URLs, and the result is
	 * merged with the no-cell count into one summary.
	 */
	public function test_run_attaches_unattached_products_and_summarises() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )
			->twice()
			->with( 200 )
			->andReturn( array( 4, 8 ), array() );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 4, 8 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'created' => 3, 'stranded' => array( 8 ) ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 5 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $summary['products'] );
		$this->assertSame( 3, $summary['created'] );
		$this->assertSame( array( 8 ), $summary['stranded'] );
		$this->assertSame( 5, $summary['no_media_cell'] );
	}

	/**
	 * The per-batch product cap is filterable, so an operator can raise it for a
	 * bulk catch-up or lower it on a slow host.
	 */
	public function test_run_limit_is_filterable() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 25 )->andReturn( array() );
		$runner->shouldReceive( 'products_without_media_cell' )->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_limit' )->once()->with( 200 )->andReturn( 25 );
		$this->stub_wordpress();

		( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );
	}

	/**
	 * The whole queue is drained in batches, not just the first batch: an import
	 * mapping more products than one batch holds must leave nothing behind
	 * (issue #100). Totals accumulate across batches.
	 */
	public function test_run_drains_the_queue_batch_by_batch() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )
			->times( 3 )
			->with( 200 )
			->andReturn( array( 1, 2 ), array( 3 ), array() );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 1, 2 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'created' => 4, 'stranded' => array( 2 ) ) ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 3 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 1, 'created' => 1, 'existing' => 7 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 3, $summary['products'] );
		$this->assertSame( 5, $summary['created'] );
		$this->assertSame( 7, $summary['existing'] );
		$this->assertSame( array( 2 ), $summary['stranded'] );
		$this->assertSame( 2, $summary['batches'] );
		$this->assertSame( 'empty', $summary['stopped'] );
	}

	/**
	 * A product that fails gets no applied marker on purpose, so it stays
	 * selectable (#93, #95). Draining until the selection empties would then
	 * re-select the same products forever, so the drain stops as soon as a
	 * batch brings back nothing it has not already processed.
	 */
	public function test_run_stops_when_a_batch_repeats_already_processed_products() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )
			->twice()
			->with( 200 )
			->andReturn( array( 1, 2 ), array( 1, 2 ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 1, 2 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'probe_failed' => 2 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $summary['products'] );
		$this->assertSame( 1, $summary['batches'] );
		$this->assertSame( 'no_progress', $summary['stopped'] );
	}

	/**
	 * A batch that brings back a mix of already-processed and new products is
	 * progress, so the drain continues rather than stopping on the repeats.
	 */
	public function test_run_continues_when_a_batch_is_partly_new() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )
			->times( 3 )
			->with( 200 )
			->andReturn( array( 1, 2 ), array( 1, 3 ), array( 1 ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 2 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 3 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $summary['batches'] );
		$this->assertSame( 'no_progress', $summary['stopped'] );
	}

	/**
	 * `fa_toolkit_after_import_media_drain`, returning false, restores the old
	 * single-batch behaviour for an operator who wants the import to hand the
	 * rest to the CLI.
	 */
	public function test_run_does_one_batch_when_drain_is_disabled() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 200 )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_drain' )->once()->with( true )->andReturn( false );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 1, $summary['batches'] );
		$this->assertSame( 'single_batch', $summary['stopped'] );
	}

	/**
	 * A total-product budget bounds the whole drain, and the last batch asks
	 * only for what is left of it rather than a full batch.
	 */
	public function test_run_respects_the_max_products_budget() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 2 )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 1 )->andReturn( array( 3 ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 2 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 3 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 1 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_limit' )->once()->with( 200 )->andReturn( 2 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_max_products' )->once()->with( 0 )->andReturn( 3 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 3, $summary['products'] );
		$this->assertSame( 2, $summary['batches'] );
		$this->assertSame( 'max_products', $summary['stopped'] );
	}

	/**
	 * A time budget stops the drain after the batch that crosses it, leaving
	 * the rest to the operator's CLI run rather than holding the import open.
	 */
	public function test_run_respects_the_time_budget() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )->once()->with( 200 )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_max_seconds' )->once()->with( 0.0 )->andReturn( 0.000001 );
		$this->stub_wordpress();

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 1, $summary['batches'] );
		$this->assertSame( 'max_seconds', $summary['stopped'] );
	}

	/**
	 * The object cache is flushed between batches. A single long-lived process
	 * otherwise grows for the length of the drain; the operator's chunked CLI
	 * runs stay bounded only because each chunk is a fresh process.
	 */
	public function test_run_flushes_the_object_cache_between_batches() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_with_unapplied_media' )
			->times( 3 )
			->with( 200 )
			->andReturn( array( 1 ), array( 2 ), array() );
		$runner->shouldReceive( 'run' )->twice()->andReturn( $this->run_result( array( 'products' => 1 ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 0 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );
		$flushes = 0;
		Functions\when( 'wp_cache_flush' )->alias(
			function () use ( &$flushes ) {
				++$flushes;
				return true;
			}
		);

		( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $flushes );
	}
}
