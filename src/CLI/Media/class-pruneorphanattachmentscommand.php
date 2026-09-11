<?php
/**
 * WP-CLI command deleting pointer attachments that left their product's `_fa_media`.
 *
 * @package    fa-toolkit
 * @since 1.2.3
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Media\OrphanAttachmentPlan;
use FAToolkit\Media\OrphanAttachmentStore;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Prunes orphan pointer attachments.
 *
 * The decision is OrphanAttachmentPlan's; the reads are OrphanAttachmentStore's.
 * This class owns the flags, the deletion, and the report.
 */
class PruneOrphanAttachmentsCommand {

	/**
	 * The bulk reads.
	 *
	 * @var OrphanAttachmentStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param OrphanAttachmentStore|null $store Store; built when omitted.
	 */
	public function __construct( ?OrphanAttachmentStore $store = null ) {
		$this->store = $store ?? new OrphanAttachmentStore();

		\WP_CLI::add_command( 'fa:media prune-orphan-attachments', array( $this, 'prune' ) );
	}

	/**
	 * Delete pointer attachments whose sha256 is no longer in the parent product's `_fa_media`.
	 *
	 * Dry run unless --execute is given. An orphan still wired as the
	 * product's thumbnail or in its gallery is reported and never deleted.
	 * Products whose `_fa_media` is missing, empty or not valid JSON are
	 * skipped. Attachments without `_fa_media_sha256` are never touched.
	 *
	 * ## OPTIONS
	 *
	 * [--product=<id>]
	 * : Only this product id. Repeatable as a comma-separated list.
	 *
	 * [--execute]
	 * : Delete the orphans. Without it nothing is written.
	 *
	 * [--dry-run]
	 * : Report without deleting (the default; wins over --execute).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function prune( $args, $assoc_args ) {
		unset( $args );

		$execute = isset( $assoc_args['execute'] ) && ! isset( $assoc_args['dry-run'] );
		$scope   = $this->scope( $assoc_args );

		// An empty scope is the store's "every parent", so a --product that
		// names no valid id must stop here rather than widen to the catalogue.
		if ( array() === $scope ) {
			\WP_CLI::warning( 'No valid product id in --product; nothing was read or deleted.' );
			return;
		}

		$attachments = $this->store->pointer_attachments( $scope ?? array() );

		if ( array() === $attachments ) {
			\WP_CLI::warning( 'No pointer attachments in scope.' );
			return;
		}

		$product_ids = array_keys( $attachments );
		$cells       = $this->store->media_cells( $product_ids );
		$wired       = $this->store->wired_ids( $product_ids );

		$plans = array();
		foreach ( $attachments as $product_id => $product_attachments ) {
			$plans[ $product_id ] = OrphanAttachmentPlan::for_product( $product_attachments, $cells[ $product_id ] ?? array(), $wired[ $product_id ] ?? array() );
		}

		$orphans = array_merge( array(), ...array_values( array_column( $plans, 'orphans' ) ) );
		$failed  = true === $execute ? $this->delete( $orphans ) : array();
		$deleted = true === $execute ? count( $orphans ) - count( $failed ) : 0;

		$this->report( $plans, $deleted, $failed );

		\WP_CLI::success(
			true === $execute
				? sprintf( 'Deleted %d orphan attachments.', $deleted )
				: sprintf( 'Dry run: nothing was deleted. Re-run with --execute to delete %d orphan attachments.', count( $orphans ) )
		);
	}

	/**
	 * Products to read.
	 *
	 * Null when `--product` is absent (every parent). Otherwise the valid ids
	 * it names, which may be empty: the caller must treat that as "nothing",
	 * never as "everything". A bare `--product` (boolean true from WP-CLI)
	 * names no id.
	 *
	 * @param array $assoc_args Flags.
	 * @return array<int, int>|null
	 */
	private function scope( array $assoc_args ) {
		if ( ! array_key_exists( 'product', $assoc_args ) ) {
			return null;
		}

		if ( true !== is_string( $assoc_args['product'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', explode( ',', $assoc_args['product'] ) ), fn( $id ) => $id > 0 ) );
	}

	/**
	 * Force-delete attachments with file deletion suppressed.
	 *
	 * A pointer attachment has no local file, but core still derives an
	 * uploads path from `_wp_attached_file` (a bare basename here) and its
	 * nominal sizes, and would unlink a real file in the uploads root that
	 * shares the name. Suppressing `wp_delete_file` for the prune removes that
	 * risk; nothing this command deletes owns a file.
	 *
	 * @param array<int, int> $attachment_ids Attachments to delete.
	 * @return array<int, int> Ids that could not be deleted.
	 */
	private function delete( array $attachment_ids ) {
		if ( array() === $attachment_ids ) {
			return array();
		}

		add_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX );

		$failed = array_values( array_filter( $attachment_ids, fn( $id ) => true !== is_object( wp_delete_attachment( $id, true ) ) ) );

		remove_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX );

		return $failed;
	}

	/**
	 * Per-product lines, the TOTAL line, and anomaly warnings.
	 *
	 * Only products with something to say get a line: an orphan, a wired
	 * orphan, or a skip. A catalogue has tens of thousands of clean products.
	 *
	 * @param array<int, array> $plans   Plans by product id.
	 * @param int               $deleted Attachments deleted.
	 * @param array<int, int>   $failed  Attachments that could not be deleted.
	 * @return void
	 */
	private function report( array $plans, $deleted, array $failed ) {
		foreach ( $plans as $product_id => $plan ) {
			$line = $this->product_line( $product_id, $plan );
			if ( '' !== $line ) {
				\WP_CLI::log( $line );
			}
		}

		$wired = array_merge( array(), ...array_values( array_column( $plans, 'wired_orphans' ) ) );

		\WP_CLI::log(
			sprintf(
				'TOTAL products %d | pointer attachments %d | current %d | orphans %d | wired orphans %d | skipped invalid cell %d | skipped no cell %d | deleted %d | delete failures %d',
				count( $plans ),
				array_sum( array_column( $plans, 'pointer' ) ),
				array_sum( array_column( $plans, 'current' ) ),
				array_sum( array_map( 'count', array_column( $plans, 'orphans' ) ) ),
				count( $wired ),
				count( array_filter( $plans, fn( $plan ) => OrphanAttachmentPlan::STATUS_INVALID_CELL === $plan['status'] ) ),
				count( array_filter( $plans, fn( $plan ) => OrphanAttachmentPlan::STATUS_NO_CELL === $plan['status'] ) ),
				$deleted,
				count( $failed )
			)
		);

		if ( array() !== $wired ) {
			\WP_CLI::warning( sprintf( '%d orphan attachments are still wired to their product and were left in place: %s', count( $wired ), implode( ',', $wired ) ) );
		}

		if ( array() !== $failed ) {
			\WP_CLI::warning( sprintf( '%d orphan attachments could not be deleted: %s', count( $failed ), implode( ',', $failed ) ) );
		}
	}

	/**
	 * One product's report line, or '' when there is nothing to say.
	 *
	 * @param int   $product_id Product id.
	 * @param array $plan       The product's plan.
	 * @return string
	 */
	private function product_line( $product_id, array $plan ) {
		if ( OrphanAttachmentPlan::STATUS_INVALID_CELL === $plan['status'] ) {
			return sprintf( 'product %d | pointer attachments %d | skipped: invalid _fa_media', $product_id, $plan['pointer'] );
		}

		if ( OrphanAttachmentPlan::STATUS_NO_CELL === $plan['status'] ) {
			return sprintf( 'product %d | pointer attachments %d | skipped: no _fa_media', $product_id, $plan['pointer'] );
		}

		if ( array() === $plan['orphans'] && array() === $plan['wired_orphans'] ) {
			return '';
		}

		return sprintf(
			'product %d | pointer attachments %d | current %d | orphans %d | wired orphans %d',
			$product_id,
			$plan['pointer'],
			$plan['current'],
			count( $plan['orphans'] ),
			count( $plan['wired_orphans'] )
		);
	}
}
