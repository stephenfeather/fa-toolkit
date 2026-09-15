<?php
/**
 * Tests for BrandLogoCreator.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\BrandLogoCreator;
use FAToolkit\Tests\TestCase;

/**
 * BrandLogoCreator: carries out a BrandLogoPlan. It creates or refreshes the
 * attachments and sets term thumbnails.
 *
 * Brand logos take the product-image path exactly (operator, 2026-09-15:
 * "images are stored on S3, images are not stored on the web host"): nothing
 * is downloaded, `_wp_attached_file` is the bare basename, and dimensions and
 * sha256 come from the map or are not written at all.
 */
class BrandLogoCreatorTest extends TestCase {

	private const KEY = 'files/product_brands/Glock-Logo.png';
	private const URL = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/Glock-Logo.png';
	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * Captured writes.
	 *
	 * @var \ArrayObject
	 */
	private $writes;

	/**
	 * Stub the WordPress writes, recording each. Any HTTP call fails the test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$writes       = new \ArrayObject(
			array(
				'inserted'  => array(),
				'post_meta' => array(),
				'term_meta' => array(),
			)
		);
		$this->writes = $writes;

		Functions\expect( 'wp_remote_get' )->never();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			array(
				'thumbnail' => array( 'width' => 150, 'height' => 150, 'crop' => true ),
				'medium'    => array( 'width' => 300, 'height' => 300, 'crop' => false ),
			)
		);
		Functions\when( 'wp_insert_attachment' )->alias(
			function ( $args, $file, $parent ) use ( $writes ) {
				$inserted           = $writes['inserted'];
				$inserted[]         = array( $args, $file, $parent );
				$writes['inserted'] = $inserted;
				return 100;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) use ( $writes ) {
				$meta                     = $writes['post_meta'];
				$meta[ $id . ':' . $key ] = $value;
				$writes['post_meta']      = $meta;
				return true;
			}
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( $id, $key, $value ) use ( $writes ) {
				$meta                     = $writes['term_meta'];
				$meta[ $id . ':' . $key ] = $value;
				$writes['term_meta']      = $meta;
				return true;
			}
		);
	}

	/**
	 * A one-logo plan.
	 *
	 * @param int        $attachment_id 0 to create.
	 * @param array|null $rows          Rows; default one row setting term 7.
	 * @param array      $overrides     Attachment field overrides.
	 * @return array
	 */
	private function plan( $attachment_id = 0, ?array $rows = null, array $overrides = array() ) {
		$attachment = array_merge(
			array(
				's3_key'        => self::KEY,
				'url'           => self::URL,
				'attachment_id' => $attachment_id,
				'alt'           => 'Glock',
				'term_ids'      => array( 7 ),
				'unused_names'  => array(),
				'width'         => null,
				'height'        => null,
				'sha256'        => null,
				'refresh'       => array(),
			),
			$overrides
		);

		$attachment['refresh'] = array_merge(
			array(
				'url'        => false,
				'dimensions' => false,
				'alt'        => false,
			),
			$attachment['refresh']
		);

		return array(
			'attachments' => array( self::KEY => $attachment ),
			'rows'        => $rows ?? array( $this->row( 'glock', 7, 'set' ) ),
			'no_term'     => array(),
			'skipped'     => array(
				'MISSING'        => array(),
				'conflict'       => array(),
				'near_identical' => array(),
			),
		);
	}

	/**
	 * A plan row.
	 *
	 * @param string $code       Code.
	 * @param int    $term_id    Term id.
	 * @param string $action     Action.
	 * @param int    $current_id Current thumbnail id.
	 * @return array
	 */
	private function row( $code, $term_id, $action, $current_id = 0 ) {
		return array(
			'code'       => $code,
			'term_id'    => $term_id,
			's3_key'     => self::KEY,
			'matched_by' => 'code',
			'action'     => $action,
			'current_id' => $current_id,
		);
	}

	/**
	 * Map-supplied image fields.
	 *
	 * @return array
	 */
	private function sized() {
		return array(
			'width'  => 640,
			'height' => 320,
			'sha256' => self::SHA,
		);
	}

