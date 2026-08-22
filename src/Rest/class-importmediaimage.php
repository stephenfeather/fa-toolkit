<?php
/**
 * Add a rest endpoint to remotely add image media from a url.
 *
 * @package FA-Toolkit
 * @since 1.0.5
 */

namespace FAToolkit\Rest;

use FAToolkit\File\UrlHelper;
use FAToolkit\Utilities\Helpers;

if ( false === defined( 'ABSPATH' ) ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
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
		if ( false === current_user_can( 'upload_files' ) ) {
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
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'rest_invalid_url', esc_html__( 'The url provided is not valid.', 'my-text-domain' ), array( 'status' => 400 ) );
		}

		// Scrub the url.
		$url = esc_url_raw( $url );
		$url = UrlHelper::scrub( $url );

		// Begin splitting references to remote name and local name.
		$remote_basename     = basename( $url );
		$parameters['title'] = $remote_basename;

		// Check if the file already exists.
		$existing_attachment = UrlHelper::attachment_exists( $remote_basename );
		if ( true === is_wp_error( $existing_attachment ) ) {
			return $existing_attachment;
		}

		// Download the remote media file.
		$download = $this->download_media( $url );
		if ( true === is_wp_error( $download ) ) {
			return $download;
		}

		// Generate SHA256 hash of the file.
		$hash = hash_file( 'sha256', $download['file'] );

		// Import the media file into the media library.
		$attachment_id = $this->create_attachment( $download );
		if ( true === is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Save hash to attachment meta.
		if ( true !== empty( $hash ) ) {
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
		if ( true === is_wp_error( $response ) ) {
			return new \WP_Error( 'rest_download_failed', esc_html__( 'The download failed.', 'my-text-domain' ), array( 'status' => 400 ) );
		}
		$file_path      = wp_upload_dir()['path'] . '/' . UrlHelper::clean_filename( $remote_basename );
		$file_name      = basename( $file_path );
		$info           = pathinfo( $file_path );
		$file_name_base = $info['filename'];
		$file_ext       = $info['extension'];
		$file           = wp_upload_bits( $file_name, null, wp_remote_retrieve_body( $response ) );
		if ( true === $file['error'] ) {
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
		$product_id    = 0; // Attach to no product.
		$attachment_id = wp_insert_attachment( $attachment, $file['file'], $product_id );
		if ( true === is_wp_error( $attachment_id ) ) {
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

		if ( true !== Helpers::is_empty( $title ) ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $title,
				)
			);
		}

		if ( true !== Helpers::is_empty( $caption ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );

		}

		if ( true !== Helpers::is_empty( $description ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_content' => $description,
				)
			);
		}
	}
}
