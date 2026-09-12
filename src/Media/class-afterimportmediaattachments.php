<?php
/**
 * Creates pointer attachments for newly imported `_fa_media` after an SSI run.
 *
 * @package    fa-toolkit
 * @since 1.2.1
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * After-import listener for the content import.
 *
 * Super Speedy Imports writes postmeta with direct SQL, so no per-product
 * WordPress hook fires while it runs. Its `superspeedyimports_after_import_stages`
 * action fires once at the end of every run (SSI 2.88.12, run-import.php:1206)
 * and is the seam this class uses. It fires for the price import too; that
 * import does not map `_fa_media`, so the selection below finds nothing new
 * and the run costs one query.
 *
 * Scope: products whose current `_fa_media` cell has not been applied (no
 * applied marker, or a marker for an older cell), taken in batches (issue #93).
 * Dead-URL healing, which unwires a stranded product, stays with the
 * operator's `wp fa:media create-remote-attachments` run.
 *
 * The batch is a batch, not a ceiling: an import mapping thousands of products
 * used to leave everything past the first 200 for a manual CLI sweep, so the
 * run drains batch after batch until the queue is empty (issue #100). Each
 * batch re-selects, which needs no offset bookkeeping because the selection is
 * marker-based and applied products drop out of the next query.
 *
 * Four things end a drain, and the summary says which:
 *
 * - `empty`        nothing left to attach.
 * - `no_progress`  a batch brought back only products already processed this
 *                  run. Failures deliberately keep no applied marker so they
 *                  stay selectable (#93, #95), so draining purely on "the
 *                  selection is empty" would re-run the same failures forever.
 * - `max_products` a total-product budget was spent.
 * - `max_seconds`  a time budget was spent.
 * - `single_batch` draining was switched off by filter.
 */
class AfterImportMediaAttachments {

	/**
	 * Default per-batch product count.
	 */
	private const DEFAULT_LIMIT = 200;

