<?php
/**
 * Tests for BrandLogoCreator.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\BrandLogoCreator;
use FAToolkit\Media\BrandLogoFetcher;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * BrandLogoCreator: carries out a BrandLogoPlan. It creates or refreshes the
 * attachments and sets term thumbnails. A failed fetch or insert changes no
 * thumbnail; an unchanged plan writes nothing.
 */
class BrandLogoCreatorTest extends TestCase {

	private const KEY = 'files/product_brands/Glock-Logo.png';
	private const URL = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/Glock-Logo.png';

	/**
	 * Captured writes.
	 *
	 * @var \ArrayObject
	 */
	private $writes;

	/**
	 * Stub the WordPress writes, recording each.
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
				$inserted       = $writes['inserted'];
				$inserted[]     = array( $args, $file, $parent );
				$writes['inserted'] = $inserted;
				return 100;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) use ( $writes ) {
				$meta                  = $writes['post_meta'];
				$meta[ $id . ':' . $key ] = $value;
				$writes['post_meta']   = $meta;
				return true;
			}
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( $id, $key, $value ) use ( $writes ) {
				$meta                  = $writes['term_meta'];
				$meta[ $id . ':' . $key ] = $value;
				$writes['term_meta']   = $meta;
				return true;
			}
		);
	}

	/**
	 * A fetcher answering the given result, expected a number of times.
	 *
	 * @param array $result Fetch result.
	 * @param int   $times  Expected calls.
	 * @return BrandLogoFetcher
	 */
	private function fetcher( array $result, $times = 1 ) {
		$fetcher = Mockery::mock( BrandLogoFetcher::class );
		$fetcher->shouldReceive( 'fetch' )->times( $times )->with( self::URL )->andReturn( $result );

		return $fetcher;
	}

	/**
	 * A successful fetch.
	 *
	 * @return array
	 */
	private function ok() {
		return array(
			'status' => 'ok',
			'width'  => 640,
			'height' => 320,
			'mime'   => 'image/png',
			'sha256' => 'abc',
		);
	}

	/**
	 * A one-logo plan.
	 *
	 * @param int    $attachment_id 0 to create.
	 * @param array  $rows          Rows; default one row setting term 7.
	 * @param array  $refresh       Refresh flags.
	 * @param string $alt           Alt.
	 * @return array
	 */
	private function plan( $attachment_id = 0, ?array $rows = null, array $refresh = array(), $alt = 'Glock' ) {
		return array(
			'attachments' => array(
				self::KEY => array(
					's3_key'        => self::KEY,
					'url'           => self::URL,
					'attachment_id' => $attachment_id,
					'alt'           => $alt,
					'term_ids'      => array( 7 ),
					'unused_names'  => array(),
					'refresh'       => array_merge(
						array(
							'url'        => false,
							'dimensions' => false,
							'alt'        => false,
						),
						$refresh
					),
				),
			),
			'rows'        => $rows ?? array(
				array(
					'code'       => 'glock',
					'term_id'    => 7,
					's3_key'     => self::KEY,
					'matched_by' => 'code',
					'action'     => 'set',
					'current_id' => 0,
				),
			),
			'no_term'     => array(),
			'skipped'     => array(
				'MISSING'        => array(),
				'conflict'       => array(),
				'near_identical' => array(),
			),
		);
	}

