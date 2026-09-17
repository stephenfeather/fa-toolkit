<?php
/**
 * Turns one `_fa_attributes` cell into WooCommerce local product attributes.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Attributes;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The unpack for one product. Pure: reads nothing, writes nothing, calls no
 * WordPress function. The runner (issue #114) does the reading and writing.
 *
 * Rules (issue #113, docs/design/attributes-unpack.md):
 *
 * - The cell is one JSON object, open-set vendor keys to scalar or list values.
 *   Anything else is invalid and changes nothing. A blank cell is what a blank
 *   CSV cell becomes, "the vendor sent nothing", and clears like `{}`.
 * - Each surviving key becomes one local (non-taxonomy) entry in the product's
 *   `_product_attributes` array.
 * - Ownership is by slug. The sidecar lists the slugs the last pass wrote; a
 *   pass removes exactly those, then writes the current set, and returns the
 *   slugs it wrote as the new sidecar. Every other entry is foreign and is
 *   returned byte for byte. A taxonomy entry is never removed, whatever a
 *   sidecar says.
 * - A key is not written when it is denylisted, when the product already
 *   carries its `pa_` twin (`bullet_type` yields to `pa_bullet-type`), when its
 *   value is empty or unsupported, or when its slug belongs to a foreign entry.
 * - Same inputs, same bytes: keys are taken in sorted order and positioned
 *   after the last foreign entry.
 */
final class AttributeUnpacker {

	/**
	 * Bumped when a change here alters output for unchanged inputs, so every
	 * product re-unpacks once. Part of the ruleset hash.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * WooCommerce's multi-value delimiter, which renders as a list.
	 *
	 * @var string
	 */
	private const DELIMITER = ' | ';

	/**
	 * Keys that are not customer-facing, plus `reticle_type`, which `pa_reticle`
	 * covers under a different name. Sorted.
	 *
	 * @var array<int, string>
	 */
	private const DENYLIST = array(
		'boxes_per_case',
		'cans_per_case',
		'packs_per_case',
		'reticle_type',
		'rsr_description',
		'rsr_subcategory',
		'units_per_case',
	);

	/**
	 * Strings the vendors use for flags, lower-cased.
	 *
	 * @var array<string, string>
	 */
	private const FLAGS = array(
		'y'     => 'Yes',
		'yes'   => 'Yes',
		'true'  => 'Yes',
		'n'     => 'No',
		'no'    => 'No',
		'false' => 'No',
	);

	/**
	 * The rules before any filter has touched them.
	 *
	 * @return array{denylist:array<int,string>,labels:array<string,string>}
	 */
	public static function default_rules() {
		return array(
			'denylist' => self::DENYLIST,
			'labels'   => array(),
		);
	}

