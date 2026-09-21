<?php
/**
 * Unpacks newly imported `_fa_attributes` after an SSI run.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Attributes;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * After-import listener for `_fa_attributes`, on the same seam as the media
 * listener: Super Speedy Imports writes postmeta with direct SQL, and its
 * `superspeedyimports_after_import_stages` action fires once at the end of
 * every run. This listener sits at priority 20, behind
 * `AfterImportMediaAttachments` at 10.
 *
 * The hook fires for the price import too. That import does not map
 * `_fa_attributes`, so the selection finds nothing and the run costs the
 * selection query and the no-cell count.
 *
 * The drain loop is the media listener's (issue #100): batch after batch
 * until the queue is empty, re-selecting each time because the selection is
 * marker-based and unpacked products drop out of the next query. Its stop
 * reasons are the same, and the summary says which:
 *
 * - `empty`        nothing left to unpack.
 * - `no_progress`  a batch brought back only products already processed this
 *                  run. An invalid cell and a failed write keep no marker by
 *                  design, so they stay selectable (#114).
 * - `max_products` a total-product budget was spent.
 * - `max_seconds`  a time budget was spent.
 * - `single_batch` draining was switched off by filter.
 *
 * Extracting the loop shared with the media listener is its own refactor
 * (issue #115, "Not in scope"). Budget defaults stay unset until #117
 * measures a pass.
 */
class AfterImportAttributes {

	/**
	 * Default per-batch product count.
	 */
	private const DEFAULT_LIMIT = 200;

	/**
	 * The shared runner.
	 *
	 * @var AttributeUnpackRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param AttributeUnpackRunner|null $runner Runner; built when omitted.
	 */
	public function __construct( ?AttributeUnpackRunner $runner = null ) {
		$this->runner = $runner ?? new AttributeUnpackRunner();

		add_action( 'superspeedyimports_after_import_stages', array( $this, 'run' ), 20, 1 );
	}

	/**
	 * Unpack attributes once the import's stages have finished.
	 *
	 * @param object $template The SSI import template; unused beyond the signature.
	 * @return array|null The run summary, or null when disabled.
	 */
	public function run( $template ) {
		unset( $template );

		if ( true !== (bool) apply_filters( 'fa_toolkit_after_import_attributes_enabled', true ) ) {
			return null;
		}

		$limit        = (int) apply_filters( 'fa_toolkit_after_import_attributes_limit', self::DEFAULT_LIMIT );
		$drain        = (bool) apply_filters( 'fa_toolkit_after_import_attributes_drain', true );
		$max_products = (int) apply_filters( 'fa_toolkit_after_import_attributes_max_products', 0 );
		$max_seconds  = (float) apply_filters( 'fa_toolkit_after_import_attributes_max_seconds', 0.0 );

		$summary     = $this->empty_summary();
		$started     = microtime( true );
		$seen        = array();
		$stuck       = array();
		$batches     = 0;
		$batch_times = array();
		$stopped     = 'empty';

		while ( true ) {
			$batch_started = microtime( true );

			if ( $this->deadline_passed( $batches, $max_seconds, microtime( true ) - $started ) ) {
				$stopped = 'max_seconds';
				break;
			}

			$product_ids = $this->runner->products_to_unpack(
				$this->batch_size( $limit, $max_products, count( $seen ) ),
				array_keys( $stuck )
			);

			if ( array() === $product_ids ) {
				break;
			}

			$known = count( $stuck );
			$fresh = $this->unprocessed( $product_ids, $seen, $stuck );

			if ( array() === $fresh ) {
				// Nothing new and nothing newly stuck: the selection is standing
				// still in a way excluding cannot move, so stop rather than spin.
				if ( count( $stuck ) === $known ) {
					$stopped = 'no_progress';
					break;
				}

				continue;
			}

			$summary = $this->merge( $summary, $this->runner->run( $fresh, false, null ) );
			++$batches;
			$batch_times[] = self::milliseconds( microtime( true ) - $batch_started );

			foreach ( $fresh as $product_id ) {
				$seen[ $product_id ] = true;
			}

			$spent = $this->budget_spent( $drain, $max_seconds, microtime( true ) - $started, $max_products, count( $seen ) );

			if ( null !== $spent ) {
				$stopped = $spent;
				break;
			}

			// The object cache grows for the length of a drain; a fresh WP-CLI
			// process would not have carried it.
			$this->flush_object_cache();
		}

		// Reported separately, always. An unmapped profile leaves the key absent
		// on every product, and that must read as a number, not as silence.
		$summary['no_attributes_cell'] = $this->runner->products_without_attributes_cell();
		$summary['limit']              = $limit;
		$summary['batches']            = $batches;
		$summary['stopped']            = $stopped;

		// Products this run could not unpack and stopped asking for. Without
		// this an early finish reads as a clean sweep.
		$summary['stuck'] = count( $stuck );

		// What the drain cost, for choosing a budget default (#117). Per-batch
		// times cover selection through merge and exclude the between-batch
		// flush, so they sum to less than the total.
		$summary['elapsed_ms']           = self::milliseconds( microtime( true ) - $started );
		$summary['elapsed_ms_per_batch'] = $batch_times;

		$this->log( $summary );

		/**
		 * Fires after the after-import attributes pass with its summary.
		 *
		 * @param array $summary Counts: products, written, cleared, unchanged,
		 *                       invalid, skipped, collisions, unsupported,
		 *                       write_failed, no_attributes_cell, limit, batches,
		 *                       stopped (empty, no_progress, max_products,
		 *                       max_seconds or single_batch), stuck, elapsed_ms
		 *                       and elapsed_ms_per_batch.
		 */
		do_action( 'fa_toolkit_after_import_attributes_run', $summary );

		return $summary;
	}

