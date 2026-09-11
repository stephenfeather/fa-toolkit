<?php
/**
 * Decides which pointer attachments a product no longer needs.
 *
 * @package    fa-toolkit
 * @since 1.2.3
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The orphan decision for one product. Pure: reads nothing, deletes nothing.
 *
 * A pointer attachment is keyed on (product, sha256). When a product's
 * `_fa_media` cell stops carrying an entry with that sha256 — a derivative
 * URL replaced by its original, a gallery entry removed upstream — the
 * attachment is an orphan: nothing will ever select or refresh it again.
 *
 * Every rule here errs towards keeping. A kept orphan is recoverable by a
 * later run; a deleted current image is not.
 *
 * - A cell that is missing or empty says nothing about the product's images,
 *   so the product is skipped rather than having every attachment orphaned.
 *   So does a cell that parses but names no sha256 at all (`[]`, `{}`, a list
 *   of entries without one).
 * - A cell that is not valid JSON, or decodes to anything but a list, is
 *   skipped as invalid for the same reason.
 * - Any entry carrying a sha256 keeps a matching attachment, whatever its
 *   kind or completeness.
 * - An orphan still wired as the product's thumbnail or in its gallery is an
 *   anomaly: reported, never offered for deletion.
 */
final class OrphanAttachmentPlan {

	/**
	 * The product's cell was read and judged.
	 *
	 * @var string
	 */
	public const STATUS_OK = 'ok';

	/**
	 * The product's cell is not valid JSON; the product is skipped.
	 *
	 * @var string
	 */
	public const STATUS_INVALID_CELL = 'invalid_cell';

	/**
	 * The product has no non-empty cell, or its cells name no sha256; the
	 * product is skipped.
	 *
	 * @var string
	 */
	public const STATUS_NO_CELL = 'no_cell';

	/**
	 * Plan one product.
	 *
	 * @param array<int, string> $attachments Pointer attachments: attachment id => sha256.
	 * @param array<int, string> $cells       Raw `_fa_media` values for the product.
	 * @param array<int, int>    $wired_ids   Thumbnail and gallery attachment ids.
	 * @return array{status:string,pointer:int,current:int,orphans:array<int,int>,wired_orphans:array<int,int>}
	 */
	public static function for_product( array $attachments, array $cells, array $wired_ids ) {
		list( $status, $shas ) = self::cell_shas( $cells );

		$plan = array(
			'status'        => $status,
			'pointer'       => count( $attachments ),
			'current'       => 0,
			'orphans'       => array(),
			'wired_orphans' => array(),
		);

		if ( self::STATUS_OK !== $status ) {
			return $plan;
		}

		$orphan_ids = array_keys( array_filter( $attachments, fn( $sha ) => ! isset( $shas[ (string) $sha ] ) ) );
		$is_wired   = fn( $id ) => in_array( (int) $id, array_map( 'intval', $wired_ids ), true );

		$plan['current']       = count( $attachments ) - count( $orphan_ids );
		$plan['wired_orphans'] = array_values( array_map( 'intval', array_filter( $orphan_ids, $is_wired ) ) );
		$plan['orphans']       = array_values( array_map( 'intval', array_filter( $orphan_ids, fn( $id ) => ! $is_wired( $id ) ) ) );

		return $plan;
	}

	/**
	 * The sha256 values a product's cells carry, and whether they could be read.
	 *
	 * @param array<int, string> $cells Raw `_fa_media` values.
	 * @return array{0:string,1:array<string,bool>} Status and a sha256 set.
	 */
	private static function cell_shas( array $cells ) {
		$non_empty = array_filter( $cells, fn( $cell ) => '' !== trim( (string) $cell ) );

		if ( array() === $non_empty ) {
			return array( self::STATUS_NO_CELL, array() );
		}

		$shas = array();

		foreach ( $non_empty as $cell ) {
			$decoded = json_decode( (string) $cell, true );

			// A media cell is a list of entries. A JSON object decodes to an
			// associative array whose values are not entries.
			if ( true !== is_array( $decoded ) || ( array() !== $decoded && true !== array_is_list( $decoded ) ) ) {
				return array( self::STATUS_INVALID_CELL, array() );
			}

			foreach ( $decoded as $entry ) {
				if ( true === is_array( $entry ) && '' !== (string) ( $entry['sha256'] ?? '' ) ) {
					$shas[ (string) $entry['sha256'] ] = true;
				}
			}
		}

		// Parsed, but naming no sha256: nothing to judge attachments against.
		if ( array() === $shas ) {
			return array( self::STATUS_NO_CELL, array() );
		}

		return array( self::STATUS_OK, $shas );
	}
}
