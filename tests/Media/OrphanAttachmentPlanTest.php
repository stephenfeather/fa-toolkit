<?php
/**
 * Tests for OrphanAttachmentPlan.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\OrphanAttachmentPlan;
use FAToolkit\Tests\TestCase;

/**
 * OrphanAttachmentPlan: decides, for one product, which of its pointer
 * attachments no longer match any entry in the product's current `_fa_media`
 * cell. Pure: it reads nothing and deletes nothing.
 */
class OrphanAttachmentPlanTest extends TestCase {

	/**
	 * A `_fa_media` cell carrying the given sha256 values as image entries.
	 *
	 * @param string ...$shas Image sha256 values.
	 * @return string
	 */
	private function cell( ...$shas ) {
		return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array_map(
				fn( $sha ) => array(
					'role'   => 'gallery',
					'kind'   => 'image',
					'url'    => 'https://ik.example.com/s3/files/' . $sha . '.jpg',
					'sha256' => $sha,
				),
				$shas
			)
		);
	}

	/**
	 * An attachment whose sha256 is absent from the cell is an orphan; one
	 * whose sha256 is present is current.
	 */
	public function test_an_attachment_whose_sha_left_the_cell_is_an_orphan() {
		$plan = OrphanAttachmentPlan::for_product(
			array(
				10 => 'aaa',
				11 => 'derivative',
			),
			array( $this->cell( 'aaa' ) ),
			array( 10 )
		);

		$this->assertSame( 'ok', $plan['status'] );
		$this->assertSame( 2, $plan['pointer'] );
		$this->assertSame( 1, $plan['current'] );
		$this->assertSame( array( 11 ), $plan['orphans'] );
		$this->assertSame( array(), $plan['wired_orphans'] );
	}

	/**
	 * A product whose every attachment is still in its cell has nothing to prune.
	 */
	public function test_a_product_whose_attachments_are_all_current_has_no_orphans() {
		$plan = OrphanAttachmentPlan::for_product(
			array(
				10 => 'aaa',
				11 => 'bbb',
			),
			array( $this->cell( 'aaa', 'bbb' ) ),
			array( 10, 11 )
		);

		$this->assertSame( 2, $plan['current'] );
		$this->assertSame( array(), $plan['orphans'] );
	}

	/**
	 * An orphan that is still the product's thumbnail is an anomaly: reported,
	 * never offered for deletion, because deleting it would strip the product
	 * of the image it renders.
	 */
	public function test_an_orphan_still_wired_as_thumbnail_is_an_anomaly_not_an_orphan() {
		$plan = OrphanAttachmentPlan::for_product(
			array(
				10 => 'aaa',
				11 => 'derivative',
			),
			array( $this->cell( 'aaa' ) ),
			array( 11, 10 )
		);

		$this->assertSame( array(), $plan['orphans'] );
		$this->assertSame( array( 11 ), $plan['wired_orphans'] );
	}

	/**
	 * A cell that is not valid JSON says nothing reliable about the product's
	 * images, so the product is skipped outright: no orphans, whatever its
	 * attachments carry.
	 */
	public function test_an_invalid_json_cell_skips_the_product() {
		$plan = OrphanAttachmentPlan::for_product(
			array( 11 => 'derivative' ),
			array( '[{"role":"hero","kind":"image","sha256":"aaa","alt":"3.4\"" ER"}]' ),
			array()
		);

		$this->assertSame( 'invalid_cell', $plan['status'] );
		$this->assertSame( array(), $plan['orphans'] );
		$this->assertSame( 1, $plan['pointer'] );
	}

	/**
	 * A product with no `_fa_media` cell, or an empty one, is skipped: an
	 * absent cell is "nothing to say", not "no images", so it can never turn
	 * every attachment into an orphan.
	 */
	public function test_a_missing_or_empty_cell_skips_the_product() {
		$missing = OrphanAttachmentPlan::for_product( array( 11 => 'aaa' ), array(), array() );
		$empty   = OrphanAttachmentPlan::for_product( array( 11 => 'aaa' ), array( '  ' ), array() );

		$this->assertSame( 'no_cell', $missing['status'] );
		$this->assertSame( array(), $missing['orphans'] );
		$this->assertSame( 'no_cell', $empty['status'] );
		$this->assertSame( array(), $empty['orphans'] );
	}

	/**
	 * Any entry carrying a sha256 keeps a matching attachment, whatever its
	 * kind or completeness. Keeping too much is recoverable; deleting too much
	 * is not.
	 */
	public function test_a_sha_on_any_entry_kind_keeps_the_attachment() {
		$cell = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				array(
					'role'   => 'manual_owner',
					'kind'   => 'document',
					'sha256' => 'doc',
				),
			)
		);

		$plan = OrphanAttachmentPlan::for_product( array( 12 => 'doc' ), array( $cell ), array() );

		$this->assertSame( array(), $plan['orphans'] );
		$this->assertSame( 1, $plan['current'] );
	}
}
