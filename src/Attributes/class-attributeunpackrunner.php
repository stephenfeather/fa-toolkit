<?php
/**
 * Runs AttributeUnpacker over a set of products.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Attributes;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The product loop shared by the after-import listener and `wp fa:attributes
 * unpack`, as RemoteAttachmentRunner is for media (issue #24 ruling: neither
 * caller carries logic the other lacks).
 *
 * Owns product selection against the two markers, the per-product read, write
 * and cache invalidation, and the run totals. The unpack itself is
 * AttributeUnpacker, which reads and writes nothing.
 *
 * Rules (issue #114, docs/design/attributes-unpack.md, "Idempotency and reruns"):
 *
 * - The pass is a function of the cell, the rules and the product's existing
 *   `_product_attributes` row, so there is one marker per mutable input, both
 *   compared in MySQL. A product is selected when either fails to match.
 * - Every pass writes all four values: row, sidecar, both markers. There is no
 *   "markers only" shortcut, so the sidecar can never lag the row.
 *   `update_post_meta()` skips a value that has not changed.
 * - An invalid cell keeps the previous attributes and gets no marker. A failed
 *   write gets no marker. Both stay selectable.
 */
class AttributeUnpackRunner {

	/**
	 * Product postmeta holding `sha256( cell . '|' . ruleset_hash )` for the
	 * last cell applied with no write failures.
	 *
	 * @var string
	 */
	public const APPLIED_MARKER = '_fa_attributes_applied_sha256';

	/**
	 * Product postmeta holding the sha256 of the `_product_attributes` row
	 * exactly as the last pass left it. SSI and admin edits change that row
	 * without moving the cell, and the `pa_*` shadow and the collision rule
	 * both read it (PR #111 review).
	 *
	 * @var string
	 */
	public const ROW_MARKER = '_fa_attributes_row_sha256';

	/**
	 * Product postmeta listing the slugs the last pass wrote.
	 *
	 * @var string
	 */
	public const SIDECAR = '_fa_attributes_unpacked';

	/**
	 * The unpack rules for this run.
	 *
	 * @var array
	 */
	private $rules;

	/**
	 * Write failures for the product being processed.
	 *
	 * @var int
	 */
	private $write_failures = 0;

	/**
	 * Fix the rules for the run, so selection and markers hash the same set.
	 *
	 * @param array|null $rules Rules, as from AttributeUnpacker::default_rules(). Null for the defaults.
	 */
	public function __construct( $rules = null ) {
		$this->rules = is_array( $rules ) ? $rules : AttributeUnpacker::default_rules();
	}