	/**
	 * Seconds as whole milliseconds.
	 *
	 * @param float $seconds Elapsed seconds.
	 * @return int
	 */
	private static function milliseconds( $seconds ) {
		return (int) round( $seconds * 1000 );
	}

	/**
	 * Whether the wall-clock budget is already spent.
	 *
	 * Read before a selection as well as after a batch, so a deadline crossed
	 * during the query or the flush does not let one more batch start. Never
	 * true before the first batch, so a tight budget still does one batch.
	 *
	 * @param int   $batches     Batches run so far.
	 * @param float $max_seconds Wall-clock budget, 0 for none.
	 * @param float $elapsed     Seconds spent so far.
	 * @return bool
	 */
	private function deadline_passed( $batches, $max_seconds, $elapsed ) {
		return $batches > 0 && $max_seconds > 0 && $elapsed >= $max_seconds;
	}

	/**
	 * The products in a selection not already processed this run.
	 *
	 * Anything the selection hands back a second time is recorded as stuck, so
	 * later selections can exclude it and the drain reaches the products
	 * queued behind it.
	 *
	 * @param array<int, int>  $product_ids The selection.
	 * @param array<int, bool> $seen        Products processed this run, keyed by id.
	 * @param array<int, bool> $stuck       Products to exclude, keyed by id; added to here.
	 * @return array<int, int>
	 */
	private function unprocessed( array $product_ids, array $seen, array &$stuck ) {
		$fresh = array_values( array_diff( $product_ids, array_keys( $seen ) ) );

		foreach ( array_diff( $product_ids, $fresh ) as $product_id ) {
			$stuck[ $product_id ] = true;
		}

		return $fresh;
	}

	/**
	 * How many products to ask for next.
	 *
	 * A full batch, except where a total-product budget leaves less than one
	 * batch of room. The runner reads 0 as "no limit", so an unbounded batch
	 * inside a product budget becomes the budget's remainder.
	 *
	 * @param int $limit        Products per batch.
	 * @param int $max_products Total-product budget, 0 for none.
	 * @param int $processed    Products processed so far this run.
	 * @return int
	 */
	private function batch_size( $limit, $max_products, $processed ) {
		if ( $max_products < 1 ) {
			return $limit;
		}

		$remaining = $max_products - $processed;

		if ( $limit < 1 ) {
			return $remaining;
		}

		return min( $limit, $remaining );
	}

	/**
	 * Drop the object cache between batches, without emptying a shared one.
	 *
	 * `wp_cache_flush()` purges a persistent backend for the whole site.
	 * Prefer the runtime-only flush; failing that, flush only where no
	 * persistent backend is in play.
	 *
	 * @return void
	 */
	private function flush_object_cache() {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}

		if ( true !== wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * Which budget, if any, ends the drain after the batch just finished.
	 *
	 * @param bool  $drain        Whether draining is on at all.
	 * @param float $max_seconds  Wall-clock budget, 0 for none.
	 * @param float $elapsed      Seconds spent so far.
	 * @param int   $max_products Total-product budget, 0 for none.
	 * @param int   $processed    Products processed so far this run.
	 * @return string|null One of single_batch, max_seconds, max_products; null to carry on.
	 */
	private function budget_spent( $drain, $max_seconds, $elapsed, $max_products, $processed ) {
		if ( true !== $drain ) {
			return 'single_batch';
		}

		if ( $max_seconds > 0 && $elapsed >= $max_seconds ) {
			return 'max_seconds';
		}

		if ( $max_products > 0 && $processed >= $max_products ) {
			return 'max_products';
		}

		return null;
	}

	/**
	 * A zeroed summary, the shape `AttributeUnpackRunner::run()` returns.
	 *
	 * @return array<string, int>
	 */
	private function empty_summary() {
		return array(
			'products'     => 0,
			'written'      => 0,
			'cleared'      => 0,
			'unchanged'    => 0,
			'invalid'      => 0,
			'skipped'      => 0,
			'collisions'   => 0,
			'unsupported'  => 0,
			'write_failed' => 0,
		);
	}

	/**
	 * Add one batch's result onto the running totals.
	 *
	 * @param array $totals Totals so far.
	 * @param array $batch  One batch's result.
	 * @return array
	 */
	private function merge( array $totals, array $batch ) {
		foreach ( $totals as $key => $value ) {
			$totals[ $key ] = $value + (int) ( $batch[ $key ] ?? 0 );
		}

		return $totals;
	}

	/**
	 * Write the summary where the operator running the import will see it.
	 *
	 * @param array $summary Run summary.
	 * @return void
	 */
	private function log( array $summary ) {
		$line = 'fa-toolkit after-import attributes: ' . wp_json_encode( $summary );

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $line );
			return;
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
