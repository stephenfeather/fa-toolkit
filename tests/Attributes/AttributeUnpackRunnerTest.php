<?php
/**
 * Tests for AttributeUnpackRunner.
 *
 * @package FAToolkit\Tests\Attributes
 */

namespace FAToolkit\Tests\Attributes;

use Brain\Monkey\Functions;
use FAToolkit\Attributes\AttributeUnpacker;
use FAToolkit\Attributes\AttributeUnpackRunner;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * AttributeUnpackRunner: the product loop shared by the after-import listener
 * and the CLI command. Selection in SQL against the two markers, the per-product
 * read/unpack/write, and the run totals live here; the unpack itself stays in
 * AttributeUnpacker.
 *
 * Meta is backed by an in-memory store that behaves like WordPress: values are
 * unslashed on write, and `update_post_meta()` returns false when the stored
 * value already equals the new one.
 */
class AttributeUnpackRunnerTest extends TestCase {

	private const CELL = '{"material":"Steel"}';

	/**
	 * Mocked $wpdb.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * In-memory postmeta: post id => meta key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $meta = array();

	/**
	 * Post types by id; a missing id is a product.
	 *
	 * @var array<int, string>
	 */
	private $types = array();

	/**
	 * Every update_post_meta() call, in order: [post id, key, value].
	 *
	 * @var array<int, array{0:int,1:string,2:mixed}>
	 */
	private $writes = array();

	/**
	 * Post ids whose cache was cleaned.
	 *
	 * @var array<int, int>
	 */
	private $cleaned = array();

	/**
	 * Install a $wpdb mock and the meta store.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb           = Mockery::mock( 'wpdb_for_test' );
		$this->wpdb->postmeta = 'wp_postmeta';
		$this->wpdb->posts    = 'wp_posts';
		$GLOBALS['wpdb']      = $this->wpdb;

		$this->meta    = array();
		$this->types   = array();
		$this->writes  = array();
		$this->cleaned = array();

		Functions\when( 'get_post_type' )->alias( fn( $id ) => $this->types[ $id ] ?? 'product' );
		Functions\when( 'maybe_serialize' )->alias( fn( $value ) => is_array( $value ) ? serialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		Functions\when( 'wp_slash' )->alias( array( $this, 'slash' ) );
		Functions\when( 'clean_post_cache' )->alias(
			function ( $id ) {
				$this->cleaned[] = $id;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( array( $this, 'store' ) );
	}

	/**
	 * Drop the $wpdb mock.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * wp_slash(): addslashes, recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function slash( $value ) {
		return is_array( $value ) ? array_map( array( $this, 'slash' ), $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
	}

	/**
	 * update_post_meta() as WordPress behaves: unslash, store, and answer false
	 * when the stored value was already equal.
	 *
	 * @param int    $id    Post id.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function store( $id, $key, $value ) {
		$value          = $this->unslash( $value );
		$this->writes[] = array( $id, $key, $value );

		if ( isset( $this->meta[ $id ][ $key ] ) && $this->meta[ $id ][ $key ] === $value ) {
			return false;
		}

		$this->meta[ $id ][ $key ] = $value;

		return true;
	}

	/**
	 * stripslashes, recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function unslash( $value ) {
		return is_array( $value ) ? array_map( array( $this, 'unslash' ), $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}

	/**
	 * One local attribute entry as the unpacker writes it.
	 *
	 * @param string $name     Label.
	 * @param string $value    Value.
	 * @param int    $position Position.
	 * @return array
	 */
	private function entry( $name, $value, $position = 0 ) {
		return array(
			'name'         => $name,
			'value'        => $value,
			'position'     => $position,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 0,
		);
	}

	/**
	 * Seed one product's meta.
	 *
	 * @param int   $id   Product id.
	 * @param array $meta Meta key => value.
	 * @return void
	 */
	private function seed( $id, array $meta ) {
		$this->meta[ $id ] = $meta;
	}

	/**
	 * The applied marker's value for a cell under the default rules.
	 *
	 * @param string $cell The cell.
	 * @return string
	 */
	private function applied_marker( $cell ) {
		return hash( 'sha256', $cell . '|' . AttributeUnpacker::ruleset_hash( AttributeUnpacker::default_rules() ) );
	}

