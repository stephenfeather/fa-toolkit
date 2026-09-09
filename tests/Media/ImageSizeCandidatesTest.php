<?php
/**
 * Tests for ImageSizeCandidates.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\ImageSizeCandidates;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test case for ImageSizeCandidates.
 *
 * The widths a remote image is offered at were previously hardcoded in two
 * places — a const in RemoteAttachmentUrls and a bare array in
 * RemoteAttachmentCreator — which had to agree with each other and with
 * nothing else. The creator writes them into `_wp_attachment_metadata`, and
 * the srcset filter advertises them; if the two lists drifted, the metadata
 * would describe one set of sizes while srcset offered another.
 *
 * They now come from WordPress's registered sizes, so the site's own
 * configuration is the single source of truth and a custom registered size is
 * picked up without touching this plugin.
 */
class ImageSizeCandidatesTest extends TestCase {

	/**
	 * Stub the registered subsizes.
	 *
	 * @param array $sizes Registered sizes.
	 * @return void
	 */
	private function stub_sizes( array $sizes ) {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn( $sizes );
	}

	/**
	 * A typical WooCommerce site's registered sizes.
	 *
	 * @return array
	 */
	private function typical() {
		return array(
			'thumbnail'                    => array( 'width' => 150, 'height' => 150, 'crop' => true ),
			'medium'                       => array( 'width' => 300, 'height' => 300, 'crop' => false ),
			'medium_large'                 => array( 'width' => 768, 'height' => 0, 'crop' => false ),
			'large'                        => array( 'width' => 1024, 'height' => 1024, 'crop' => false ),
			'woocommerce_thumbnail'        => array( 'width' => 300, 'height' => 300, 'crop' => true ),
			'woocommerce_single'           => array( 'width' => 600, 'height' => 0, 'crop' => false ),
			'woocommerce_gallery_thumbnail' => array( 'width' => 100, 'height' => 100, 'crop' => true ),
		);
	}

	/**
	 * Test that candidates come from the registered sizes.
	 *
	 * @return void
	 */
	public function test_widths_come_from_registered_sizes() {
		$this->stub_sizes( $this->typical() );

		$this->assertSame( array( 100, 150, 300, 600, 768, 1024 ), ImageSizeCandidates::widths() );
	}

	/**
	 * Test that duplicate widths collapse.
	 *
	 * `medium` and `woocommerce_thumbnail` are both 300 on a stock install;
	 * offering 300w twice in a srcset is invalid.
	 *
	 * @return void
	 */
	public function test_duplicate_widths_are_collapsed() {
		$this->stub_sizes( $this->typical() );

		$widths = ImageSizeCandidates::widths();

		$this->assertSame( array_values( array_unique( $widths ) ), $widths );
	}

	/**
	 * Test that widths are ascending.
	 *
	 * @return void
	 */
	public function test_widths_are_ascending() {
		$this->stub_sizes( $this->typical() );

		$widths = $sorted = ImageSizeCandidates::widths();
		sort( $sorted );

		$this->assertSame( $sorted, $widths );
	}

	/**
	 * Test that a custom registered size is picked up.
	 *
	 * The point of deriving rather than hardcoding: a size registered by the
	 * theme appears without anyone editing this plugin.
	 *
	 * @return void
	 */
	public function test_a_custom_registered_size_is_included() {
		$sizes = $this->typical();
		$sizes['fa-hero'] = array( 'width' => 1440, 'height' => 0, 'crop' => false );
		$this->stub_sizes( $sizes );

		$this->assertContains( 1440, ImageSizeCandidates::widths() );
	}

	/**
	 * Test that zero-width and malformed entries are discarded.
	 *
	 * @return void
	 */
	public function test_zero_and_malformed_sizes_are_discarded() {
		$this->stub_sizes(
			array(
				'good'      => array( 'width' => 400, 'height' => 400, 'crop' => false ),
				'zero'      => array( 'width' => 0, 'height' => 400, 'crop' => false ),
				'no-width'  => array( 'height' => 400, 'crop' => false ),
				'not-array' => 'nonsense',
			)
		);

		$this->assertSame( array( 400 ), ImageSizeCandidates::widths() );
	}

	/**
	 * Test that a site with no registered sizes yields nothing.
	 *
	 * Callers must handle an empty list rather than this class inventing one.
	 * An invented width is a candidate the original may not be big enough to
	 * satisfy, which is an upscale the browser will happily pick.
	 *
	 * @return void
	 */
	public function test_no_registered_sizes_yields_an_empty_list() {
		$this->stub_sizes( array() );

		$this->assertSame( array(), ImageSizeCandidates::widths() );
	}

	/**
	 * Test that candidates are capped at the original width.
	 *
	 * @return void
	 */
	public function test_candidates_are_capped_at_the_original_width() {
		$this->stub_sizes( $this->typical() );

		$this->assertSame( array( 100, 150, 300 ), ImageSizeCandidates::up_to( 320 ) );
	}

	/**
	 * Test that the original width itself is a valid candidate.
	 *
	 * @return void
	 */
	public function test_a_candidate_equal_to_the_original_is_kept() {
		$this->stub_sizes( $this->typical() );

		$this->assertContains( 600, ImageSizeCandidates::up_to( 600 ) );
	}

	/**
	 * Test that an image smaller than every candidate yields none.
	 *
	 * @return void
	 */
	public function test_an_image_smaller_than_every_candidate_yields_none() {
		$this->stub_sizes( $this->typical() );

		$this->assertSame( array(), ImageSizeCandidates::up_to( 80 ) );
	}
}
