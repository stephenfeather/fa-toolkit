<?php
/**
 * Add a rest endpoint to remotely add image media from a url.
 *
 * @package FA-Toolkit
 * @since 1.0.5
 */

namespace FAToolkit\Rest;

use FAToolkit\File;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import Media Image.
 */
class ImportMediaImage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_route() {
		$success = register_rest_route(
			'fa-toolkit/v1',
			'/import-media-image/',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_media_image' ),
				'permission_callback' => array( $this, 'import_media_image_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to import media.
	 *
	 * @return bool|\WP_Error True if the user has permission.
	 */
	public function import_media_image_permission() {
		// Restrict endpoint to only users who have the edit_posts capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'rest_forbidden', esc_html__( 'Your are not permitted to upload files.', 'my-text-domain' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Import the media image.
	 *
	 * @param object $request The request object.
	 */
	public function import_media_image( $request ) {
		$parameters = $request->get_params();
		$url        = $parameters['url'];

		// Check that the $url is valid.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'rest_invalid_url', esc_html__( 'The url provided is not valid.', 'my-text-domain' ), array( 'status' => 400 ) );
		}

		// Scrub the url.
		$url = esc_url_raw( $url );
		$url = scrub( $url );

		// Begin splitting references to remote name and local name.
		$remote_basename = basename( $url );

		// Check if the file already exists.
		$existing_attachment = attachment_exists( $remote_basename );
		if ( is_wp_error( $existing_attachment ) ) {
			return $existing_attachment;
		}

		// Download the remote media file.
		$download = $this->download_media( $url );
		if ( is_wp_error( $download ) ) {
			return $download;
		}

		// Generate SHA256 hash of the file.
		$hash = hash_file( 'sha256', $download['file'] );

		// Import the media file into the media library.
		$attachment_id = $this->create_attachment( $download );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Save hash to attachment meta.
		if ( $hash ) {
			update_post_meta( $attachment_id, 'sha256_hash', $hash );
		}

		// Set optional meta if provided.
		$this->save_optional_meta( $attachment_id, $parameters, $download );
		return array(
			'success'       => true,
			'message'       => 'Media imported successfully.',
			'attachment_id' => $attachment_id,
		);

	}

	/**
	 * Download the media file.
	 *
	 * @param string $url The url of the media file.
	 * @return mixed $file The file or error.
	 */
	private function download_media( $url ) {
		$remote_basename = basename( $url );
		$response        = wp_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rest_download_failed', esc_html__( 'The download failed.', 'my-text-domain' ), array( 'status' => 400 ) );
		}
		$file_path = wp_upload_dir()['path'] . '/' . clean_filename( $remote_basename );
		$file_name = basename( $file_path );

		$info           = pathinfo( $file_path );
		$file_name_base = $info['filename'];
		$file_ext       = $info['extension'];

		$file = wp_upload_bits( $file_name, null, wp_remote_retrieve_body( $response ) );
		if ( $file['error'] ) {
			return new \WP_Error( 'rest_upload_failed', esc_html__( 'The upload failed.', 'my-text-domain' ), array( 'status' => 400 ) );
		}

		return $file;
	}

	/**
	 * Create a new attachment.
	 *
	 * @param array $file The file to create the attachment from.
	 * @return mixed $attachment_id The attachment id or error.
	 */
	private function create_attachment( $file ) {
		$attachment    = array(
			'guid'           => $file['url'],
			'post_mime_type' => $file['type'],
			'post_title'     => $file_name_base,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $file['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'rest_attachment_failed', esc_html__( 'The attachment failed.', 'my-text-domain' ), array( 'status' => 400 ) );
		}

		return $attachment_id;
	}

	/**
	 * Save Optional Meta.
	 *
	 * @param int   $attachment_id The attachment id.
	 * @param array $parameters    The parameters.
	 * @param array $file          The file.
	 */
	private function save_optional_meta( $attachment_id, $parameters, $file ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// IMPORTANT! These two lines seem to trigger media-cloud uplaod to s3.
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		// Set optional fields if provided.
		$title       = $parameters['title'];
		$caption     = $parameters['caption'];
		$description = $parameters['description'];

		if ( $title ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $title,
				)
			);
		}

		if ( $caption ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );

		}

		if ( $description ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_content' => $description,
				)
			);
		}
	}

}

new ImportMediaImage();


// TODO: Refactor into the a class in the FAToolkit\File Namespace.

/**
 * Scrub vendor junk from urls
 *
 * @param string $url The url.
 * @return string The scrubbed url.
 */
function scrub( $url ) {
	$scrubbed_url = $url;

	// Remove inline image params from davidsons.
	$pattern = '/^(https:\/\/res\.cloudinary\.com\/davidsons-inc)(\/[^\/]+)(\/v1\/media\/.+)(\?.+)$/';
	if ( preg_match( $pattern, $scrubbed_url, $matches ) ) {
		$part1        = $matches[1];
		$part2        = $matches[2];
		$part3        = $matches[3];
		$part4        = $matches[4];
		$scrubbed_url = $part1 . $part3;
	}

	// Remove Query Strings.
	$parts        = wp_parse_url( $scrubbed_url );
	$scrubbed_url = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

	return $scrubbed_url;

}

/**
 * Clean double extensions
 *
 * @param string $filename The filename.
 * @return string The cleaned filename.
 */
function clean_filename( $filename ) {
	$new_name = $filename;
	// Remove double extensions (stupid davidsons).
	$new_name = str_replace( '.jpg.jpg', '.jpg', $new_name );
	return $new_name;
}

/**
 * Get the extension from a URL.
 *
 * @param string $url The url.
 * @return string The extension.
 */
function get_url_ext( $url ) {
	$path_info = pathinfo( $url );
	return $path_info['extension'];
}

/**
 * Get the filename from a URL.
 *
 * @param string $url The url.
 * @return string The filename.
 */
function get_url_filename( $url ) {
	$path_info = pathinfo( $url );
	return $path_info['filename'];
}

/**
 * Check if an attachment exists.
 *
 * @param string $filename The filename.
 * @return bool True if the attachment exists.
 */
function attachment_exists( $filename ) {
	$post_id = post_exists( $filename );
	if ( $post_id ) {
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
