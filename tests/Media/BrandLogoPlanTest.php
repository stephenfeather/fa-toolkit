<?php
/**
 * Tests for BrandLogoPlan.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\BrandLogoPlan;
use FAToolkit\Tests\TestCase;

/**
 * BrandLogoPlan: decides, per brand, which attachment to create or reuse and
 * whether to set the term's thumbnail_id. Pure: reads nothing, writes nothing.
 */
class BrandLogoPlanTest extends TestCase {

	private const KEY  = 'files/product_brands/Glock-Logo.jpg';
	private const URL  = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/Glock-Logo.jpg';
	private const KEY2 = 'files/product_brands/A-Zoom-Logo.jpg';
	private const URL2 = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/A-Zoom-Logo.jpg';

	/**
	 * A logo entry.
	 *
	 * @param string $key S3 key.
	 * @param string $url URL.
	 * @return array
	 */
	private function logo( $key = self::KEY, $url = self::URL ) {
		return array(
			'status' => 'logo',
			's3_key' => $key,
			'url'    => $url,
		);
	}

	/**
	 * A matched term.
	 *
	 * @param int    $term_id    Term id.
	 * @param string $name       Term name.
	 * @param string $matched_by How it was matched.
	 * @return array
	 */
	private function term( $term_id, $name, $matched_by = 'code' ) {
		return array(
			'term_id'    => $term_id,
			'name'       => $name,
			'matched_by' => $matched_by,
		);
	}

	/**
	 * An existing brand-logo attachment.
	 *
	 * @param int    $id     Attachment id.
	 * @param array  $extra  Overrides.
	 * @return array
	 */
	private function attachment( $id, array $extra = array() ) {
		return array_merge(
			array(
				'id'     => $id,
				'url'    => self::URL,
				'width'  => 400,
				'height' => 200,
				'alt'    => 'Glock',
			),
			$extra
		);
	}

	/**
	 * MISSING, conflict and near_identical entries are listed by status and
	 * never planned.
	 */
	public function test_non_logo_entries_are_skipped_by_status() {
		$plan = BrandLogoPlan::build(
			array(
				'10_ring'      => array( 'status' => 'MISSING' ),
				'heckler_koch' => array( 'status' => 'conflict' ),
				'burris'       => array( 'status' => 'near_identical' ),
				'cmmg'         => array( 'status' => 'near_identical' ),
			),
			array(),
			array(),
			array(),
			false
		);

		$this->assertSame(
			array(
				'MISSING'        => array( '10_ring' ),
				'conflict'       => array( 'heckler_koch' ),
				'near_identical' => array( 'burris', 'cmmg' ),
			),
			$plan['skipped']
		);
		$this->assertSame( array(), $plan['rows'] );
		$this->assertSame( array(), $plan['attachments'] );
	}

	/**
	 * A logo whose code has no product_brand term is listed and creates
	 * nothing: there would be nothing to attach it to.
	 */
	public function test_a_logo_without_a_term_is_no_term_and_creates_nothing() {
		$plan = BrandLogoPlan::build( array( 'glock' => $this->logo() ), array(), array(), array(), false );

		$this->assertSame( array( 'glock' ), $plan['no_term'] );
		$this->assertSame( array(), $plan['rows'] );
		$this->assertSame( array(), $plan['attachments'] );
	}

	/**
	 * Thumbnail states that are always overwritten.
	 *
	 * @return array<string, array{0:array}>
	 */
	public static function settable_states() {
		return array(
			'empty'   => array( array( 'id' => 0, 'state' => 'empty' ) ),
			'missing' => array( array( 'id' => 999, 'state' => 'missing' ) ),
		);
	}

	/**
	 * An empty thumbnail, or one pointing at a deleted attachment, is set to
	 * a new attachment whose alt is the term name.
	 *
	 * @param array $thumbnail Current thumbnail state.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'settable_states' )]
	public function test_an_empty_or_stale_thumbnail_is_set_to_a_new_attachment( array $thumbnail ) {
		$plan = BrandLogoPlan::build(
			array( 'glock' => $this->logo() ),
			array( 'glock' => $this->term( 7, 'Glock' ) ),
			array(),
			array( 7 => $thumbnail ),
			false
		);

		$this->assertSame(
			array(
				array(
					'code'       => 'glock',
					'term_id'    => 7,
					's3_key'     => self::KEY,
					'matched_by' => 'code',
					'action'     => 'set',
					'current_id' => $thumbnail['id'],
				),
			),
			$plan['rows']
		);
		$this->assertSame(
			array(
				self::KEY => array(
					's3_key'        => self::KEY,
					'url'           => self::URL,
					'attachment_id' => 0,
					'alt'           => 'Glock',
					'term_ids'      => array( 7 ),
					'unused_names'  => array(),
					'refresh'       => array(
						'url'        => false,
						'dimensions' => false,
						'alt'        => false,
					),
				),
			),
			$plan['attachments']
		);
	}

	/**
	 * A term already pointing at the attachment for its key is already set,
	 * and an up-to-date attachment needs no refresh: the rerun is a no-op.
	 */
	public function test_a_rerun_with_nothing_changed_is_already_set_with_no_refresh() {
		$plan = BrandLogoPlan::build(
			array( 'glock' => $this->logo() ),
			array( 'glock' => $this->term( 7, 'Glock' ) ),
			array( self::KEY => $this->attachment( 50 ) ),
			array( 7 => array( 'id' => 50, 'state' => 'ours' ) ),
			false
		);

		$this->assertSame( 'already_set', $plan['rows'][0]['action'] );
		$this->assertSame( 50, $plan['attachments'][ self::KEY ]['attachment_id'] );
		$this->assertSame(
			array(
				'url'        => false,
				'dimensions' => false,
				'alt'        => false,
			),
			$plan['attachments'][ self::KEY ]['refresh']
		);
	}