	/**
	 * The shared runner.
	 *
	 * @var RemoteAttachmentRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param RemoteAttachmentRunner|null $runner Runner; built when omitted.
	 */
	public function __construct( ?RemoteAttachmentRunner $runner = null ) {
		$this->runner = $runner ?? new RemoteAttachmentRunner();

		add_action( 'superspeedyimports_after_import_stages', array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Attach new media once the import's stages have finished.
	 *
	 * @param object $template The SSI import template; unused beyond the signature.
	 * @return array|null The run summary, or null when disabled.
	 */
	public function run( $template ) {
		unset( $template );

		if ( true !== (bool) apply_filters( 'fa_toolkit_after_import_media_enabled', true ) ) {
			return null;
		}

		$limit        = (int) apply_filters( 'fa_toolkit_after_import_media_limit', self::DEFAULT_LIMIT );
		$drain        = (bool) apply_filters( 'fa_toolkit_after_import_media_drain', true );
		$max_products = (int) apply_filters( 'fa_toolkit_after_import_media_max_products', 0 );
		$max_seconds  = (float) apply_filters( 'fa_toolkit_after_import_media_max_seconds', 0.0 );

		$summary = $this->empty_summary();
		$started = microtime( true );
		$seen    = array();
		$stuck   = array();
		$batches = 0;
		$stopped = 'empty';

		while ( true ) {
			// Checked before the selection as well as after the batch: the query
			// and the cache flush both take time, and a deadline that is only
			// read after a batch lets one more batch start past it.
			if ( $batches > 0 && $max_seconds > 0 && microtime( true ) - $started >= $max_seconds ) {
				$stopped = 'max_seconds';
				break;
			}

			$product_ids = $this->runner->products_with_unapplied_media(
				$this->batch_size( $limit, $max_products, count( $seen ) ),
				array_keys( $stuck )
			);

			if ( array() === $product_ids ) {
				break;
			}

			// Products that failed keep no applied marker, by design, so they come
			// back in the next selection (#93, #95) — and only PROBE failures sort
			// behind fresh work (#97), so a creation or write failure at a low id
			// heads every selection. Excluding those is what lets the drain reach
			// the products queued behind them; stopping here would strand them.
			$fresh   = array_values( array_diff( $product_ids, array_keys( $seen ) ) );
			$repeats = array_diff( $product_ids, $fresh );

			foreach ( $repeats as $product_id ) {
				$stuck[ $product_id ] = true;
			}

			if ( array() === $fresh ) {
				if ( array() === $repeats ) {
					$stopped = 'no_progress';
					break;
				}

				continue;
			}

			$summary = $this->merge( $summary, $this->runner->run( $fresh, false, false, null ) );
			++$batches;

			foreach ( $fresh as $product_id ) {
				$seen[ $product_id ] = true;
			}

			$spent = $this->budget_spent( $drain, $max_seconds, microtime( true ) - $started, $max_products, count( $seen ) );

			if ( null !== $spent ) {
				$stopped = $spent;
				break;
			}

			// Both caches a fresh WP-CLI process would have left behind: the
			// runner's probe cache is a plain property no object-cache flush
			// reaches, and the object cache grows for the length of a drain.
			$this->runner->reset_probe_cache();
			$this->flush_object_cache();
		}

		// Reported separately, always. An unmapped profile leaves the key absent
		// on every product, and that must read as a number, not as silence.
		$summary['no_media_cell'] = $this->runner->products_without_media_cell();
		$summary['limit']         = $limit;
		$summary['batches']       = $batches;
		$summary['stopped']       = $stopped;

		// Products this run could not apply and stopped asking for. Without this
		// an early finish reads as a clean sweep.
		$summary['stuck'] = count( $stuck );

		$this->log( $summary );

		/**
		 * Fires after the after-import media pass with its summary.
		 *
		 * @param array $summary Counts: products, created, existing, unreachable,
		 *                       probe_failed, failed, write_failed, no_media,
		 *                       stranded, no_media_cell, limit, batches, and
		 *                       stopped (empty, no_progress, max_products,
		 *                       max_seconds or single_batch).
		 */
		do_action( 'fa_toolkit_after_import_media_run', $summary );

		return $summary;
	}

	/**
	 * How many products to ask for next.
	 *
	 * A full batch, except where a total-product budget leaves less than one
	 * batch of room: the last batch asks only for what is left of the budget.
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

		// The runner reads 0 as "no limit", so an unbounded batch size inside a
		// product budget has to become the budget's remainder — otherwise the
		// first selection returns the whole queue and spends the budget it was
		// meant to bound (PR #101 review).
		if ( $limit < 1 ) {
			return $remaining;
		}

		return min( $limit, $remaining );
	}

	/**
	 * Drop the object cache between batches, without emptying a shared one.
	 *
	 * `wp_cache_flush()` purges a persistent Redis or Memcached backend for the
	 * whole site, which is not the process-local cleanup a fresh WP-CLI process
	 * gives. Prefer the runtime-only flush; failing that, flush only where no
	 * persistent backend is in play (PR #101 review).
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
	 * A zeroed summary, the shape `RemoteAttachmentRunner::run()` returns.
	 *
	 * @return array
	 */
	private function empty_summary() {
		return array(
			'products'     => 0,
			'created'      => 0,
			'existing'     => 0,
			'unreachable'  => 0,
			'probe_failed' => 0,
			'failed'       => 0,
			'write_failed' => 0,
			'no_media'     => 0,
			'stranded'     => array(),
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
			if ( 'stranded' === $key ) {
				continue;
			}

			$totals[ $key ] = $value + (int) ( $batch[ $key ] ?? 0 );
		}

		$totals['stranded'] = array_values(
			array_unique( array_merge( $totals['stranded'], $batch['stranded'] ?? array() ) )
		);

		return $totals;
	}

	/**
	 * Write the summary where the operator running the import will see it.
	 *
	 * @param array $summary Run summary.
	 * @return void
	 */
	private function log( array $summary ) {
		$line = 'fa-toolkit after-import media: ' . wp_json_encode( $summary );

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $line );
			return;
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
