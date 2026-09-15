<?php
/**
 * Reads for the brand-logo command, and the thumbnail cleanup on delete.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Brand terms, brand-logo attachments and term thumbnails.
 */
class BrandLogoStore {

	/**
	 * WooCommerce's brand taxonomy.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'product_brand';

	/**
	 * Term meta holding the Akeneo manufacturer code.
	 *
	 * Written by the term seed (featherarms-infrastructure
	 * infrastructure/akeneo/seed/scripts/woo_term_manifest.py:97, 245-248).
	 *
	 * @var string
	 */
	public const CODE_META = '_fa_akeneo_code';

	/**
	 * The product_brand term for each code.
	 *
	 * Matched on `_fa_akeneo_code` first. A code no term carries falls back to
	 * the seed's slug (`_` to `-`, woo_term_manifest.py:106-110) and says so,
	 * so the report can show it. A code shared by two terms keeps the lower id.
	 *
	 * @param array<int, string> $codes Brand codes.
	 * @return array<string, array{term_id:int,name:string,matched_by:string}>
	 */
	public function terms_for_codes( array $codes ) {
		if ( array() === $codes ) {
			return array();
		}

		$codes = array_values( array_map( 'strval', $codes ) );

		$by_code = $this->lowest_per_code(
			$this->terms(
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::CODE_META,
							'value'   => $codes,
							'compare' => 'IN',
						),
					),
				)
			),
			fn( $term ) => (string) get_term_meta( (int) $term->term_id, self::CODE_META, true ),
			$codes,
			'code'
		);

		$remaining = array_values( array_diff( $codes, array_keys( $by_code ) ) );

		if ( array() === $remaining ) {
			return $by_code;
		}

		$slugs   = array_combine( $remaining, array_map( fn( $code ) => str_replace( '_', '-', $code ), $remaining ) );
		$by_slug = array_flip( $slugs );
		$claimed = array_column( $by_code, 'term_id' );

		// A term a code already owns is never offered to a second code by slug.
		$slug_hit = $this->lowest_per_code(
			array_filter( $this->terms( array( 'slug' => array_values( $slugs ) ) ), fn( $term ) => ! in_array( (int) $term->term_id, $claimed, true ) ),
			fn( $term ) => (string) ( $by_slug[ (string) $term->slug ] ?? '' ),
			$remaining,
			'slug'
		);

		// `+`, not array_merge(): a purely numeric code ("1911") is an integer
		// key, and array_merge() would renumber it. The key sets are disjoint.
		return $by_code + $slug_hit;
	}

	/**
	 * Brand-logo attachments, keyed by s3_key. A duplicate key keeps the lowest id.
	 *
	 * @return array<string, array{id:int,url:string,width:int,height:int,alt:string}>
	 */
	public function logo_attachments() {
		global $wpdb;

		$sql = "SELECT a.ID, k.meta_value AS s3_key FROM {$wpdb->posts} a
			INNER JOIN {$wpdb->postmeta} k ON k.post_id = a.ID AND k.meta_key = '" . BrandLogoCreator::KEY_META . "'
			WHERE a.post_type = 'attachment' AND a.post_parent = 0
			ORDER BY a.ID ASC";

		$attachments = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input.
		foreach ( (array) $wpdb->get_results( $sql, 'ARRAY_A' ) as $row ) {
			$key = (string) $row['s3_key'];

			if ( true === isset( $attachments[ $key ] ) ) {
				continue;
			}

			$id = (int) $row['ID'];

			$attachments[ $key ] = array(
				'id'     => $id,
				'url'    => (string) get_post_meta( $id, '_fa_remote_url', true ),
				'width'  => (int) get_post_meta( $id, '_fa_remote_width', true ),
				'height' => (int) get_post_meta( $id, '_fa_remote_height', true ),
				'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}

		return $attachments;
	}

	/**
	 * Each term's current thumbnail and what it points at.
	 *
	 * @param array<int, int> $term_ids Term ids.
	 * @return array<int, array{id:int,state:string}> state: empty, missing, ours or manual.
	 */
	public function thumbnail_states( array $term_ids ) {
		$states = array();

		foreach ( $term_ids as $term_id ) {
			$id = (int) get_term_meta( (int) $term_id, 'thumbnail_id', true );

			$states[ (int) $term_id ] = array(
				'id'    => $id,
				'state' => $this->state( $id ),
			);
		}

		return $states;
	}

	/**
	 * Remove thumbnail_id from every brand term pointing at an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return int Terms cleared.
	 */
	public function clear_thumbnails_pointing_at( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		$term_ids = $this->terms(
			array(
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'thumbnail_id',
						'value' => $attachment_id,
					),
				),
			)
		);

		foreach ( $term_ids as $term_id ) {
			delete_term_meta( (int) $term_id, 'thumbnail_id', $attachment_id );
		}

		return count( $term_ids );
	}

	/**
	 * What a thumbnail id points at.
	 *
	 * @param int $id Attachment id.
	 * @return string
	 */
	private function state( $id ) {
		if ( $id < 1 ) {
			return 'empty';
		}

		$post = get_post( $id );

		if ( true !== is_object( $post ) || 'attachment' !== $post->post_type ) {
			return 'missing';
		}

		return '' !== (string) get_post_meta( $id, BrandLogoCreator::KEY_META, true ) ? 'ours' : 'manual';
	}

	/**
	 * Brand terms matching extra get_terms() arguments, or none on error.
	 *
	 * @param array $args Extra arguments.
	 * @return array
	 */
	private function terms( array $args ) {
		$terms = get_terms(
			array_merge(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
				),
				$args
			)
		);

		return true === is_wp_error( $terms ) || true !== is_array( $terms ) ? array() : $terms;
	}

	/**
	 * Index terms by the code a reader assigns them, lowest term id winning.
	 *
	 * @param array    $terms      Term objects.
	 * @param callable $code_of    fn( object $term ): string.
	 * @param array    $codes      Codes asked for.
	 * @param string   $matched_by How these terms were found.
	 * @return array<string, array{term_id:int,name:string,matched_by:string}>
	 */
	private function lowest_per_code( array $terms, callable $code_of, array $codes, $matched_by ) {
		$found = array();

		foreach ( $terms as $term ) {
			$code    = call_user_func( $code_of, $term );
			$term_id = (int) $term->term_id;

			if ( true !== in_array( $code, $codes, true ) || ( true === isset( $found[ $code ] ) && $found[ $code ]['term_id'] <= $term_id ) ) {
				continue;
			}

			$found[ $code ] = array(
				'term_id'    => $term_id,
				'name'       => (string) $term->name,
				'matched_by' => $matched_by,
			);
		}

		return $found;
	}
}