	/**
	 * A term pointing at a different brand-logo attachment is moved to the
	 * one its key maps to.
	 */
	public function test_a_thumbnail_on_another_brand_logo_is_moved() {
		$plan = BrandLogoPlan::build(
			array( 'glock' => $this->logo() ),
			array( 'glock' => $this->term( 7, 'Glock' ) ),
			array( self::KEY => $this->attachment( 50 ) ),
			array( 7 => array( 'id' => 51, 'state' => 'ours' ) ),
			false
		);

		$this->assertSame( 'set', $plan['rows'][0]['action'] );
	}

	/**
	 * A thumbnail uploaded by hand is kept, and no attachment is planned for
	 * a key with no other use.
	 */
	public function test_a_manual_thumbnail_is_kept_without_replace() {
		$plan = BrandLogoPlan::build(
			array( 'glock' => $this->logo() ),
			array( 'glock' => $this->term( 7, 'Glock' ) ),
			array(),
			array( 7 => array( 'id' => 300, 'state' => 'manual' ) ),
			false
		);

		$this->assertSame( 'kept_manual', $plan['rows'][0]['action'] );
		$this->assertSame( 300, $plan['rows'][0]['current_id'] );
		$this->assertSame( array(), $plan['attachments'] );
	}

	/**
	 * With --replace a manual thumbnail is overwritten.
	 */
	public function test_replace_overwrites_a_manual_thumbnail() {
		$plan = BrandLogoPlan::build(
			array( 'glock' => $this->logo() ),
			array( 'glock' => $this->term( 7, 'Glock' ) ),
			array(),
			array( 7 => array( 'id' => 300, 'state' => 'manual' ) ),
			true
		);

		$this->assertSame( 'set', $plan['rows'][0]['action'] );
		$this->assertArrayHasKey( self::KEY, $plan['attachments'] );
	}

	/**
	 * Two codes sharing one s3_key share one attachment. Its alt is the name
	 * of the lowest term_id, whatever the map order; the other names are
	 * reported.
	 */
	public function test_codes_sharing_a_key_share_one_attachment_with_the_lowest_term_name() {
		$plan = BrandLogoPlan::build(
			array(
				'glock_inc' => $this->logo(),
				'glock'     => $this->logo(),
			),
			array(
				'glock_inc' => $this->term( 12, 'Glock, Inc.' ),
				'glock'     => $this->term( 7, 'Glock' ),
			),
			array(),
			array(
				12 => array( 'id' => 0, 'state' => 'empty' ),
				7  => array( 'id' => 0, 'state' => 'empty' ),
			),
			false
		);

		$this->assertCount( 1, $plan['attachments'] );
		$this->assertSame( array( 7, 12 ), $plan['attachments'][ self::KEY ]['term_ids'] );
		$this->assertSame( 'Glock', $plan['attachments'][ self::KEY ]['alt'] );
		$this->assertSame( array( 'Glock, Inc.' ), $plan['attachments'][ self::KEY ]['unused_names'] );
		$this->assertSame( array( 'glock_inc', 'glock' ), array_column( $plan['rows'], 'code' ) );
	}

	/**
	 * An existing attachment is refreshed field by field: a changed url, a
	 * missing dimension, a changed term name.
	 */
	public function test_an_existing_attachment_is_refreshed_only_where_it_differs() {
		$plan = BrandLogoPlan::build(
			array(
				'glock'  => $this->logo( self::KEY, self::URL . '?v=2' ),
				'a_zoom' => $this->logo( self::KEY2, self::URL2 ),
			),
			array(
				'glock'  => $this->term( 7, 'Glock' ),
				'a_zoom' => $this->term( 8, 'A-Zoom', 'slug' ),
			),
			array(
				self::KEY  => $this->attachment( 50, array( 'height' => 0 ) ),
				self::KEY2 => $this->attachment( 60, array( 'url' => self::URL2, 'alt' => 'Old name' ) ),
			),
			array(
				7 => array( 'id' => 50, 'state' => 'ours' ),
				8 => array( 'id' => 60, 'state' => 'ours' ),
			),
			false
		);

		$this->assertSame(
			array(
				'url'        => true,
				'dimensions' => true,
				'alt'        => false,
			),
			$plan['attachments'][ self::KEY ]['refresh']
		);
		$this->assertSame(
			array(
				'url'        => false,
				'dimensions' => false,
				'alt'        => true,
			),
			$plan['attachments'][ self::KEY2 ]['refresh']
		);
		$this->assertSame( 'slug', $plan['rows'][1]['matched_by'] );
	}
}