	/**
	 * A stable hash of everything in the rules that changes output.
	 *
	 * Order inside the denylist and the label map is not a rule, so neither
	 * moves the hash.
	 *
	 * @param array $rules Rules, as from default_rules().
	 * @return string sha256.
	 */
	public static function ruleset_hash( array $rules ) {
		$denylist = array_values( array_unique( array_map( 'strval', $rules['denylist'] ?? array() ) ) );
		$labels   = array_map( 'strval', $rules['labels'] ?? array() );

		sort( $denylist, SORT_STRING );
		ksort( $labels, SORT_STRING );

		return hash(
			'sha256',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, no WordPress calls.
				array(
					'version'  => self::VERSION,
					'denylist' => $denylist,
					'labels'   => $labels,
				)
			)
		);
	}

	/**
	 * Unpack one cell onto one product's attributes.
	 *
	 * @param string             $cell     The raw `_fa_attributes` value.
	 * @param array              $rules    Rules, as from default_rules().
	 * @param array              $existing The product's `_product_attributes` array.
	 * @param array<int, string> $sidecar  Slugs the previous pass wrote.
	 * @return array{valid:bool,attributes:array,sidecar:array<int,string>,counts:array<string,int>}
	 */
	public static function unpack( $cell, array $rules, array $existing, array $sidecar ) {
		$pairs = self::pairs( (string) $cell );

		if ( null === $pairs ) {
			return array(
				'valid'      => false,
				'attributes' => $existing,
				'sidecar'    => array_values( $sidecar ),
				'counts'     => self::zero_counts(),
			);
		}

		$foreign  = self::foreign( $existing, $sidecar );
		$denylist = array_flip( array_map( 'strval', $rules['denylist'] ?? array() ) );
		$labels   = $rules['labels'] ?? array();
		$counts   = self::zero_counts();
		$position = self::next_position( $foreign );
		$written  = array();

		foreach ( $pairs as $key => $raw ) {
			$key = (string) $key;

			if ( isset( $denylist[ $key ] ) ) {
				++$counts['denied'];
				continue;
			}

			if ( isset( $existing[ 'pa_' . str_replace( '_', '-', $key ) ] ) ) {
				++$counts['shadowed'];
				continue;
			}

			$value = self::value( $raw );

			if ( null === $value || '' === $value ) {
				++$counts[ null === $value ? 'unsupported' : 'skipped_empty' ];
				continue;
			}

			$label = self::label( $key, $labels );
			$slug  = self::slug( $label );

			if ( '' === $slug ) {
				++$counts['unsupported'];
				continue;
			}

			// A slug already taken belongs to somebody else: a foreign entry, or
			// an earlier key whose label maps to the same slug.
			if ( isset( $foreign[ $slug ] ) || isset( $written[ $slug ] ) ) {
				++$counts['collisions'];
				continue;
			}

			$written[ $slug ] = array(
				'name'         => $label,
				'value'        => $value,
				'position'     => $position,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			);

			++$position;
			++$counts['written'];
		}

		return array(
			'valid'      => true,
			'attributes' => $foreign + $written,
			'sidecar'    => array_map( 'strval', array_keys( $written ) ),
			'counts'     => $counts,
		);
	}

	/**
	 * The cell's key/value pairs in key order, or null when it is not an object.
	 *
	 * Decoded as objects, not arrays, so `{}` and `[]` stay distinguishable at
	 * the top level and a nested object is recognisable as one.
	 *
	 * @param string $cell The raw cell.
	 * @return array<string, mixed>|null
	 */
	private static function pairs( $cell ) {
		if ( '' === trim( $cell ) ) {
			return array();
		}

		$decoded = json_decode( $cell );

		if ( true !== ( $decoded instanceof \stdClass ) ) {
			return null;
		}

		$pairs = get_object_vars( $decoded );

		ksort( $pairs, SORT_STRING );

		return $pairs;
	}

	/**
	 * Entries this class does not own, in their stored order.
	 *
	 * An entry is ours only when the sidecar names its slug AND it is a local
	 * entry. The second test costs nothing and means no sidecar, however it
	 * came to be wrong, can remove a taxonomy attribute.
	 *
	 * @param array              $existing The product's attributes.
	 * @param array<int, string> $sidecar  Slugs the previous pass wrote.
	 * @return array
	 */
	private static function foreign( array $existing, array $sidecar ) {
		$owned = array_flip( array_map( 'strval', $sidecar ) );

		return array_filter(
			$existing,
			static function ( $entry, $slug ) use ( $owned ) {
				return false === isset( $owned[ (string) $slug ] ) || self::is_taxonomy_entry( (string) $slug, $entry );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Whether an entry is a taxonomy attribute.
	 *
	 * @param string $slug  The entry's key.
	 * @param mixed  $entry The entry.
	 * @return bool
	 */
	private static function is_taxonomy_entry( $slug, $entry ) {
		return 0 === strpos( $slug, 'pa_' ) || ( is_array( $entry ) && false === empty( $entry['is_taxonomy'] ) );
	}

	/**
	 * The first position after every foreign entry's.
	 *
	 * @param array $foreign Foreign entries.
	 * @return int
	 */
	private static function next_position( array $foreign ) {
		$positions = array_map(
			static function ( $entry ) {
				return is_array( $entry ) ? (int) ( $entry['position'] ?? 0 ) : 0;
			},
			$foreign
		);

		return array() === $positions ? 0 : max( $positions ) + 1;
	}

	/**
	 * One JSON value as an attribute value.
	 *
	 * @param mixed $raw The decoded value.
	 * @return string|null '' when there is nothing to show, null when the shape is unsupported.
	 */
	private static function value( $raw ) {
		if ( true !== is_array( $raw ) ) {
			return self::scalar( $raw );
		}

		$parts = array_map( array( self::class, 'scalar' ), $raw );

		if ( in_array( null, $parts, true ) ) {
			return null;
		}

		return implode( self::DELIMITER, array_values( array_diff( $parts, array( '' ) ) ) );
	}

	/**
	 * One scalar as text.
	 *
	 * A number keeps the text JSON gives it back, not the text it arrived in:
	 * `json_decode()` has already made `1.50` a float. Vendors send numbers as
	 * strings today, and a string is never reformatted.
	 *
	 * @param mixed $raw The decoded scalar.
	 * @return string|null '' for null and blank, null for anything not a scalar.
	 */
	private static function scalar( $raw ) {
		if ( null === $raw ) {
			return '';
		}

		if ( is_bool( $raw ) ) {
			return $raw ? 'Yes' : 'No';
		}

		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (string) json_encode( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, no WordPress calls.
		}

		if ( true !== is_string( $raw ) ) {
			return null;
		}

		$text = trim( $raw );

		// WooCommerce reads "|" as the separator between values.
		return self::FLAGS[ strtolower( $text ) ] ?? str_replace( '|', '/', $text );
	}

	/**
	 * The label shown for a key.
	 *
	 * @param string                $key    Normalized vendor key.
	 * @param array<string, string> $labels Overrides, key => label.
	 * @return string
	 */
	private static function label( $key, array $labels ) {
		$override = trim( (string) ( $labels[ $key ] ?? '' ) );

		return '' !== $override ? $override : ucwords( str_replace( '_', ' ', $key ) );
	}

	/**
	 * The `_product_attributes` key for a label.
	 *
	 * WooCommerce keys a local attribute by `sanitize_title( name )` and re-keys
	 * the row that way on a wp-admin product save. This is the same result for
	 * labels made of ASCII letters, digits, spaces, hyphens and underscores,
	 * which is every label derived from a normalized key. An override outside
	 * that set may be re-keyed by an admin save and orphaned from the sidecar.
	 *
	 * @param string $label The label.
	 * @return string
	 */
	private static function slug( $label ) {
		$slug = preg_replace( '/[^a-z0-9 _-]/', '', strtolower( $label ) );
		$slug = preg_replace( '/[\s-]+/', '-', (string) $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * Every count, at zero.
	 *
	 * @return array<string, int>
	 */
	private static function zero_counts() {
		return array(
			'written'       => 0,
			'shadowed'      => 0,
			'denied'        => 0,
			'skipped_empty' => 0,
			'unsupported'   => 0,
			'collisions'    => 0,
		);
	}
}
