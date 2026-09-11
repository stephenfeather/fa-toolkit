<?php
/**
 * WP-CLI command creating pointer attachments from `_fa_media`.
 *
 * @package    fa-toolkit
 * @since 1.2.0
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Media\RemoteAttachmentRunner;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Creates WooCommerce attachments for images hosted on s3.
 *
 * The product loop, selection, probe and finder live in
 * RemoteAttachmentRunner, shared with the after-import listener. This class
 * owns flag parsing and WP-CLI output only.
 */
class CreateRemoteAttachmentsCommand {

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

		\WP_CLI::add_command( 'fa:media create-remote-attachments', array( $this, 'create' ) );
	}

	/**
	 * Create attachments for products carrying `_fa_media`.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after this many products.
	 *
	 * [--product=<id>]
	 * : Only this product id. Repeatable as a comma-separated list.
	 *
	 * [--dry-run]
	 * : Report without writing.
	 *
	 * [--recheck-all]
	 * : Probe every URL, not only the shapes known to contain dead links.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$limit       = (int) ( $assoc_args['limit'] ?? 0 );
		$dry_run     = isset( $assoc_args['dry-run'] );
		$recheck_all = isset( $assoc_args['recheck-all'] );

		$product_ids = $this->target_products( $assoc_args, $limit );

		if ( array() === $product_ids ) {
			\WP_CLI::warning( 'No products carry _fa_media. Has the content import run with the media column?' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Creating attachments', count( $product_ids ) );

		$totals = $this->runner->run(
			$product_ids,
			$dry_run,
			$recheck_all,
			function () use ( $progress ) {
				$progress->tick();
			}
		);

		$progress->finish();

		\WP_CLI::log(
			sprintf(
				'products %d | attachments created %d | already present %d | unreachable urls %d | probe failures %d | insert failures %d',
				$totals['products'],
				$totals['created'],
				$totals['existing'],
				$totals['unreachable'],
				$totals['probe_failed'],
				$totals['failed']
			)
		);

		// Reported, never silent. These products show WooCommerce's
		// placeholder and stay that way until the URLs are repaired upstream,
		// at which point a re-run picks them up.
		if ( array() !== $totals['stranded'] ) {
			\WP_CLI::warning( sprintf( '%d products have no reachable image:', count( $totals['stranded'] ) ) );
			\WP_CLI::log( implode( ',', $totals['stranded'] ) );
		}

		// Surfaced separately from unreachable URLs: a write failure is ours and
		// is retryable, a dead URL is the data's and is not.
		if ( 0 < $totals['failed'] ) {
			\WP_CLI::warning( sprintf( '%d attachments failed to insert. Re-running is safe and will retry them.', $totals['failed'] ) );
		}

		// A probe with no definite answer (timeout, 429, 5xx) is neither a
		// dead URL nor stranded: those products were left untouched and
		// unmarked, so a re-run or the next import retries them (issue #95).
		if ( 0 < $totals['probe_failed'] ) {
			\WP_CLI::warning( sprintf( '%d image probes failed without a definite answer. Re-running is safe and will retry them.', $totals['probe_failed'] ) );
		}

		if ( true === $dry_run ) {
			\WP_CLI::success( 'Dry run: nothing was written.' );
			return;
		}

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Products to process: an explicit `--product` list, else every product
	 * carrying `_fa_media` (see RemoteAttachmentRunner::products_with_media()
	 * for why presence of the cell, and never a uuid, is the selector).
	 *
	 * @param array $assoc_args Flags.
	 * @param int   $limit      Maximum products, 0 for all.
	 * @return array<int, int>
	 */
	private function target_products( array $assoc_args, $limit ) {
		if ( isset( $assoc_args['product'] ) ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['product'] ) ) ) );
		}

		return $this->runner->products_with_media( $limit );
	}

	/**
	 * Find an attachment already created for a (product, sha) pair.
	 *
	 * Kept on the command for callers that reach it here; the lookup itself
	 * lives on the runner.
	 *
	 * @param int    $product_id Product post id.
	 * @param string $sha        Image sha256.
	 * @return int Attachment id, or 0.
	 */
	public function find_existing( $product_id, $sha ) {
		return $this->runner->find_existing( $product_id, $sha );
	}
}