	/**
	 * Products whose `_fa_attributes` needs a pass, ascending by id.
	 *
	 * Two arms, one query. Arm 1, stale inputs: a non-empty cell where the
	 * applied marker does not equal `SHA2( cell | ruleset_hash )` or the row
	 * marker does not equal `SHA2( _product_attributes row, or '' )`. No marker
	 * row and a different value both select. Arm 2, cell gone: a non-empty
	 * sidecar beside an absent or empty cell. A blank CSV cell writes `''` and
	 * only an absent column leaves old meta (infra-dev I-01), so `''` is the
	 * "vendor sent nothing" signal this arm clears on.
	 *
	 * Forced, both markers are ignored: every product with a non-empty cell or
	 * a non-empty sidecar is selected.
	 *
	 * Restricted to `product` posts, so a variation never takes a slot in a
	 * capped run. The comparisons and the cap run in MySQL, so a capped run
	 * never materialises the catalogue. Products named in `$exclude` are cut in
	 * SQL, for a caller draining in batches past a product that failed.
	 *
	 * @param int             $limit   Maximum products, 0 for all.
	 * @param array<int, int> $exclude Product ids to leave out.
	 * @param bool            $force   Ignore the markers.
	 * @return array<int, int>
	 */
	public function products_to_unpack( $limit = 0, array $exclude = array(), $force = false ) {
		global $wpdb;

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fa_attributes'
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '" . self::SIDECAR . "'
			LEFT JOIN {$wpdb->postmeta} pa ON pa.post_id = p.ID AND pa.meta_key = '_product_attributes'
			WHERE p.post_type = 'product'
			AND (
				(COALESCE(m.meta_value, '') <> ''" . ( true === $force ? '' : $this->stale_clause() ) . ")
				OR (COALESCE(m.meta_value, '') = '' AND COALESCE(s.meta_value, '') NOT IN ('', 'a:0:{}'))
			)" . $this->exclude_clause( $exclude ) . '
			ORDER BY p.ID ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql . $this->limit_clause( $limit ) ) );
	}

	/**
	 * Run the unpacker over the given products.
	 *
	 * Per product: read the cell, the row and the sidecar; unpack; write the
	 * row, the sidecar and both markers with `update_post_meta()`, not
	 * `WC_Product::save()`; clean the product's cache when the row changed.
	 *
	 * Write order is row, sidecar, applied marker, row marker, and the first
	 * failure stops the sequence, so a marker is never written over a row that
	 * did not store.
	 *
	 * @param array<int, int> $product_ids Products to process.
	 * @param bool            $dry_run     Report without writing.
	 * @param callable|null   $tick        Called once after each product with (id, outcome).
	 * @return array{products:int,written:int,cleared:int,unchanged:int,invalid:int,skipped:int,collisions:int,unsupported:int,write_failed:int}
	 */
	public function run( array $product_ids, $dry_run = false, $tick = null ) {
		$totals = array(
			'products'     => count( $product_ids ),
			'written'      => 0,
			'cleared'      => 0,
			'unchanged'    => 0,
			'invalid'      => 0,
			'skipped'      => 0,
			'collisions'   => 0,
			'unsupported'  => 0,
			'write_failed' => 0,
		);

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$outcome    = $this->process( $product_id, (bool) $dry_run, $totals );

			++$totals[ $outcome ];

			if ( null !== $tick ) {
				call_user_func( $tick, $product_id, $outcome );
			}
		}

		return $totals;
	}

	/**
	 * One product: its outcome, with collisions and unsupported added to totals.
	 *
	 * @param int   $product_id Product id.
	 * @param bool  $dry_run    Report without writing.
	 * @param array $totals     Run totals, by reference.
	 * @return string One of written, cleared, unchanged, invalid, skipped, write_failed.
	 */
	private function process( $product_id, $dry_run, array &$totals ) {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return 'skipped';
		}

		$cell     = (string) get_post_meta( $product_id, '_fa_attributes', true );
		$existing = $this->array_meta( $product_id, '_product_attributes' );
		$result   = AttributeUnpacker::unpack( $cell, $this->rules, $existing, $this->array_meta( $product_id, self::SIDECAR ) );

		$totals['collisions']  += $result['counts']['collisions'];
		$totals['unsupported'] += $result['counts']['unsupported'];

		if ( true !== $result['valid'] ) {
			return 'invalid';
		}

		$outcome = self::outcome( $existing, $result );

		if ( true === $dry_run ) {
			return $outcome;
		}

		if ( true !== $this->write( $product_id, $cell, $result['attributes'], $result['sidecar'] ) ) {
			return 'write_failed';
		}

		if ( 'unchanged' !== $outcome ) {
			clean_post_cache( $product_id );
		}

		return $outcome;
	}

	/**
	 * What a valid pass did to the row.
	 *
	 * @param array $existing The row before.
	 * @param array $result   The unpacker's result.
	 * @return string written, cleared or unchanged.
	 */
	private static function outcome( array $existing, array $result ) {
		if ( $result['attributes'] === $existing ) {
			return 'unchanged';
		}

		return 0 < $result['counts']['written'] ? 'written' : 'cleared';
	}

	/**
	 * Write all four values, stopping at the first that does not store.
	 *
	 * The row marker hashes the row as `update_post_meta()` stores it, which
	 * is what the selection query hashes in MySQL. Row and sidecar are slashed
	 * on the way in, as `update_post_meta()` expects, so a backslash in a
	 * vendor value survives and the two hashes agree.
	 *
	 * @param int    $product_id Product id.
	 * @param string $cell       The cell as read.
	 * @param array  $attributes The row to store.
	 * @param array  $sidecar    The slugs this pass wrote.
	 * @return bool Whether every value stored.
	 */
	private function write( $product_id, $cell, array $attributes, array $sidecar ) {
		$this->write_failures = 0;

		$writes = array(
			array( '_product_attributes', wp_slash( $attributes ) ),
			array( self::SIDECAR, wp_slash( $sidecar ) ),
			array( self::APPLIED_MARKER, hash( 'sha256', $cell . '|' . AttributeUnpacker::ruleset_hash( $this->rules ) ) ),
			array( self::ROW_MARKER, hash( 'sha256', (string) maybe_serialize( $attributes ) ) ),
		);

		foreach ( $writes as $write ) {
			$this->write_meta( $product_id, $write[0], $write[1] );

			if ( 0 < $this->write_failures ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write one meta value, counting it when it did not store.
	 *
	 * WordPress's update_post_meta() returns false both on failure and when
	 * nothing changed, so false alone is not a failure. Only a stored value
	 * that still differs is (RemoteAttachmentCreator::write_meta()).
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value to store, slashed where WordPress expects it.
	 * @return void
	 */
	private function write_meta( $post_id, $key, $value ) {
		if ( false !== update_post_meta( $post_id, $key, $value ) ) {
			return;
		}

		$stored   = get_post_meta( $post_id, $key, true );
		$expected = is_array( $value ) ? self::unslash( $value ) : $value;

		if ( $stored === $expected ) {
			return;
		}

		++$this->write_failures;
	}

	/**
	 * A meta value that must be an array: anything else, absent or corrupt,
	 * reads as empty.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return array
	 */
	private function array_meta( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * The inverse of wp_slash() for a nested array of strings.
	 *
	 * @param array $value Slashed array.
	 * @return array
	 */
	private static function unslash( array $value ) {
		return array_map(
			static function ( $item ) {
				if ( is_array( $item ) ) {
					return self::unslash( $item );
				}

				return is_string( $item ) ? stripslashes( $item ) : $item;
			},
			$value
		);
	}

	/**
	 * The arm-1 marker predicate: either marker absent or not matching.
	 *
	 * The ruleset hash is prepared, never interpolated.
	 *
	 * @return string
	 */
	private function stale_clause() {
		global $wpdb;

		$hash = $wpdb->prepare( '%s', AttributeUnpacker::ruleset_hash( $this->rules ) );

		return " AND (
					NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} a
						WHERE a.post_id = p.ID AND a.meta_key = '" . self::APPLIED_MARKER . "'
						AND a.meta_value = SHA2(CONCAT(m.meta_value, '|', " . $hash . '), 256)
					)
					OR NOT EXISTS (
						SELECT 1 FROM ' . $wpdb->postmeta . " r
						WHERE r.post_id = p.ID AND r.meta_key = '" . self::ROW_MARKER . "'
						AND r.meta_value = SHA2(COALESCE(pa.meta_value, ''), 256)
					)
				)";
	}

	/**
	 * An id-exclusion clause, or nothing. Ids are cast to integers and inlined:
	 * `prepare()` has no placeholder for a list.
	 *
	 * @param array<int, int> $exclude Product ids to leave out.
	 * @return string
	 */
	private function exclude_clause( array $exclude ) {
		if ( array() === $exclude ) {
			return '';
		}

		return ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';
	}

	/**
	 * A prepared LIMIT clause, or nothing.
	 *
	 * @param int $limit Maximum rows, 0 for no limit.
	 * @return string
	 */
	private function limit_clause( $limit ) {
		global $wpdb;

		$limit = (int) $limit;

		return $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
	}
}
