<?php
/**
 * Downloads a brand logo once to learn its size and hash.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Fetches a logo and reads its width, height, mime and sha256.
 *
 * The map does not carry dimensions, and without them RemoteAttachmentUrls
 * serves the raw URL unsized with no srcset. The logos are small, so one GET
 * at creation is cheap; nothing is written to disk.
 *
 * Only a 404 or 410 is dead. A transport error, a 429 or a 5xx says nothing
 * about the image (issue #95), and neither answer is cached.
 */
class BrandLogoFetcher {

	/**
	 * Fetch and inspect one logo.
	 *
	 * @param string $url Logo URL.
	 * @return array{status:string,width?:int,height?:int,mime?:string,sha256?:string}
	 */
	public function fetch( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( true === is_wp_error( $response ) ) {
			return array( 'status' => 'failed' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( true === in_array( $code, array( 404, 410 ), true ) ) {
			return array( 'status' => 'dead' );
		}

		if ( 200 !== $code ) {
			return array( 'status' => 'failed' );
		}

		return self::inspect( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Read an image's size from its bytes.
	 *
	 * @param string $body Response body.
	 * @return array{status:string,width?:int,height?:int,mime?:string,sha256?:string}
	 */
	private static function inspect( $body ) {
		$size = '' === $body ? false : getimagesizefromstring( $body );

		if ( false === $size || (int) $size[0] < 1 || (int) $size[1] < 1 || true !== str_starts_with( (string) ( $size['mime'] ?? '' ), 'image/' ) ) {
			return array( 'status' => 'not_image' );
		}

		return array(
			'status' => 'ok',
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
			'mime'   => (string) $size['mime'],
			'sha256' => hash( 'sha256', $body ),
		);
	}
}
