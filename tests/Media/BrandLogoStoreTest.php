<?php
/**
 * Tests for BrandLogoStore.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\BrandLogoStore;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * BrandLogoStore: the reads the brand-logo command needs, and the thumbnail
 * cleanup the delete guard needs.
 */
class BrandLogoStoreTest extends TestCase {

	/**
	 * Mocked $wpdb.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * Install a $wpdb mock.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb           = Mockery::mock( 'wpdb_for_test' );
		$this->wpdb->postmeta = 'wp_postmeta';
		$this->wpdb->posts    = 'wp_posts';
		$GLOBALS['wpdb']      = $this->wpdb;
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
	}

	/**
	 * Drop the $wpdb mock.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A term object.
	 *
	 * @param int    $id   Term id.
	 * @param string $name Name.
	 * @param string $slug Slug.
	 * @return object
	 */
	private function term( $id, $name, $slug ) {
		return (object) array(
			'term_id' => $id,
			'name'    => $name,
			'slug'    => $slug,
		);
	}

	/**
	 * No codes means no query.
	 */
	public function test_no_codes_reads_nothing() {
		Functions\expect( 'get_terms' )->never();

		$this->assertSame( array(), ( new BrandLogoStore() )->terms_for_codes( array() ) );
	}

	/**
	 * A get_terms error reads as no terms, so nothing is planned against it.
	 */
	public function test_a_get_terms_error_is_no_terms() {
		Functions\when( 'get_terms' )->justReturn( new \WP_Error( 'invalid_taxonomy', 'no' ) );

		$this->assertSame( array(), ( new BrandLogoStore() )->terms_for_codes( array( 'glock' ) ) );
	}

