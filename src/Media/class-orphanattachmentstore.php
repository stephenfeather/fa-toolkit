<?php
/**
 * Bulk reads for the orphan pointer attachment prune.
 *
 * @package    fa-toolkit
 * @since 1.2.3
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The three reads the prune needs, each a handful of queries rather than one
 * per attachment: staging runs SAVEQUERIES, and a catalogue holds tens of
 * thousands of pointer attachments.
 */
class OrphanAttachmentStore {

	/**
	 * Product ids per IN list.
	 */
	private const CHUNK = 500;

	/**
	 * Pointer attachments grouped by parent product.
	 *
	 * Only attachments carrying `_fa_media_sha256` with a parent are selected,
	 * so an ordinary uploaded attachment can never reach the prune.
	 *
	 * @param array<int, int> $product_ids Parent products to read, or empty for all.
	 * @return array<int, array<int, string>> product id => ( attachment id => sha256 )
	 */
	public function pointer_attachments( array $product_ids ) {
		global $wpdb;

		$sql   = "SELECT a.ID, a.post_parent, s.meta_value AS sha256 FROM {$wpdb->posts} a
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = a.ID AND s.meta_key = '_fa_media_sha256'
			WHERE a.post_type = 'attachment' AND a.post_parent > 0";
		$order = ' ORDER BY a.post_parent ASC, a.ID ASC';

		$rows = array() === $product_ids
			? (array) $wpdb->get_results( $sql . $order, 'ARRAY_A' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $this->chunked( $sql . ' AND a.post_parent IN (%s)' . $order, $product_ids );

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row['post_parent'] ][ (int) $row['ID'] ] = (string) $row['sha256'];
		}

		return $grouped;
	}

	/**
	 * Raw `_fa_media` values grouped by product, every row kept.
	 *
	 * @param array<int, int> $product_ids Products to read.
	 * @return array<int, array<int, string>> product id => raw cells
	 */
	public function media_cells( array $product_ids ) {
		global $wpdb;

		$rows = $this->chunked(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fa_media' AND post_id IN (%s)",
			$product_ids
		);

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row['post_id'] ][] = (string) $row['meta_value'];
		}

		return $grouped;
	}

	/**
	 * Attachment ids each product is wired to: thumbnail, then gallery.
	 *
	 * @param array<int, int> $product_ids Products to read.
	 * @return array<int, array<int, int>> product id => attachment ids
	 */
	public function wired_ids( array $product_ids ) {
		global $wpdb;

		$rows = $this->chunked(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery') AND post_id IN (%s)",
			$product_ids
		);

		usort( $rows, fn( $a, $b ) => ( '_thumbnail_id' === $b['meta_key'] ) <=> ( '_thumbnail_id' === $a['meta_key'] ) );

		$grouped = array();

		foreach ( $rows as $row ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $row['meta_value'] ) ) );

			$grouped[ (int) $row['post_id'] ] = array_merge( $grouped[ (int) $row['post_id'] ] ?? array(), array_values( $ids ) );
		}

		return $grouped;
	}

	/**
	 * Run a query once per chunk of product ids and merge the rows.
	 *
	 * @param string          $sql         Query with one `%s` where the id placeholders go.
	 * @param array<int, int> $product_ids Product ids.
	 * @return array<int, array<string, string>>
	 */
	private function chunked( $sql, array $product_ids ) {
		global $wpdb;

		$rows = array();

		foreach ( array_chunk( array_map( 'intval', $product_ids ), self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built from the chunk size.
			$prepared = $wpdb->prepare( sprintf( $sql, $placeholders ), ...$chunk );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$rows = array_merge( $rows, (array) $wpdb->get_results( $prepared, 'ARRAY_A' ) );
		}

		return $rows;
	}
}
