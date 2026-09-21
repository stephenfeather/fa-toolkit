<?php
/**
 * WP-CLI command unpacking `_fa_attributes` into local product attributes.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\CLI\Attributes;

use FAToolkit\Attributes\AttributeUnpackRunner;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * `wp fa:attributes unpack`.
 *
 * The selection, the unpack and the writes live in AttributeUnpackRunner,
 * shared with the after-import listener; this class owns flag parsing and
 * WP-CLI output only (issue #24 ruling). It is the operator's recovery path
 * when a listener run stops on a budget, and the tool #117 measures with.
 */
class UnpackAttributesCommand {

	/**
	 * The runner's per-product outcomes, in the order the summary prints them.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'products'     => 'products',
		'written'      => 'written',
		'cleared'      => 'cleared',
		'unchanged'    => 'unchanged',
		'invalid'      => 'invalid',
		'skipped'      => 'skipped',
		'collisions'   => 'collisions',
		'unsupported'  => 'unsupported',
		'write_failed' => 'write failures',
	);

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

		\WP_CLI::add_command( 'fa:attributes unpack', array( $this, 'unpack' ) );
	}

	/**
	 * Unpack `_fa_attributes` into local product attributes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change, write nothing.
	 *
	 * [--product=<id>]
	 * : One product, with its attribute row printed before and after.
	 *
	 * [--force]
	 * : Ignore the markers and re-unpack every product carrying a cell.
	 *
	 * [--limit=<n>]
	 * : Cap the selection.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function unpack( $args, $assoc_args ) {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$product_id = isset( $assoc_args['product'] ) ? $this->checked_product_id( $assoc_args['product'] ) : 0;

		$product_ids = 0 < $product_id
			? array( $product_id )
			: $this->runner->products_to_unpack( (int) ( $assoc_args['limit'] ?? 0 ), array(), isset( $assoc_args['force'] ) );

		if ( array() === $product_ids ) {
			\WP_CLI::warning( 'Nothing to unpack: every product carrying _fa_attributes is up to date. Use --force to re-unpack.' );
			return;
		}

		if ( 0 < $product_id ) {
			\WP_CLI::log( 'Before: ' . wp_json_encode( $this->row( $product_id ) ) );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Unpacking attributes', count( $product_ids ) );

		$totals = $this->runner->run(
			$product_ids,
			$dry_run,
			function () use ( $progress ) {
				$progress->tick();
			}
		);

		$progress->finish();

		if ( 0 < $product_id ) {
			\WP_CLI::log( $this->after_line( $product_id, $dry_run, $totals ) );
		}

		\WP_CLI::log( $this->summary( $totals ) );

		// Both leave the product unmarked and selectable, so the operator should
		// know why a rerun keeps finding it.
		if ( 0 < $totals['invalid'] ) {
			\WP_CLI::warning( sprintf( '%d products have a cell that is not a JSON object. They keep their previous attributes and stay selectable.', $totals['invalid'] ) );
		}

		if ( 0 < $totals['write_failed'] ) {
			\WP_CLI::warning( sprintf( '%d products had a write fail. Re-running is safe and will retry them.', $totals['write_failed'] ) );
		}

		if ( true === $dry_run ) {
			\WP_CLI::success( 'Dry run: nothing was written.' );
			return;
		}

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * The `--product` value as a product id, or a WP-CLI error.
	 *
	 * The runner would only skip a non-product, which for an explicit id would
	 * read as a silent no-op; here it is an error the operator sees.
	 *
	 * @param mixed $value The flag value.
	 * @return int
	 */
	private function checked_product_id( $value ) {
		$product_id = (int) $value;

		if ( $product_id < 1 || trim( (string) $value ) !== (string) $product_id ) {
			\WP_CLI::error( '--product must be a positive integer.' );
		}

		$type = get_post_type( $product_id );

		if ( false === $type || null === $type ) {
			\WP_CLI::error( sprintf( 'Product %d not found.', $product_id ) );
		}

		if ( 'product' !== $type ) {
			\WP_CLI::error( sprintf( 'Post %d is a %s, not a product.', $product_id, $type ) );
		}

		return $product_id;
	}

	/**
	 * A product's `_product_attributes` row as stored, an empty array when absent.
	 *
	 * @param int $product_id Product id.
	 * @return array
	 */
	private function row( $product_id ) {
		$row = get_post_meta( $product_id, '_product_attributes', true );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * The "after" line for a single product.
	 *
	 * A dry run writes nothing, so there is no after row to read; the outcome
	 * the runner counted is reported instead.
	 *
	 * @param int   $product_id Product id.
	 * @param bool  $dry_run    Whether the run was dry.
	 * @param array $totals     Runner totals for the one product.
	 * @return string
	 */
	private function after_line( $product_id, $dry_run, array $totals ) {
		if ( true !== $dry_run ) {
			return 'After: ' . wp_json_encode( $this->row( $product_id ) );
		}

		foreach ( array( 'written', 'cleared', 'unchanged', 'invalid', 'skipped', 'write_failed' ) as $outcome ) {
			if ( 0 < ( $totals[ $outcome ] ?? 0 ) ) {
				return sprintf( 'After: not written (dry run); outcome %s.', $outcome );
			}
		}

		return 'After: not written (dry run).';
	}

	/**
	 * The one-line totals summary.
	 *
	 * @param array $totals Runner totals.
	 * @return string
	 */
	private function summary( array $totals ) {
		$parts = array();

		foreach ( self::LABELS as $key => $label ) {
			$parts[] = sprintf( '%s %d', $label, $totals[ $key ] ?? 0 );
		}

		return implode( ' | ', $parts );
	}
}