	/**
	 * A runner over the default rules.
	 *
	 * @return AttributeUnpackRunner
	 */
	private function runner() {
		return new AttributeUnpackRunner( AttributeUnpacker::default_rules() );
	}

	/*
	 * ---- Selection ----
	 */

	/**
	 * Whether a query carries selection arm 1: a non-empty cell whose applied
	 * marker or row marker fails to match, both compared in MySQL. The applied
	 * marker is compared against the cell joined to the prepared ruleset hash;
	 * the row marker against the `_product_attributes` row, `''` when absent.
	 *
	 * @param string $sql Query text.
	 * @return bool
	 */
	private function has_stale_arm( $sql ) {
		return false !== strpos( $sql, "a.meta_key = '_fa_attributes_applied_sha256'" )
			&& false !== strpos( $sql, "a.meta_value = SHA2(CONCAT(m.meta_value, '|', 'RULESET'), 256)" )
			&& false !== strpos( $sql, "r.meta_key = '_fa_attributes_row_sha256'" )
			&& false !== strpos( $sql, "r.meta_value = SHA2(COALESCE(pa.meta_value, ''), 256)" )
			&& false !== strpos( $sql, "pa.meta_key = '_product_attributes'" );
	}

	/**
	 * Whether a query carries selection arm 2: a non-empty sidecar beside an
	 * absent or empty cell, which is what a blank CSV cell writes (I-01).
	 *
	 * @param string $sql Query text.
	 * @return bool
	 */
	private function has_cleared_arm( $sql ) {
		return false !== strpos( $sql, "s.meta_key = '_fa_attributes_unpacked'" )
			&& false !== strpos( $sql, "COALESCE(s.meta_value, '') NOT IN ('', 'a:0:{}')" )
			&& false !== strpos( $sql, "COALESCE(m.meta_value, '') = ''" );
	}

	/**
	 * Let prepare() through for the ruleset hash and the limit.
	 *
	 * @return void
	 */
	private function allow_prepare() {
		$this->wpdb->shouldReceive( 'prepare' )->with( '%s', Mockery::type( 'string' ) )->andReturn( "'RULESET'" );
		$this->wpdb->shouldReceive( 'prepare' )->with( ' LIMIT %d', Mockery::type( 'int' ) )->andReturnUsing( fn( $sql, $n ) => " LIMIT $n" );
	}

