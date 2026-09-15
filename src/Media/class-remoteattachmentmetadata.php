<?php
/**
 * The attachment metadata a pointer attachment needs to count as an image.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Builds `_wp_attachment_metadata` and the mime type for a remote image.
 *
 * Shared by product pointer attachments (RemoteAttachmentCreator) and brand
 * logos (BrandLogoCreator), so the two cannot drift. See
 * RemoteAttachmentCreator::write_wordpress_metadata() for why this is stored
 * rather than filtered.
 */
final class RemoteAttachmentMetadata {

	/**
	 * Mime type for a remote image, from its extension.
	 *
	 * Hard-coding image/jpeg would mislabel every png and webp in the
	 * catalogue, and WordPress uses this to decide what an attachment is.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	public static function mime_type( $url ) {
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$known = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return $known[ $extension ] ?? 'image/jpeg';
	}

	/**
	 * The `_wp_attachment_metadata` value for a remote image.
	 *
	 * The stored `sizes` are nominal: RemoteAttachmentUrls replaces their URLs.
	 * They exist so core proceeds far enough to ask.
	 *
	 * @param string $url           Remote URL.
	 * @param int    $width         Intrinsic width.
	 * @param int    $height        Intrinsic height.
	 * @param string $attached_file Value stored in `_wp_attached_file`.
	 * @return array{width:int,height:int,file:string,sizes:array<string,array>}
	 */
	public static function build( $url, $width, $height, $attached_file ) {
		$width  = (int) $width;
		$height = (int) $height;
		$file   = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$mime   = self::mime_type( $url );

		$sizes = array();

		foreach ( ImageSizeCandidates::up_to( $width ) as $candidate ) {
			$sizes[ 'fa-' . $candidate ] = array(
				'file'      => $file,
				'width'     => $candidate,
				'height'    => (int) round( $height * ( $candidate / $width ) ),
				'mime-type' => $mime,
			);
		}

		if ( array() === $sizes ) {
			// Smaller than every candidate. One entry so core's guard clears.
			$sizes['fa-full'] = array(
				'file'      => $file,
				'width'     => $width,
				'height'    => $height,
				'mime-type' => $mime,
			);
		}

		return array(
			'width'  => $width,
			'height' => $height,
			'file'   => (string) $attached_file,
			'sizes'  => $sizes,
		);
	}
}
