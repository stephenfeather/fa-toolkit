<?php
/**
 * Tests for AfterImportAttributes.
 *
 * @package FAToolkit\Tests\Attributes
 */

namespace FAToolkit\Tests\Attributes;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FAToolkit\Attributes\AfterImportAttributes;
use FAToolkit\Attributes\AttributeUnpackRunner;
use FAToolkit\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * AfterImportAttributes: unpacks `_fa_attributes` once a Super Speedy Imports
 * run finishes, at priority 20 behind the media listener, with the media
 * listener's drain loop, budgets, summary line and action.
 */
class AfterImportAttributesTest extends TestCase {

	/**
	 * A runner mock.
	 *
	 * @return \Mockery\MockInterface&AttributeUnpackRunner
	 */
	private function runner() {
		return Mockery::mock( AttributeUnpackRunner::class );
	}

	/**
	 * A completed-run result in the runner's shape.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function run_result( array $overrides = array() ) {
		return array_merge(
			array(
				'products'     => 0,
				'written'      => 0,
				'cleared'      => 0,
				'unchanged'    => 0,
				'invalid'      => 0,
				'skipped'      => 0,
				'collisions'   => 0,
				'unsupported'  => 0,
				'write_failed' => 0,
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
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
	}

	/**
	 * Collect what the run action reports.
	 *
	 * @param array $reported Filled with each summary, by reference.
	 * @return void
	 */
	private function capture_action( array &$reported ) {
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) use ( &$reported ) {
				if ( 'fa_toolkit_after_import_attributes_run' === $hook ) {
					$reported[] = $args[0];
				}
			}
		);
	}

	/**
	 * The constructor hooks the run onto SSI's after-stages action at priority
	 * 20, behind the media listener at 10.
	 */
	public function test_constructor_registers_on_ssi_after_import_stages_at_priority_20() {
		$listener = new AfterImportAttributes( $this->runner() );

		$this->assertSame( 20, has_action( 'superspeedyimports_after_import_stages', array( $listener, 'run' ) ) );
	}

	/**
	 * `fa_toolkit_after_import_attributes_enabled`, returning false, makes the
	 * listener a no-op that touches no product.
	 */
	public function test_run_does_nothing_when_disabled_by_filter() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->never();
		$runner->shouldReceive( 'run' )->never();
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_enabled' )->once()->with( true )->andReturn( false );

		$this->assertNull( ( new AfterImportAttributes( $runner ) )->run( new \stdClass() ) );
	}

	/**
	 * With nothing to unpack (the price import, or an unchanged catalogue) the
	 * run costs the selection query and the no-cell count, and still reports,
	 * so an unmapped profile reads as a number and not as silence.
	 */
	public function test_run_reports_even_when_no_product_needs_work() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array() );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 70619 );
		$runner->shouldReceive( 'run' )->never();
		$this->stub_wordpress();
		$reported = array();
		$this->capture_action( $reported );

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 0, $summary['products'] );
		$this->assertSame( 0, $summary['batches'] );
		$this->assertSame( 0, $summary['stuck'] );
		$this->assertSame( 'empty', $summary['stopped'] );
		$this->assertSame( 70619, $summary['no_attributes_cell'] );
		$this->assertSame( array( $summary ), $reported );
	}

	/**
	 * The summary carries the runner's totals plus the drain fields, in one
	 * flat array, which is what the log line and the action hand on.
	 */
	public function test_run_summary_has_the_full_shape() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->twice()->with( 200, array() )->andReturn( array( 4, 8 ), array() );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 4, 8 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'written' => 1, 'cleared' => 1, 'collisions' => 3 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 5 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame(
			array(
				'products',
				'written',
				'cleared',
				'unchanged',
				'invalid',
				'skipped',
				'collisions',
				'unsupported',
				'write_failed',
				'no_attributes_cell',
				'limit',
				'batches',
				'stopped',
				'stuck',
				'elapsed_ms',
				'elapsed_ms_per_batch',
			),
			array_keys( $summary )
		);
		$this->assertSame( 2, $summary['products'] );
		$this->assertSame( 1, $summary['written'] );
		$this->assertSame( 1, $summary['cleared'] );
		$this->assertSame( 3, $summary['collisions'] );
		$this->assertSame( 5, $summary['no_attributes_cell'] );
		$this->assertSame( 200, $summary['limit'] );
		$this->assertSame( 1, $summary['batches'] );
		$this->assertIsInt( $summary['elapsed_ms'] );
		$this->assertCount( 1, $summary['elapsed_ms_per_batch'] );
	}

	/**
	 * The log line goes to WP-CLI when it is loaded, as one JSON summary.
	 */
	public function test_run_logs_one_json_line() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array() );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();
		\WP_CLI::$calls = array();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$logged = array_filter( \WP_CLI::$calls, fn( $call ) => 'log' === $call['method'] );
		$this->assertSame(
			array( 'fa-toolkit after-import attributes: ' . json_encode( $summary ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array_column( array_values( $logged ), 'args' )[0]
		);
	}

	/**
	 * The per-batch cap is filterable.
	 */
	public function test_run_limit_is_filterable() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 25, array() )->andReturn( array() );
		$runner->shouldReceive( 'products_without_attributes_cell' )->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_limit' )->once()->with( 200 )->andReturn( 25 );
		$this->stub_wordpress();

		( new AfterImportAttributes( $runner ) )->run( new \stdClass() );
	}

	/**
	 * The queue is drained batch by batch, never in dry run, and totals add up
	 * across batches.
	 */
	public function test_run_drains_the_queue_batch_by_batch() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )
			->times( 3 )
			->with( 200, array() )
			->andReturn( array( 1, 2 ), array( 3 ), array() );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 1, 2 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'written' => 2 ) ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 3 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 1, 'unchanged' => 1, 'unsupported' => 4 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 3, $summary['products'] );
		$this->assertSame( 2, $summary['written'] );
		$this->assertSame( 1, $summary['unchanged'] );
		$this->assertSame( 4, $summary['unsupported'] );
		$this->assertSame( 2, $summary['batches'] );
		$this->assertSame( 'empty', $summary['stopped'] );
	}

	/**
	 * A product that keeps coming back (an invalid cell or a failed write keeps
	 * no marker, by design) is excluded from later selections, so the drain
	 * reaches the products queued behind it.
	 */
	public function test_run_excludes_persistently_selected_products_and_keeps_going() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )
			->twice()
			->with( 200, array() )
			->andReturn( array( 1, 2 ), array( 1, 2 ) );
		$runner->shouldReceive( 'products_to_unpack' )
			->twice()
			->with( 200, array( 1, 2 ) )
			->andReturn( array( 9 ), array() );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 2 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'invalid' => 2 ) ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 9 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 1, 'written' => 1 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 1, $summary['written'] );
		$this->assertSame( 2, $summary['stuck'] );
		$this->assertSame( 'empty', $summary['stopped'] );
	}

	/**
	 * A selection that returns only products already processed, with nothing
	 * newly stuck to exclude, stops as no_progress rather than spinning.
	 */
	public function test_run_stops_on_no_progress_when_the_selection_stands_still() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array( 1 ) );
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array( 1 ) );
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array( 1 ) )->andReturn( array( 1 ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 1, 'write_failed' => 1 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 'no_progress', $summary['stopped'] );
		$this->assertSame( 1, $summary['stuck'] );
		$this->assertSame( 1, $summary['batches'] );
	}

	/**
	 * `fa_toolkit_after_import_attributes_drain`, returning false, does one
	 * batch and hands the rest to the CLI.
	 */
	public function test_run_does_one_batch_when_drain_is_disabled() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_drain' )->once()->with( true )->andReturn( false );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 1, $summary['batches'] );
		$this->assertSame( 'single_batch', $summary['stopped'] );
	}

	/**
	 * A total-product budget bounds the drain; the last batch asks only for
	 * what is left of it.
	 */
	public function test_run_respects_the_max_products_budget() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 2, array() )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 1, array() )->andReturn( array( 3 ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 2 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 3 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 1 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_limit' )->once()->with( 200 )->andReturn( 2 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_max_products' )->once()->with( 0 )->andReturn( 3 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 3, $summary['products'] );
		$this->assertSame( 2, $summary['batches'] );
		$this->assertSame( 'max_products', $summary['stopped'] );
	}

	/**
	 * A batch size of 0 inside a product budget asks for the budget's
	 * remainder, never for "no limit".
	 */
	public function test_run_never_asks_for_an_unbounded_batch_inside_a_product_budget() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 3, array() )->andReturn( array( 1, 2, 3 ) );
		$runner->shouldReceive( 'run' )->once()->with( array( 1, 2, 3 ), false, null )
			->andReturn( $this->run_result( array( 'products' => 3 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_limit' )->once()->with( 200 )->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_max_products' )->once()->with( 0 )->andReturn( 3 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 'max_products', $summary['stopped'] );
	}

	/**
	 * A time budget stops the drain after the batch that crosses it.
	 */
	public function test_run_respects_the_time_budget() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 200, array() )->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->run_result( array( 'products' => 2 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_attributes_max_seconds' )->once()->with( 0.0 )->andReturn( 0.000001 );
		$this->stub_wordpress();

		$summary = ( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 1, $summary['batches'] );
		$this->assertSame( 'max_seconds', $summary['stopped'] );
	}

	/**
	 * Between batches the object cache is dropped with the runtime-only flush
	 * where WordPress offers one, never with the shared-backend flush.
	 *
	 * Isolated: defining `wp_cache_flush_runtime()` here would leave it defined
	 * for every later test in the process, the media listener's included.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_prefers_the_runtime_cache_flush_between_batches() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )
			->times( 3 )
			->with( 200, array() )
			->andReturn( array( 1 ), array( 2 ), array() );
		$runner->shouldReceive( 'run' )->twice()->andReturn( $this->run_result( array( 'products' => 1 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );
		$runtime = 0;
		Functions\when( 'wp_cache_flush_runtime' )->alias(
			function () use ( &$runtime ) {
				++$runtime;
				return true;
			}
		);
		Functions\expect( 'wp_cache_flush' )->never();

		( new AfterImportAttributes( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $runtime );
	}

	/**
	 * With no runtime flush and a persistent object cache, flush nothing.
	 * Isolated: a defined PHP function outlives the test that defined it.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_does_not_flush_a_shared_persistent_object_cache() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )
			->times( 3 )
			->with( 200, array() )
			->andReturn( array( 1 ), array( 2 ), array() );
		$runner->shouldReceive( 'run' )->twice()->andReturn( $this->run_result( array( 'products' => 1 ) ) );
		$runner->shouldReceive( 'products_without_attributes_cell' )->once()->andReturn( 0 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_flush' )->never();

		( new AfterImportAttributes( $runner ) )->run( new \stdClass() );
	}
}
