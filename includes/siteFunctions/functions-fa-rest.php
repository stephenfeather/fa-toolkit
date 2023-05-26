<?php
/**
 * Add a rest endpoint to remotely add media from a url.
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'register_custom_media_endpoint' );

function register_custom_media_endpoint() {
	$success = register_rest_route(
		'custom/v1',
		'/import-media',
		array(
			'methods'             => 'POST',
			'callback'            => 'import_media_callback',
			'permission_callback' => 'import_media_permission_callback',
		)
	);

	error_log( 'registered route: ' . $success );
}



function import_media_permission_callback() {
	// Restrict endpoint to only users who have the edit_posts capability.
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'rest_forbidden', esc_html__( 'Your are not permitted to upload files.', 'my-text-domain' ), array( 'status' => 401 ) );
	}

	// This is a black-listing approach. You could alternatively do this via white-listing, by returning false here and changing the permissions check.
	return true;
}

function import_media_callback( $request ) {
	$url = $request->get_param( 'url' ); // Get the URL parameter from the request.
	error_log( 'url: ' . $url );
	if ( empty( $url ) ) {
		return array(
			'success' => false,
			'message' => 'URL parameter is required.',
		);
	}

	// Scrub the url.
	$url = scrub( $url );

	// Download the remote media file.
	$response = wp_remote_get( $url );

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		return array(
			'success' => false,
			'message' => 'Error downloading the media: ' . $error_message,
		);
	}

	// Import the media file into the media library.
	$remote_basename = basename( $url );
	error_log( 'remote_basename: ' . $remote_basename );
	$file_path = wp_upload_dir()['path'] . '/' . clean_filename( $remote_basename );
	$file_name = basename( $file_path );

	$info           = pathinfo( $file_path );
	$file_name_base = $info['filename'];
	$file_ext       = $info['extension'];

	error_log( 'file_path: ' . $file_path );
	error_log( 'file_name: ' . $file_name );
	error_log( 'file_ext: ' . $file_ext );
	$file = wp_upload_bits( $file_name, null, wp_remote_retrieve_body( $response ) );
	if ( ! $file['error'] ) {
		$attachment = array(
			'guid'           => $file['url'],
			'post_mime_type' => $file['type'],
			'post_title'     => $file_name_base,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file['file'] );
		if ( ! is_wp_error( $attachment_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			// Set optional fields if provided.
			$title       = $request->get_param( 'title' );
			$caption     = $request->get_param( 'caption' );
			$description = $request->get_param( 'description' );
			if ( ! empty( $title ) ) {
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => $title,
					)
				);
			}
			if ( ! empty( $caption ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );
			}
			if ( ! empty( $description ) ) {
				wp_update_post(
					array(
						'ID'           => $attachment_id,
						'post_content' => $description,
					)
				);
			}

			return array(
				'success'       => true,
				'message'       => 'Media imported successfully.',
				'attachment_id' => $attachment_id,
			);
		}
	}

	return array(
		'success' => false,
		'message' => 'Error importing the media: ' . $file['error'],
	);
}

function clean_filename( $filename ) {
	$new_name = $filename;
	// Remove double extensions (stupid davidsons).
	$new_name = str_replace( '.jpg.jpg', '.jpg', $new_name );
	return $new_name;
}

function scrub( $url ) {
	$scrubbed_url = $url;
	// Remove Query Strings.
	$parts        = parse_url( $scrubbed_url );
	$scrubbed_url = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

	// Remove inline image params from davidsons.
	$pattern = '/^(https:\/\/res\.cloudinary\.com\/davidsons-inc)(\/[^\/]+)(\/v1\/media\/.+)(\?.+)$/';
	if ( preg_match( $pattern, $scrubbed_url, $matches ) ) {
		$part1        = $matches[1];
		$part2        = $matches[2];
		$part3        = $matches[3];
		$part4        = $matches[4];
		$scrubbed_url = $part1 . $part3;
	}

	return $scrubbed_url;

}
