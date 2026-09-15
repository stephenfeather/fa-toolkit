<?php
/**
 * Decides what the brand-logo command does for each brand.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The plan for one run. Pure: reads nothing, writes nothing.
 *
 * Rules (issue #105):
 *
 * - Only `logo` entries are planned. MISSING, conflict and near_identical are
 *   listed; resolving them is an operator ruling, not this command's.
 * - A code with no product_brand term is listed and creates nothing.
 * - An attachment is identified by its s3_key. Codes sharing a key share one
 *   attachment, whose alt is the name of the lowest term_id (operator, Q2).
 * - A term's thumbnail_id is set when empty, when it points at a deleted
 *   attachment, or when it points at another brand logo. One uploaded by hand
 *   is kept unless replace is asked for.
 * - An existing attachment is refreshed field by field, only where it differs,
 *   so a rerun over an unchanged map writes nothing.
 */
final class BrandLogoPlan {

	/**
	 * Build the plan.
	 *
	 * @param array<string, array> $entries     Map entries, from BrandLogoMap.
	 * @param array<string, array> $terms       Code => {term_id, name, matched_by}.
	 * @param array<string, array> $attachments s3_key => {id, url, width, height, alt}.
	 * @param array<int, array>    $thumbnails  Term id => {id, state}.
	 * @param bool                 $replace     Overwrite thumbnails uploaded by hand.
	 * @return array{attachments:array<string,array>,rows:array<int,array>,no_term:array<int,string>,skipped:array<string,array<int,string>>}
	 */
	public static function build( array $entries, array $terms, array $attachments, array $thumbnails, $replace ) {
		$plan = array(
			'attachments' => array(),
			'rows'        => array(),
			'no_term'     => array(),
			'skipped'     => array(
				'MISSING'        => array(),
				'conflict'       => array(),
				'near_identical' => array(),
			),
		);

		foreach ( $entries as $code => $entry ) {
			$code = (string) $code;

			if ( 'logo' !== $entry['status'] ) {
				$plan['skipped'][ $entry['status'] ][] = $code;
				continue;
			}

			if ( false === isset( $terms[ $code ] ) ) {
				$plan['no_term'][] = $code;
				continue;
			}

			$plan['rows'][] = self::row( $code, $entry, $terms[ $code ], $attachments, $thumbnails, (bool) $replace );
		}

		$plan['attachments'] = self::attachments( $entries, $terms, $attachments, $plan['rows'] );

		return $plan;
	}

	/**
	 * One brand's row.
	 *
	 * @param string $code        Brand code.
	 * @param array  $entry       Logo entry.
	 * @param array  $term        Matched term.
	 * @param array  $attachments Existing brand-logo attachments.
	 * @param array  $thumbnails  Thumbnail states.
	 * @param bool   $replace     Overwrite thumbnails uploaded by hand.
	 * @return array{code:string,term_id:int,s3_key:string,matched_by:string,action:string,current_id:int}
	 */
	private static function row( $code, array $entry, array $term, array $attachments, array $thumbnails, $replace ) {
		$term_id   = (int) $term['term_id'];
		$thumbnail = $thumbnails[ $term_id ] ?? array(
			'id'    => 0,
			'state' => 'empty',
		);

		return array(
			'code'       => $code,
			'term_id'    => $term_id,
			's3_key'     => $entry['s3_key'],
			'matched_by' => (string) $term['matched_by'],
			'action'     => self::action( $thumbnail, (int) ( $attachments[ $entry['s3_key'] ]['id'] ?? 0 ), $replace ),
			'current_id' => (int) $thumbnail['id'],
		);
	}

	/**
	 * What to do with a term's thumbnail.
	 *
	 * An unknown state is kept: every rule errs towards not overwriting.
	 *
	 * @param array $thumbnail   {id, state}.
	 * @param int   $existing_id Attachment already serving the key, or 0.
	 * @param bool  $replace     Overwrite thumbnails uploaded by hand.
	 * @return string set, already_set or kept_manual.
	 */
	private static function action( array $thumbnail, $existing_id, $replace ) {
		switch ( $thumbnail['state'] ) {
			case 'empty':
			case 'missing':
				return 'set';
			case 'ours':
				return 0 < $existing_id && (int) $thumbnail['id'] === $existing_id ? 'already_set' : 'set';
			case 'manual':
				return true === $replace ? 'set' : 'kept_manual';
			default:
				return 'kept_manual';
		}
	}

	/**
	 * The attachments the kept-out-of rows need, keyed by s3_key, first-seen order.
	 *
	 * @param array $entries     Map entries.
	 * @param array $terms       Matched terms.
	 * @param array $attachments Existing brand-logo attachments.
	 * @param array $rows        Planned rows.
	 * @return array<string, array>
	 */
	private static function attachments( array $entries, array $terms, array $attachments, array $rows ) {
		$by_key = array();

		foreach ( $rows as $row ) {
			if ( 'kept_manual' !== $row['action'] ) {
				$by_key[ $row['s3_key'] ][] = $row;
			}
		}

		$planned = array();

		foreach ( $by_key as $key => $key_rows ) {
			$planned[ $key ] = self::attachment( (string) $key, $entries[ $key_rows[0]['code'] ], $key_rows, $terms, $attachments[ $key ] ?? null );
		}

		return $planned;
	}

	/**
	 * One planned attachment.
	 *
	 * Width, height and sha256 come from the first entry using the key, and
	 * stay null when the map does not carry them: they are never learned by
	 * downloading the image.
	 *
	 * @param string     $key      s3_key.
	 * @param array      $entry    First logo entry using the key.
	 * @param array      $key_rows Rows using this key.
	 * @param array      $terms    Matched terms.
	 * @param array|null $existing Existing attachment, or null.
	 * @return array
	 */
	private static function attachment( $key, array $entry, array $key_rows, array $terms, $existing ) {
		$url   = $entry['url'];
		$sized = isset( $entry['width'], $entry['height'] );
		$names = array();

		foreach ( $key_rows as $row ) {
			$names[ $row['term_id'] ] = (string) $terms[ $row['code'] ]['name'];
		}

		ksort( $names );

		$alt = reset( $names );

		return array(
			's3_key'        => $key,
			'url'           => $url,
			'attachment_id' => null === $existing ? 0 : (int) $existing['id'],
			'alt'           => $alt,
			'term_ids'      => array_keys( $names ),
			'unused_names'  => array_values( array_unique( array_diff( array_slice( $names, 1, null, true ), array( $alt ) ) ) ),
			'width'         => $sized ? (int) $entry['width'] : null,
			'height'        => $sized ? (int) $entry['height'] : null,
			'sha256'        => isset( $entry['sha256'] ) ? (string) $entry['sha256'] : null,
			'refresh'       => array(
				'url'        => null !== $existing && (string) $existing['url'] !== $url,
				'dimensions' => null !== $existing && $sized && ( (int) $existing['width'] !== (int) $entry['width'] || (int) $existing['height'] !== (int) $entry['height'] ),
				'alt'        => null !== $existing && (string) $existing['alt'] !== $alt,
			),
		);
	}
}
