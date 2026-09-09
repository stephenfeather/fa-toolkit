<?php
/**
 * The widths a remote image is offered at.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Derives image width candidates from WordPress's registered sizes.
 *
 * These widths are used twice and must agree: RemoteAttachmentCreator writes
 * them into `_wp_attachment_metadata['sizes']` at creation, and
 * RemoteAttachmentUrls advertises them in srcset at render. They were
 * previously a hardcoded list in each class — two frozen copies that had to
 * match each other by hand, and that matched the site's actual configuration
 * only by coincidence. If they drifted, the stored metadata would describe one
 * set of sizes while srcset offered another.
 *
 * Reading the registered sizes makes the site's own configuration the single
 * source of truth, so a size registered by a theme is picked up without
 * touching this plugin, and changing `woocommerce_single_image_width` moves
 * the candidates with it.
 *
 * Deliberately does NOT invent a fallback when nothing is registered. A width
 * this class made up is a candidate the original may be too small to satisfy,
 * and the browser will pick an upscale if one is advertised. Callers handle an
 * empty list.
 */
class ImageSizeCandidates {

	/**
	 * Every distinct registered width, ascending.
	 *
	 * @return array<int, int>
	 */
	public static function widths() {
		$registered = wp_get_registered_image_subsizes();

		if ( true !== is_array( $registered ) ) {
			return array();
		}

		$widths = array();

		foreach ( $registered as $size ) {
			if ( true !== is_array( $size ) ) {
				continue;
			}

			$width = (int) ( $size['width'] ?? 0 );

			if ( $width < 1 ) {
				continue;
			}

			$widths[] = $width;
		}

		$widths = array_values( array_unique( $widths ) );
		sort( $widths );

		return $widths;
	}

	/**
	 * Registered widths no larger than the original.
	 *
	 * A candidate wider than the source is an upscale, and advertising one is
	 * worse than advertising nothing — the browser will choose it and get a
	 * blurred image for more bytes.
	 *
	 * @param int $original_width Intrinsic width of the source image.
	 * @return array<int, int>
	 */
	public static function up_to( $original_width ) {
		$original_width = (int) $original_width;

		return array_values(
			array_filter(
				self::widths(),
				function ( $width ) use ( $original_width ) {
					return $width <= $original_width;
				}
			)
		);
	}
}