	/**
	 * The default selection carries both arms, the ruleset hash prepared and
	 * never interpolated, is restricted to products, and is ordered by id with
	 * no LIMIT when none is asked for.
	 */
	public function test_products_to_unpack_selects_both_arms_over_products_in_sql() {
		$this->allow_prepare();
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => $this->has_stale_arm( $sql )
						&& $this->has_cleared_arm( $sql )
						&& false !== strpos( $sql, "p.post_type = 'product'" )
						&& false !== strpos( $sql, "m.meta_key = '_fa_attributes'" )
						&& str_ends_with( $sql, 'ORDER BY p.ID ASC' )
				)
			)
			->andReturn( array( '3', '9' ) );

		$this->assertSame( array( 3, 9 ), $this->runner()->products_to_unpack() );
	}

	/**
	 * The ruleset hash in the query is the one the run writes, so the SQL
	 * comparison and the PHP marker agree.
	 */
	public function test_products_to_unpack_prepares_the_current_ruleset_hash() {
		$hash = AttributeUnpacker::ruleset_hash( AttributeUnpacker::default_rules() );
		$this->wpdb->shouldReceive( 'prepare' )->once()->with( '%s', $hash )->andReturn( "'RULESET'" );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$this->runner()->products_to_unpack();
	}

	/**
	 * A limit is prepared into the query, never interpolated.
	 */
	public function test_products_to_unpack_prepares_the_limit() {
		$this->allow_prepare();
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => str_ends_with( $sql, 'ORDER BY p.ID ASC LIMIT 200' ) ) )
			->andReturn( array() );

		$this->assertSame( array(), $this->runner()->products_to_unpack( 200 ) );
	}

	/**
	 * Products named in the exclusion list are cut in SQL, cast to integers,
	 * so a caller draining in batches can step past a product that failed.
	 */
	public function test_products_to_unpack_excludes_given_products_as_integers() {
		$this->allow_prepare();
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, 'p.ID NOT IN (5,3,0)' )
						&& false === strpos( $sql, 'DROP' )
				)
			)
			->andReturn( array( '7' ) );

		$this->assertSame( array( 7 ), $this->runner()->products_to_unpack( 0, array( '5', '3; DROP TABLE wp_posts', 'DROP' ) ) );
	}

	/**
	 * An empty exclusion list adds no clause.
	 */
	public function test_products_to_unpack_without_exclusions_has_no_not_in_clause() {
		$this->allow_prepare();
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with( Mockery::on( fn( $sql ) => false === strpos( $sql, 'p.ID NOT IN' ) ) )
			->andReturn( array() );

		$this->runner()->products_to_unpack( 0, array() );
	}

	/**
	 * Force ignores both markers: every product with a non-empty cell or a
	 * non-empty sidecar is selected, still restricted to products.
	 */
	public function test_products_to_unpack_forced_ignores_the_markers() {
		$this->allow_prepare();
		$this->wpdb->shouldReceive( 'get_col' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false === strpos( $sql, '_fa_attributes_applied_sha256' )
						&& false === strpos( $sql, '_fa_attributes_row_sha256' )
						&& false !== strpos( $sql, "COALESCE(m.meta_value, '') <> ''" )
						&& false !== strpos( $sql, "COALESCE(s.meta_value, '') NOT IN ('', 'a:0:{}')" )
						&& false !== strpos( $sql, "p.post_type = 'product'" )
				)
			)
			->andReturn( array( '1', '2' ) );

		$this->assertSame( array( 1, 2 ), $this->runner()->products_to_unpack( 0, array(), true ) );
	}

	/**
	 * products_without_attributes_cell() counts products that carry no
	 * `_fa_attributes` key at all, which is how an unmapped import profile
	 * shows up in the listener's summary (#115).
	 */
	public function test_products_without_attributes_cell_counts_absent_keys() {
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "post_type = 'product'" )
						&& false !== strpos( $sql, "meta_key = '_fa_attributes'" )
						&& false !== strpos( $sql, 'NOT EXISTS' )
				)
			)
			->andReturn( '41' );

		$this->assertSame( 41, $this->runner()->products_without_attributes_cell() );
	}

	/*
	 * ---- Per product ----
	 */

	/**
	 * A clean pass writes all four values: the row, the sidecar as the slugs
	 * this pass wrote, the applied marker over cell and ruleset, and the row
	 * marker over the row as stored.
	 */
	public function test_run_writes_the_row_the_sidecar_and_both_markers_on_a_clean_pass() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		$row = array( 'material' => $this->entry( 'Material', 'Steel' ) );

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( $row, $this->meta[5]['_product_attributes'] );
		$this->assertSame( array( 'material' ), $this->meta[5]['_fa_attributes_unpacked'] );
		$this->assertSame( $this->applied_marker( self::CELL ), $this->meta[5]['_fa_attributes_applied_sha256'] );
		$this->assertSame( hash( 'sha256', serialize( $row ) ), $this->meta[5]['_fa_attributes_row_sha256'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( 1, $totals['written'] );
		$this->assertSame( array( 5 ), $this->cleaned );
	}

	/**
	 * The row and the sidecar are slashed on the way in, as update_post_meta()
	 * expects, so a value with a backslash is stored as unpacked and the row
	 * marker matches what MySQL hashes.
	 */
	public function test_run_slashes_the_row_so_a_backslash_survives_the_write() {
		$this->seed( 5, array( '_fa_attributes' => '{"note":"a\\\\b"}' ) );

		$this->runner()->run( array( 5 ) );

		$this->assertSame( 'a\\b', $this->meta[5]['_product_attributes']['note']['value'] );
		$this->assertSame( hash( 'sha256', serialize( $this->meta[5]['_product_attributes'] ) ), $this->meta[5]['_fa_attributes_row_sha256'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * The totals carry every key the listener merges, at zero for an empty run.
	 */
	public function test_run_totals_have_the_full_shape() {
		$this->assertSame(
			array(
				'products'     => 0,
				'written'      => 0,
				'cleared'      => 0,
				'unchanged'    => 0,
				'invalid'      => 0,
				'skipped'      => 0,
				'collisions'   => 0,
				'unsupported'  => 0,
				'write_failed' => 0,
			),
			$this->runner()->run( array() )
		);
	}

	/**
	 * An invalid cell keeps the previous attributes and gets no marker, so it
	 * stays selectable. Counted invalid.
	 */
	public function test_run_leaves_an_invalid_cell_unmarked_and_untouched() {
		$row = array( 'material' => $this->entry( 'Material', 'Steel' ) );
		$this->seed(
			5,
			array(
				'_fa_attributes'          => '[1,2]',
				'_product_attributes'     => $row,
				'_fa_attributes_unpacked' => array( 'material' ),
			)
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( $row, $this->meta[5]['_product_attributes'] );
		$this->assertSame( 1, $totals['invalid'] );
		$this->assertSame( 0, $totals['written'] );
		$this->assertSame( array(), $this->cleaned );
	}

	/**
	 * A product whose row gained a `pa_*` twin since the last pass is re-unpacked
	 * against the new row: the twin is removed, the sidecar drops it, and the
	 * `pa_*` entry itself is untouched.
	 */
	public function test_run_recomputes_the_shadow_when_the_row_changed_under_the_pass() {
		$pa = array(
			'name'         => 'pa_material',
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		);
		$this->seed(
			5,
			array(
				'_fa_attributes'          => self::CELL,
				'_product_attributes'     => array(
					'material'    => $this->entry( 'Material', 'Steel', 1 ),
					'pa_material' => $pa,
				),
				'_fa_attributes_unpacked' => array( 'material' ),
			)
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( array( 'pa_material' => $pa ), $this->meta[5]['_product_attributes'] );
		$this->assertSame( array(), $this->meta[5]['_fa_attributes_unpacked'] );
		$this->assertSame( 1, $totals['cleared'] );
	}

	/**
	 * A foreign entry removed since the last pass frees its slug: the key that
	 * collided last time is written now and the collision count goes to zero.
	 */
	public function test_run_recomputes_a_collision_when_the_foreign_entry_is_gone() {
		$this->seed(
			5,
			array(
				'_fa_attributes'      => self::CELL,
				'_product_attributes' => array( 'material' => $this->entry( 'Material', 'Hand made' ) ),
			)
		);
		$first = $this->runner()->run( array( 5 ) );
		$this->assertSame( 1, $first['collisions'] );
		$this->assertSame( array(), $this->meta[5]['_fa_attributes_unpacked'] );

		// An admin deletes the hand-made entry; the row marker no longer matches.
		$this->meta[5]['_product_attributes'] = array();

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( 'Steel', $this->meta[5]['_product_attributes']['material']['value'] );
		$this->assertSame( array( 'material' ), $this->meta[5]['_fa_attributes_unpacked'] );
		$this->assertSame( 0, $totals['collisions'] );
		$this->assertSame( 1, $totals['written'] );
	}

	/**
	 * Collisions and unsupported values are summed across products.
	 */
	public function test_run_sums_collisions_and_unsupported_across_products() {
		$this->seed(
			5,
			array(
				'_fa_attributes'      => self::CELL,
				'_product_attributes' => array( 'material' => $this->entry( 'Material', 'Hand made' ) ),
			)
		);
		$this->seed( 6, array( '_fa_attributes' => '{"specs":{"a":1}}' ) );

		$totals = $this->runner()->run( array( 5, 6 ) );

		$this->assertSame( 1, $totals['collisions'] );
		$this->assertSame( 1, $totals['unsupported'] );
		$this->assertSame( 'Hand made', $this->meta[5]['_product_attributes']['material']['value'] );
	}

	/**
	 * Selection arm 2 in effect: an empty cell beside a non-empty sidecar
	 * removes the owned entries, empties the sidecar, and leaves foreign
	 * entries alone. Counted cleared.
	 */
	public function test_run_clears_a_product_whose_cell_went_empty() {
		$foreign = $this->entry( 'Colour', 'Blue', 3 );
		$this->seed(
			5,
			array(
				'_fa_attributes'          => '',
				'_product_attributes'     => array(
					'material' => $this->entry( 'Material', 'Steel' ),
					'colour'   => $foreign,
				),
				'_fa_attributes_unpacked' => array( 'material' ),
			)
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( array( 'colour' => $foreign ), $this->meta[5]['_product_attributes'] );
		$this->assertSame( array(), $this->meta[5]['_fa_attributes_unpacked'] );
		$this->assertSame( $this->applied_marker( '' ), $this->meta[5]['_fa_attributes_applied_sha256'] );
		$this->assertSame( 1, $totals['cleared'] );
		$this->assertSame( 0, $totals['written'] );
	}

	/**
	 * A variation is skipped before any meta is read or written.
	 */
	public function test_run_skips_a_product_variation() {
		$this->types[7] = 'product_variation';
		$this->seed( 7, array( '_fa_attributes' => self::CELL ) );

		$totals = $this->runner()->run( array( 7 ) );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 1, $totals['products'] );
	}

	/**
	 * A dry run reads and counts but writes nothing and cleans no cache.
	 */
	public function test_run_dry_writes_nothing() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		$this->seed( 6, array( '_fa_attributes' => 'nope' ) );

		$totals = $this->runner()->run( array( 5, 6 ), true );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( array(), $this->cleaned );
		$this->assertSame( 1, $totals['written'] );
		$this->assertSame( 1, $totals['invalid'] );
	}

	/**
	 * A second pass over an unchanged product rewrites nothing: every
	 * update_post_meta() answers false for an equal value, and that is
	 * unchanged, not a write failure. The cache is left alone.
	 */
	public function test_run_counts_an_unchanged_product_and_cleans_nothing() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		$this->runner()->run( array( 5 ) );
		$before        = $this->meta[5];
		$this->writes  = array();
		$this->cleaned = array();

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( $before, $this->meta[5] );
		$this->assertSame( 4, count( $this->writes ) );
		$this->assertSame( 1, $totals['unchanged'] );
		$this->assertSame( 0, $totals['write_failed'] );
		$this->assertSame( array(), $this->cleaned );
	}

	/**
	 * A row write that does not store leaves both markers unwritten, so the
	 * product stays selectable. Counted write_failed, not written.
	 */
	public function test_run_writes_no_marker_when_the_row_write_fails() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->writes[] = array( $id, $key, $value );
				return false;
			}
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( array( '_product_attributes' ), array_column( $this->writes, 1 ) );
		$this->assertSame( 1, $totals['write_failed'] );
		$this->assertSame( 0, $totals['written'] );
	}

	/**
	 * A marker write that does not store is a write failure too: the product
	 * keeps a stale or missing marker and is selected again. The row had
	 * already stored, so the product's cache is still cleaned (PR #122 review).
	 */
	public function test_run_counts_a_failed_marker_write() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		Functions\when( 'update_post_meta' )->alias(
			fn( $id, $key, $value ) => '_fa_attributes_row_sha256' === $key ? false : $this->store( $id, $key, $value )
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( 1, $totals['write_failed'] );
		$this->assertSame( 0, $totals['written'] );
		$this->assertArrayNotHasKey( '_fa_attributes_row_sha256', $this->meta[5] );
		$this->assertSame( array( 5 ), $this->cleaned );
	}

	/**
	 * A row or sidecar that is not an array (absent, or corrupt) is read as
	 * empty rather than passed through.
	 */
	public function test_run_reads_a_non_array_row_and_sidecar_as_empty() {
		$this->seed(
			5,
			array(
				'_fa_attributes'          => self::CELL,
				'_product_attributes'     => 'garbage',
				'_fa_attributes_unpacked' => 'garbage',
			)
		);

		$totals = $this->runner()->run( array( 5 ) );

		$this->assertSame( array( 'material' ), array_keys( $this->meta[5]['_product_attributes'] ) );
		$this->assertSame( 1, $totals['written'] );
	}

	/**
	 * The per-product callback fires once per product, after that product,
	 * with the product id and its outcome.
	 */
	public function test_run_invokes_the_tick_callback_once_per_product() {
		$this->seed( 5, array( '_fa_attributes' => self::CELL ) );
		$this->seed( 6, array( '_fa_attributes' => 'nope' ) );
		$ticks = array();

		$this->runner()->run(
			array( 5, 6 ),
			true,
			function ( $id, $outcome ) use ( &$ticks ) {
				$ticks[] = array( $id, $outcome );
			}
		);

		$this->assertSame( array( array( 5, 'written' ), array( 6, 'invalid' ) ), $ticks );
	}
}
