<?php
/**
 * Tests for UnpackAttributesCommand.
 *
 * @package FAToolkit\Tests\CLI\Attributes
 */

namespace FAToolkit\Tests\CLI\Attributes;

use Brain\Monkey\Functions;
use FAToolkit\Attributes\AttributeUnpackRunner;
use FAToolkit\CLI\Attributes\UnpackAttributesCommand;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * UnpackAttributesCommand: `wp fa:attributes unpack`.
 *
 * The unpack, the selection and the writes live in AttributeUnpackRunner and
 * are tested there. These tests cover what the command owns: mapping flags
 * onto runner arguments, the product-id check, and the output. Issue #116.
 */
class UnpackAttributesCommandTest extends TestCase {

	/**
	 * Reset the WP_CLI recorder and stub the progress bar.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		Functions\when( 'WP_CLI\Utils\make_progress_bar' )->justReturn(
			new class() {
				/**
				 * No-op: progress output is not under test.
				 */
				public function tick() {
					// Intentionally empty.
				}
				/**
				 * No-op: progress output is not under test.
				 */
				public function finish() {
					// Intentionally empty.
				}
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

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
	private function totals( array $overrides = array() ) {
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
		$command = new UnpackAttributesCommand( $this->runner() );

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:attributes unpack', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'unpack' ), $calls[0]['args'][1] );
	}