	/**
	 * Terms match on `_fa_akeneo_code` first. Codes left over fall back to the
	 * slug (`_` to `-`) and say so. A code with a duplicate keeps the lowest
	 * term id.
	 */
	public function test_terms_match_on_akeneo_code_then_slug() {
		$queries = array();
		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$queries ) {
				$queries[] = $args;
				if ( isset( $args['meta_query'] ) ) {
					return array(
						$this->term( 9, 'Glock Dup', 'glock-2' ),
						$this->term( 7, 'Glock', 'glock' ),
					);
				}
				return array( $this->term( 8, 'A-Zoom', 'a-zoom' ) );
			}
		);
		Functions\when( 'get_term_meta' )->alias( fn( $id, $key ) => '_fa_akeneo_code' === $key && in_array( $id, array( 7, 9 ), true ) ? 'glock' : '' );

		$terms = ( new BrandLogoStore() )->terms_for_codes( array( 'glock', 'a_zoom', 'nope' ) );

		$this->assertSame(
			array(
				'glock'  => array(
					'term_id'    => 7,
					'name'       => 'Glock',
					'matched_by' => 'code',
				),
				'a_zoom' => array(
					'term_id'    => 8,
					'name'       => 'A-Zoom',
					'matched_by' => 'slug',
				),
			),
			$terms
		);
		$this->assertSame( 'product_brand', $queries[0]['taxonomy'] );
		$this->assertFalse( $queries[0]['hide_empty'] );
		$this->assertSame(
			array(
				array(
					'key'     => '_fa_akeneo_code',
					'value'   => array( 'glock', 'a_zoom', 'nope' ),
					'compare' => 'IN',
				),
			),
			$queries[0]['meta_query']
		);
		$this->assertSame( array( 'a-zoom', 'nope' ), $queries[1]['slug'] );
	}

	/**
	 * A term already matched by code is never matched again by slug, so two
	 * codes cannot claim one term (PR #107 review): `foo_bar` owns term 7 by
	 * code, and the code `foo-bar`, whose slug is also `foo-bar`, gets no term.
	 */
	public function test_the_slug_fallback_skips_terms_matched_by_code() {
		Functions\when( 'get_terms' )->justReturn( array( $this->term( 7, 'Foo Bar', 'foo-bar' ) ) );
		Functions\when( 'get_term_meta' )->justReturn( 'foo_bar' );

		$this->assertSame(
			array(
				'foo_bar' => array(
					'term_id'    => 7,
					'name'       => 'Foo Bar',
					'matched_by' => 'code',
				),
			),
			( new BrandLogoStore() )->terms_for_codes( array( 'foo_bar', 'foo-bar' ) )
		);
	}

	/**
	 * Brand-logo attachments are unparented attachments carrying
	 * `_fa_brand_logo_s3_key`, keyed by that key; a duplicate key keeps the
	 * lowest id.
	 */
	public function test_logo_attachments_are_keyed_by_s3_key() {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with(
				Mockery::on(
					fn( $sql ) => false !== strpos( $sql, "k.meta_key = '_fa_brand_logo_s3_key'" )
						&& false !== strpos( $sql, "a.post_type = 'attachment'" )
						&& false !== strpos( $sql, 'a.post_parent = 0' )
						&& false !== strpos( $sql, 'ORDER BY a.ID ASC' )
				),
				'ARRAY_A'
			)
			->andReturn(
				array(
					array( 'ID' => '50', 's3_key' => 'files/product_brands/Glock-Logo.jpg' ),
					array( 'ID' => '51', 's3_key' => 'files/product_brands/Glock-Logo.jpg' ),
				)
			);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key ) => array(
				'_fa_remote_url'            => 'https://ik/x.jpg',
				'_fa_remote_width'          => '400',
				'_fa_remote_height'         => '',
				'_wp_attachment_image_alt'  => 'Glock',
			)[ $key ] ?? ''
		);

		$this->assertSame(
			array(
				'files/product_brands/Glock-Logo.jpg' => array(
					'id'     => 50,
					'url'    => 'https://ik/x.jpg',
					'width'  => 400,
					'height' => 0,
					'alt'    => 'Glock',
				),
			),
			( new BrandLogoStore() )->logo_attachments()
		);
	}

	/**
	 * Each term's thumbnail is classified: empty, pointing at a deleted post,
	 * at one of ours, or at something uploaded by hand.
	 */
	public function test_thumbnail_states_are_classified() {
		Functions\when( 'get_term_meta' )->alias( fn( $term_id ) => array( 1 => '', 2 => '404', 3 => '50', 4 => '300', 5 => '60' )[ $term_id ] );
		Functions\when( 'get_post' )->alias(
			fn( $id ) => array(
				50  => (object) array( 'post_type' => 'attachment' ),
				300 => (object) array( 'post_type' => 'attachment' ),
				60  => (object) array( 'post_type' => 'page' ),
			)[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id ) => 50 === $id ? 'files/product_brands/Glock-Logo.jpg' : '' );

		$this->assertSame(
			array(
				1 => array( 'id' => 0, 'state' => 'empty' ),
				2 => array( 'id' => 404, 'state' => 'missing' ),
				3 => array( 'id' => 50, 'state' => 'ours' ),
				4 => array( 'id' => 300, 'state' => 'manual' ),
				5 => array( 'id' => 60, 'state' => 'missing' ),
			),
			( new BrandLogoStore() )->thumbnail_states( array( 1, 2, 3, 4, 5 ) )
		);
	}

	/**
	 * Clearing removes thumbnail_id only from brand terms pointing at the id.
	 */
	public function test_clear_thumbnails_removes_only_matching_rows() {
		$query = null;
		Functions\when( 'get_terms' )->alias(
			function ( $args ) use ( &$query ) {
				$query = $args;
				return array( 7, 12 );
			}
		);
		$deleted = array();
		Functions\when( 'delete_term_meta' )->alias(
			function ( $term_id, $key, $value ) use ( &$deleted ) {
				$deleted[] = array( $term_id, $key, $value );
				return true;
			}
		);

		$this->assertSame( 2, ( new BrandLogoStore() )->clear_thumbnails_pointing_at( 50 ) );
		$this->assertSame( array( array( 7, 'thumbnail_id', 50 ), array( 12, 'thumbnail_id', 50 ) ), $deleted );
		$this->assertSame( 'product_brand', $query['taxonomy'] );
		$this->assertSame( 'ids', $query['fields'] );
		$this->assertSame( array( array( 'key' => 'thumbnail_id', 'value' => 50 ) ), $query['meta_query'] );
	}
}