	/**
	 * An insert that fails writes no meta, sets no thumbnail, and blocks the brand.
	 */
	public function test_a_failed_insert_writes_no_meta_and_no_thumbnail() {
		Functions\when( 'wp_insert_attachment' )->justReturn( new \WP_Error( 'db', 'nope' ) );

		$totals = ( new BrandLogoCreator() )->apply( $this->plan() );

		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array(), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['insert_failed'] );
		$this->assertSame( array( 'glock' ), $totals['blocked'] );
	}

	/**
	 * An insert returning 0 is a failure too.
	 */
	public function test_an_insert_returning_zero_is_a_failure() {
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$totals = ( new BrandLogoCreator() )->apply( $this->plan() );

		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( 1, $totals['insert_failed'] );
	}

	/**
	 * A term meta write that does not store is counted.
	 */
	public function test_a_thumbnail_write_that_does_not_store_is_counted() {
		Functions\when( 'update_term_meta' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );

		$totals = ( new BrandLogoCreator() )->apply( $this->plan() );

		$this->assertSame( 1, $totals['write_failed'] );
		$this->assertSame( 0, $totals['thumbnails_set'] );
	}

	/**
	 * Created with map dimensions and sha256: the full product meta contract,
	 * with a bare-basename attached file, and the thumbnail set.
	 */
	public function test_create_with_map_dimensions_writes_the_product_contract() {
		$totals = ( new BrandLogoCreator() )->apply( $this->plan( 0, null, $this->sized() ) );

		$this->assertSame(
			array(
				array(
					array(
						'post_title'     => 'Glock',
						'post_mime_type' => 'image/png',
						'post_status'    => 'inherit',
					),
					false,
					0,
				),
			),
			$this->writes['inserted']
		);

		$meta = $this->writes['post_meta'];
		$this->assertSame( self::KEY, $meta['100:_fa_brand_logo_s3_key'] );
		$this->assertSame( self::URL, $meta['100:_fa_remote_url'] );
		$this->assertSame( self::SHA, $meta['100:_fa_media_sha256'] );
		$this->assertSame( 640, $meta['100:_fa_remote_width'] );
		$this->assertSame( 320, $meta['100:_fa_remote_height'] );
		$this->assertSame( 'Glock-Logo.png', $meta['100:_wp_attached_file'] );
		$this->assertSame( 'Glock-Logo.png', $meta['100:_wp_attachment_metadata']['file'] );
		$this->assertSame( 640, $meta['100:_wp_attachment_metadata']['width'] );
		$this->assertSame( 'Glock', $meta['100:_wp_attachment_image_alt'] );

		$this->assertSame( array( '7:thumbnail_id' => 100 ), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['created'] );
		$this->assertSame( 1, $totals['thumbnails_set'] );
		$this->assertSame( array(), $totals['blocked'] );
	}

	/**
	 * Created without map dimensions: the product degraded path. No width,
	 * height, attached file or metadata is invented, and none is fetched.
	 * The thumbnail is still set; the logo renders the raw URL unsized.
	 */
	public function test_create_without_map_dimensions_writes_no_image_metadata() {
		( new BrandLogoCreator() )->apply( $this->plan() );

		$meta = $this->writes['post_meta'];
		$this->assertSame(
			array(
				'100:_fa_brand_logo_s3_key',
				'100:_fa_remote_url',
				'100:_wp_attachment_image_alt',
			),
			array_keys( $meta )
		);
		$this->assertSame( array( '7:thumbnail_id' => 100 ), $this->writes['term_meta'] );
	}

	/**
	 * An empty alt is never written.
	 */
	public function test_an_empty_alt_is_not_written() {
		( new BrandLogoCreator() )->apply( $this->plan( 0, null, array( 'alt' => '' ) ) );

		$this->assertArrayNotHasKey( '100:_wp_attachment_image_alt', $this->writes['post_meta'] );
	}

	/**
	 * A plan with nothing to change writes nothing.
	 */
	public function test_an_unchanged_plan_writes_nothing() {
		$totals = ( new BrandLogoCreator() )->apply( $this->plan( 50, array( $this->row( 'glock', 7, 'already_set', 50 ) ), $this->sized() ) );

		$this->assertSame( array(), $this->writes['inserted'] );
		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array(), $this->writes['term_meta'] );
		$this->assertSame( 0, $totals['created'] + $totals['refreshed'] + $totals['thumbnails_set'] );
	}

	/**
	 * A changed url and a changed alt are written onto the existing attachment.
	 */
	public function test_url_and_alt_refresh() {
		$totals = ( new BrandLogoCreator() )->apply(
			$this->plan(
				50,
				null,
				array(
					'refresh' => array(
						'url' => true,
						'alt' => true,
					),
				)
			)
		);

		$this->assertSame(
			array(
				'50:_fa_remote_url'           => self::URL,
				'50:_wp_attachment_image_alt' => 'Glock',
			),
			$this->writes['post_meta']
		);
		$this->assertSame( array( '7:thumbnail_id' => 50 ), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['refreshed'] );
	}

	/**
	 * Map dimensions that differ from the stored ones are written, with the
	 * attached file, metadata and sha256 they travel with.
	 */
	public function test_changed_map_dimensions_are_written() {
		$totals = ( new BrandLogoCreator() )->apply( $this->plan( 50, null, array_merge( $this->sized(), array( 'refresh' => array( 'dimensions' => true ) ) ) ) );

		$meta = $this->writes['post_meta'];
		$this->assertSame( 640, $meta['50:_fa_remote_width'] );
		$this->assertSame( 320, $meta['50:_fa_remote_height'] );
		$this->assertSame( self::SHA, $meta['50:_fa_media_sha256'] );
		$this->assertSame( 'Glock-Logo.png', $meta['50:_wp_attached_file'] );
		$this->assertArrayHasKey( '50:_wp_attachment_metadata', $meta );
		$this->assertSame( 1, $totals['refreshed'] );
	}

	/**
	 * One attachment serves every row sharing its key; kept rows are untouched.
	 */
	public function test_a_shared_attachment_is_set_on_every_set_row() {
		$rows = array(
			$this->row( 'glock', 7, 'set' ),
			$this->row( 'glock_inc', 12, 'set' ),
			$this->row( 'glock_gmbh', 13, 'kept_manual', 300 ),
		);

		$totals = ( new BrandLogoCreator() )->apply( $this->plan( 0, $rows ) );

		$this->assertCount( 1, $this->writes['inserted'] );
		$this->assertSame(
			array(
				'7:thumbnail_id'  => 100,
				'12:thumbnail_id' => 100,
			),
			$this->writes['term_meta']
		);
		$this->assertSame( 2, $totals['thumbnails_set'] );
	}
}