	/**
	 * Fetch failures on create.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function failed_fetches() {
		return array(
			'dead'      => array( 'dead', 'dead' ),
			'failed'    => array( 'failed', 'fetch_failed' ),
			'not image' => array( 'not_image', 'not_image' ),
		);
	}

	/**
	 * A fetch that does not yield an image creates nothing and sets no
	 * thumbnail; the brand is reported as blocked.
	 *
	 * @param string $status Fetch status.
	 * @param string $total  Total it counts under.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failed_fetches' )]
	public function test_a_failed_fetch_on_create_writes_nothing( $status, $total ) {
		$totals = ( new BrandLogoCreator( $this->fetcher( array( 'status' => $status ) ) ) )->apply( $this->plan() );

		$this->assertSame( array(), $this->writes['inserted'] );
		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array(), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals[ $total ] );
		$this->assertSame( array( 'glock' ), $totals['blocked'] );
	}

	/**
	 * An insert that fails sets no thumbnail and writes no meta.
	 */
	public function test_a_failed_insert_writes_no_meta_and_no_thumbnail() {
		Functions\when( 'wp_insert_attachment' )->justReturn( new \WP_Error( 'db', 'nope' ) );

		$totals = ( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan() );

		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array(), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['insert_failed'] );
		$this->assertSame( array( 'glock' ), $totals['blocked'] );
	}

	/**
	 * A term meta write that does not store is counted.
	 */
	public function test_a_thumbnail_write_that_does_not_store_is_counted() {
		Functions\when( 'update_term_meta' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );

		$totals = ( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan() );

		$this->assertSame( 1, $totals['write_failed'] );
		$this->assertSame( 0, $totals['thumbnails_set'] );
	}

	/**
	 * Creating writes an unparented attachment carrying the whole meta
	 * contract, with a prefixed attached file, then sets the thumbnail.
	 */
	public function test_create_writes_the_attachment_contract_and_sets_the_thumbnail() {
		$totals = ( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan() );

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
		$this->assertSame( 'abc', $meta['100:_fa_media_sha256'] );
		$this->assertSame( 640, $meta['100:_fa_remote_width'] );
		$this->assertSame( 320, $meta['100:_fa_remote_height'] );
		$this->assertSame( 'fa-remote/product_brands/Glock-Logo.png', $meta['100:_wp_attached_file'] );
		$this->assertSame( 'fa-remote/product_brands/Glock-Logo.png', $meta['100:_wp_attachment_metadata']['file'] );
		$this->assertSame( 640, $meta['100:_wp_attachment_metadata']['width'] );
		$this->assertSame( 'Glock', $meta['100:_wp_attachment_image_alt'] );

		$this->assertSame( array( '7:thumbnail_id' => 100 ), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['created'] );
		$this->assertSame( 1, $totals['thumbnails_set'] );
		$this->assertSame( array(), $totals['blocked'] );
	}

	/**
	 * An empty alt is never written.
	 */
	public function test_an_empty_alt_is_not_written() {
		( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan( 0, null, array(), '' ) );

		$this->assertArrayNotHasKey( '100:_wp_attachment_image_alt', $this->writes['post_meta'] );
	}

	/**
	 * A plan with nothing to change fetches nothing and writes nothing.
	 */
	public function test_an_unchanged_plan_writes_nothing() {
		$rows = array(
			array(
				'code'       => 'glock',
				'term_id'    => 7,
				's3_key'     => self::KEY,
				'matched_by' => 'code',
				'action'     => 'already_set',
				'current_id' => 50,
			),
		);

		$totals = ( new BrandLogoCreator( $this->fetcher( array(), 0 ) ) )->apply( $this->plan( 50, $rows ) );

		$this->assertSame( array(), $this->writes['inserted'] );
		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array(), $this->writes['term_meta'] );
		$this->assertSame( 0, $totals['created'] + $totals['refreshed'] + $totals['thumbnails_set'] );
	}

	/**
	 * A changed url and a changed alt are written onto the existing
	 * attachment without a fetch.
	 */
	public function test_url_and_alt_refresh_without_a_fetch() {
		$totals = ( new BrandLogoCreator( $this->fetcher( array(), 0 ) ) )->apply(
			$this->plan(
				50,
				null,
				array(
					'url' => true,
					'alt' => true,
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
	 * Missing dimensions on an existing attachment are fetched and stored.
	 */
	public function test_missing_dimensions_are_fetched_and_stored() {
		( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan( 50, null, array( 'dimensions' => true ) ) );

		$meta = $this->writes['post_meta'];
		$this->assertSame( 640, $meta['50:_fa_remote_width'] );
		$this->assertSame( 320, $meta['50:_fa_remote_height'] );
		$this->assertSame( 'abc', $meta['50:_fa_media_sha256'] );
		$this->assertSame( 'fa-remote/product_brands/Glock-Logo.png', $meta['50:_wp_attached_file'] );
		$this->assertArrayHasKey( '50:_wp_attachment_metadata', $meta );
	}

	/**
	 * An existing attachment whose dimension fetch fails still serves the
	 * thumbnail (it renders unsized), and the failure is counted.
	 */
	public function test_a_failed_dimension_fetch_still_sets_the_thumbnail() {
		$totals = ( new BrandLogoCreator( $this->fetcher( array( 'status' => 'failed' ) ) ) )->apply( $this->plan( 50, null, array( 'dimensions' => true ) ) );

		$this->assertSame( array(), $this->writes['post_meta'] );
		$this->assertSame( array( '7:thumbnail_id' => 50 ), $this->writes['term_meta'] );
		$this->assertSame( 1, $totals['fetch_failed'] );
		$this->assertSame( array(), $totals['blocked'] );
	}

	/**
	 * One attachment serves every row sharing its key; kept rows are untouched.
	 */
	public function test_a_shared_attachment_is_set_on_every_set_row() {
		$rows = array(
			array(
				'code'       => 'glock',
				'term_id'    => 7,
				's3_key'     => self::KEY,
				'matched_by' => 'code',
				'action'     => 'set',
				'current_id' => 0,
			),
			array(
				'code'       => 'glock_inc',
				'term_id'    => 12,
				's3_key'     => self::KEY,
				'matched_by' => 'slug',
				'action'     => 'set',
				'current_id' => 0,
			),
			array(
				'code'       => 'glock_gmbh',
				'term_id'    => 13,
				's3_key'     => self::KEY,
				'matched_by' => 'code',
				'action'     => 'kept_manual',
				'current_id' => 300,
			),
		);

		$totals = ( new BrandLogoCreator( $this->fetcher( $this->ok() ) ) )->apply( $this->plan( 0, $rows ) );

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