	/**
	 * With no flags the selection is the runner's own, uncapped and
	 * marker-based, and the run is real.
	 */
	public function test_default_run_uses_the_marker_based_selection() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 0, array(), false )->andReturn( array( 3, 9 ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 3, 9 ), false, Mockery::type( 'callable' ) )
			->andReturn( $this->totals( array( 'products' => 2, 'written' => 2 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array() );

		$this->assertSame( 'Done.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * `--limit` and `--force` reach the selection as its cap and its
	 * marker-ignoring mode.
	 */
	public function test_limit_and_force_map_onto_the_selection() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->with( 50, array(), true )->andReturn( array( 1 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->totals( array( 'products' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'limit' => '50', 'force' => true ) );
	}

	/**
	 * `--dry-run` reaches the runner as its dry run, and the command says
	 * nothing was written.
	 */
	public function test_dry_run_reaches_the_runner_as_dry_run() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->andReturn( array( 1 ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 1 ), true, Mockery::type( 'callable' ) )
			->andReturn( $this->totals( array( 'products' => 1, 'written' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'dry-run' => true ) );

		$this->assertSame( 'Dry run: nothing was written.', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * An empty selection warns and stops before the runner is asked to run.
	 */
	public function test_warns_and_stops_when_nothing_is_selected() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->andReturn( array() );
		$runner->shouldReceive( 'run' )->never();

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array() );

		$warnings = \WP_CLI::get_calls( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'Nothing to unpack', $warnings[0]['args'][0] );
		$this->assertCount( 0, \WP_CLI::get_calls( 'success' ) );
	}

	/**
	 * `--product` bypasses the selection and runs exactly that product.
	 */
	public function test_product_flag_runs_that_product_without_selecting() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->never();
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 7 ), false, Mockery::type( 'callable' ) )
			->andReturn( $this->totals( array( 'products' => 1, 'written' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => '7' ) );

		$this->assertStringStartsWith( 'products 1 | written 1', $this->summary_line() );
	}

	/**
	 * A `--product` that is not a product is an error before anything runs.
	 */
	public function test_product_flag_rejects_an_unknown_id() {
		$runner = $this->runner();
		$runner->shouldReceive( 'run' )->never();
		Functions\when( 'get_post_type' )->justReturn( false );

		try {
			( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => '404' ) );
			$this->fail( 'Expected WP_CLI::error() to stop the command.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Product 404 not found.', $e->getMessage() );
		}
	}

	/**
	 * A `--product` that names a variation, or anything that is not a
	 * `product` post, is the same error: the runner would only skip it.
	 */
	public function test_product_flag_rejects_a_variation() {
		$runner = $this->runner();
		$runner->shouldReceive( 'run' )->never();
		Functions\when( 'get_post_type' )->justReturn( 'product_variation' );

		try {
			( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => '8' ) );
			$this->fail( 'Expected WP_CLI::error() to stop the command.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Post 8 is a product_variation, not a product.', $e->getMessage() );
		}
	}

	/**
	 * A `--product` that is not a positive integer is rejected as such.
	 */
	public function test_product_flag_rejects_a_non_id() {
		$runner = $this->runner();
		$runner->shouldReceive( 'run' )->never();

		try {
			( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => 'abc' ) );
			$this->fail( 'Expected WP_CLI::error() to stop the command.' );
		} catch ( \Exception $e ) {
			$this->assertSame( '--product must be a positive integer.', $e->getMessage() );
		}
	}

	/**
	 * Every runner total is printed, in the runner's order.
	 */
	public function test_totals_are_printed() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->andReturn( array( 1, 2, 3 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn(
			$this->totals(
				array(
					'products'     => 3,
					'written'      => 1,
					'cleared'      => 1,
					'unchanged'    => 0,
					'invalid'      => 1,
					'skipped'      => 0,
					'collisions'   => 2,
					'unsupported'  => 4,
					'write_failed' => 0,
				)
			)
		);

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array() );

		$this->assertSame(
			'products 3 | written 1 | cleared 1 | unchanged 0 | invalid 1 | skipped 0 | collisions 2 | unsupported 4 | write failures 0',
			$this->summary_line()
		);
	}

	/**
	 * Invalid cells and write failures are surfaced as warnings, since both
	 * leave the product selectable and the operator should know why a rerun
	 * keeps finding it.
	 */
	public function test_invalid_cells_and_write_failures_are_warned_about() {
		$runner = $this->runner();
		$runner->shouldReceive( 'products_to_unpack' )->once()->andReturn( array( 1, 2 ) );
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->totals( array( 'products' => 2, 'invalid' => 1, 'write_failed' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array() );

		$warnings = array_column( array_column( \WP_CLI::get_calls( 'warning' ), 'args' ), 0 );
		$this->assertSame(
			array(
				'1 products have a cell that is not a JSON object. They keep their previous attributes and stay selectable.',
				'1 products had a write fail. Re-running is safe and will retry them.',
			),
			$warnings
		);
	}

	/**
	 * With `--product`, the attribute row is printed before and after the run
	 * so a single cell can be checked on staging.
	 */
	public function test_product_flag_prints_the_row_before_and_after() {
		$before = array( 'colour' => array( 'name' => 'Colour', 'value' => 'Blue' ) );
		$after  = $before + array( 'material' => array( 'name' => 'Material', 'value' => 'Steel' ) );
		$rows   = array( $before, $after );
		Functions\when( 'get_post_meta' )->alias(
			function () use ( &$rows ) {
				return array_shift( $rows );
			}
		);
		$runner = $this->runner();
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->totals( array( 'products' => 1, 'written' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => '7' ) );

		$logs = array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
		$this->assertContains( 'Before: ' . json_encode( $before ), $logs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertContains( 'After: ' . json_encode( $after ), $logs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A dry run over `--product` prints the row once, as "before", and says
	 * the after row was not written.
	 */
	public function test_product_flag_dry_run_prints_the_row_once() {
		$before = array( 'colour' => array( 'name' => 'Colour', 'value' => 'Blue' ) );
		Functions\when( 'get_post_meta' )->justReturn( $before );
		$runner = $this->runner();
		$runner->shouldReceive( 'run' )->once()->andReturn( $this->totals( array( 'products' => 1, 'written' => 1 ) ) );

		( new UnpackAttributesCommand( $runner ) )->unpack( array(), array( 'product' => '7', 'dry-run' => true ) );

		$logs = array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
		$this->assertContains( 'Before: ' . json_encode( $before ), $logs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertContains( 'After: not written (dry run); outcome written.', $logs );
	}
}
