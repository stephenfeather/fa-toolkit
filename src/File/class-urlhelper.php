<?php
/**
 * URL manipulation utilities.
 *
 * @package FA-Toolkit
 * @since 1.2.0
 */

namespace FAToolkit\File;

/**
 * Class for URL and filename manipulation.
 */
class UrlHelper {

	/**
	 * Scrub vendor-specific junk from URLs.
	 *
	 * Removes inline image parameters from vendor URLs (e.g., Davidsons)
	 * and strips query strings.
	 *
	 * @param string $url The URL to scrub.
	 * @return string The scrubbed URL.
	 */
	public static function scrub( string $url ): string {
		$scrubbed_url = $url;

		// Remove inline image params from davidsons.
		$pattern = '/^(https:\/\/res\.cloudinary\.com\/davidsons-inc)(\/[^\/]+)(\/v1\/media\/.+)(\?.+)$/';
		if ( true === preg_match( $pattern, $scrubbed_url, $matches ) ) {
			$part1        = $matches[1];
			$part3        = $matches[3];
			$scrubbed_url = $part1 . $part3;
		}

		// Remove Query Strings.
		$parts        = wp_parse_url( $scrubbed_url );
		$scrubbed_url = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

		return $scrubbed_url;
	}

	/**
	 * Clean double extensions from a filename.
	 *
	 * Handles cases like 'image.jpg.jpg' from certain vendors.
	 *
	 * @param string $filename The filename to clean.
	 * @return string The cleaned filename.
	 */
	public static function clean_filename( string $filename ): string {
		// Remove double extensions (stupid davidsons).
		return str_replace( '.jpg.jpg', '.jpg', $filename );
	}

	/**
	 * Get the extension from a URL.
	 *
	 * @param string $url The URL to extract extension from.
	 * @return string The file extension.
	 */
	public static function get_extension( string $url ): string {
		$path_info = pathinfo( $url );
		return $path_info['extension'] ?? '';
	}

	/**
	 * Get the filename (without extension) from a URL.
	 *
	 * @param string $url The URL to extract filename from.
	 * @return string The filename without extension.
	 */
	public static function get_filename( string $url ): string {
		$path_info = pathinfo( $url );
		return $path_info['filename'] ?? '';
	}

	/**
	 * Check if an attachment with the given filename already exists.
	 *
	 * @param string $filename The filename to check.
	 * @return \WP_Error|false WP_Error if attachment exists, false otherwise.
	 */
	public static function attachment_exists( string $filename ) {
		$post_id = post_exists( $filename );
		if ( true !== empty( $post_id ) ) {
			return new \WP_Error(
				'rest_attachment_exists',
				esc_html__(
					'The attachment already exists.',
					'my-text-domain'
				),
				array(
					'status'        => 400,
					'attachment_id' => $post_id,
				)
			);
		}
		return false;
	}
}
