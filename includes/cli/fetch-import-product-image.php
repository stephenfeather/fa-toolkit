<?php
/**
 * Downloads and imports an image for a given product ID, and saves the SHA-1256 hash as metadata.
 *
 * @package FA-Toolkit
 * @since 1.0.1
 * 
 * TODO: Refactor this into a class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

if ( ! function_exists( 'wp_cli_fetch_import_product_image' ) ) {
	/**
	 * Downloads and imports an image for a given product ID, and saves the SHA-1 hash as metadata.
	 *
	 * ## OPTIONS
	 *
	 * <product-id>
	 * : The ID of the product to fetch and attach the image to.
	 *
	 * [--extension=<ext>]
	 * : The file extension to be searched for.
	 *
	 * ## EXAMPLES
	 *
	 * wp fa:media fetch-import-product-image 123
	 *   Downloads and imports the image for the product with ID 123.
	 */
	function wp_cli_fetch_import_product_image( $args, $assoc_args ) {
		global $wp_filesystem;
		// Make sure that the above variable is properly setup.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		// Suffixes.
		$suffixes = array( '', '_1', '-1' );
		// Grab our arguments.
		list( $product_id ) = $args;
		$extension          = isset( $assoc_args['extension'] ) ? $assoc_args['extension'] : 'jpg';

		// Verify we had an ID passed.
		if ( null === $product_id ) {
			WP_CLI::error( 'Missing Argument: <id>' );
		}
		// Check if the post already has an image attached.
		$media = get_attached_media( 'image', $product_id );
		if ( $media ) {
			WP_CLI::error( "($product_id) Post aleady has an image attached." );
		}

		// Get the image source ACF field value for the product.
		// $image_source = get_field( 'image_source', $product_id );
		WP_CLI::debug( "Image Source: {$image_source}" );

		// Verify that an $image_source exists.
		if ( ! $image_source ) {
			WP_CLI::debug( "No image_source found for Product {$product_id}." );
			$image_source = get_dealer_image_url( $product_id, $extension, $suffixes[0] );
			handle_wp_error( $image_source, $product_id );
		}

		// Verify we dont already have this image.
		if ( post_exists( $filename ) ) {
			WP_CLI::error( "({$product_id}): {$filename} already exists. Not redownloading." );
		}

		// Download the image from the URL.
		$image_source = str_replace( ' ', '%20', $image_source );
		WP_CLI::debug( "Cleaned image_source: {$image_source}" );
		$temp_image_path = download_image( $image_source, $product_id );
		WP_CLI::debug( ' Made it past download?' );
		// TODO: cloudinary can return a strange 404, that doesnt cause an error. this results in an empty download.

		handle_wp_error( $temp_image_path, $product_id );
		WP_CLI::debug( "Image Path: {$temp_image_path}" );

		// Remove spaces from filenames.
		$sanitized_path = str_replace( ' ', '_', $temp_image_path );
		$sanitized_path = str_replace( '%20', '_', $sanitized_path );

		// Remove double extensions (.jpg.jpg stupid davidsons).
		$sanitized_path = str_replace( '.jpg.jpg', '.jpg', $sanitized_path );
		$move_response  = $wp_filesystem->copy( $temp_image_path, $sanitized_path, true );
		handle_wp_error( $move_response, $product_id );
		WP_CLI::debug( "Sanitized Path: {$sanitized_path}" );

		// Calculate the SHA-1 hash of the image file.
		$hash = hash_file( 'sha256', $temp_image_path );
		WP_CLI::debug( "SHA-256 Hash: {$hash}" );

		// Reject known placeholder files.
		if ( '75b8b48d7485cee17764f8b70b318136a4779bc38e8522279432cb327e0a448d' || '9896278cac434b24892b14c3fb8fb93f5b675fd6fab45c12e73bb43058ff648e' === $hash ) {
			WP_CLI::log( "$hash for $image_source" );
			WP_CLI::error( "($product_id) Skipping import due to known placeholder hash." );
		}

		// Get the filesize and verify content.
		$file_size = filesize( $sanitized_path );
		if ( 0 === $file_size ) {
			WP_CLI::error( "({$product_id}) Downloaded file is empty." );
		} else {
			WP_CLI::debug( "filesize: {$file_size}" );
		}

		// Build a $__FILE array.
		$file_array = array(
			'name'        => basename( $sanitized_path ),
			'tmp_name'    => $sanitized_path,
			'description' => 'Product Image',
			'error'       => 0,
			'size'        => $file_size,
		);

		// Import the image into WordPress.
		$attachment_id = media_handle_sideload( $file_array, $product_id );
		handle_wp_error( $attachment_id, $product_id );
		WP_CLI::debug( "Attachment ID: {$attachment_id}" );

		// Save the SHA2-256 hash as metadata on the attachment post.
		$response1 = update_post_meta( $attachment_id, 'sha256_hash', $hash );
		handle_wp_error( $response1, $product_id );
		WP_CLI::debug( 'update_post_meta: {$response1}' );

		// Attach the image to the product.
		$response2 = set_post_thumbnail( $product_id, $attachment_id );
		handle_wp_error( $response2, $product_id );
		WP_CLI::debug( 'set_post_thumbnail: {$response2}' );

		// Publish the post.
		$publish_response = wp_update_post(
			array(
				'ID'           => $product_id,
				'post_status'  => 'publish',
				'image_source' => $image_source,
			)
		);
		handle_wp_error( $publish_response, $product_id );

		WP_CLI::success( "({$product_id}) Image {$attachment_id} imported successfully and product published." );
	}
	WP_CLI::add_command( 'fa:media fetch-import-product-image', 'wp_cli_fetch_import_product_image' );
}

if ( ! function_exists( 'handle_wp_error' ) ) {

	function handle_wp_error( $the_error, $post_id = 0 ) {
		if ( is_wp_error( $the_error ) ) {
			$error_string = $the_error->get_error_message();
			WP_CLI::error( "($post_id): {$error_string}" );
		}
	}
}

if ( ! function_exists( 'download_image' ) ) {

	function download_image( $image_source, $product_id ) {
		global $wp_filesystem;
		// Extract the filename and extension from the image URL.
		$filename  = pathinfo( $image_source, PATHINFO_FILENAME );
		$extension = pathinfo( $image_source, PATHINFO_EXTENSION );
		WP_CLI::debug( "filename: {$filename}" );
		if ( post_exists( $filename ) ) {
			WP_CLI::error( "({$product_id}): {$filename} already exists. Not redownloading." );
		}
		// Initialize cURL and set the necessary parameters.
		$ch = curl_init();
		WP_CLI::debug( 'curl initialized' );
		curl_setopt( $ch, CURLOPT_URL, $image_source );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, false );

		// Execute the cURL request and download the image.
		$image_data = curl_exec( $ch );
		WP_CLI::debug( 'curl_exec' );
		// Handle errors we are concerned about.
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		WP_CLI::debug( "response_code {$response_code} " );
		if ( $response_code >= 400 ) {
			WP_CLI::error( "({$product_id}) HTTP Error: {$response_code}. {$image_source}" );
		}

		$cl = strlen( $image_data );
		WP_CLI::debug( "content length: {$cl} " );

		// Close the cURL session.
		curl_close( $ch );

		// Create a temporary filename to save the image data.
		$temp_image_path = '/tmp/faWeb-' . getName( 5 );
		WP_CLI::debug( "temp image path: {$temp_image_path}" );

		// Set the final image path.
		$final_image_path = pathinfo( $temp_image_path, PATHINFO_DIRNAME ) . '/' . $filename . '.' . $extension;
		WP_CLI::debug( "final image path: {$final_image_path}" );

		// Write the image data to the temporary file.
		// fwrite( $temp_file, $image_data );.
		if ( $wp_filesystem->put_contents( $temp_image_path, $image_data, FS_CHMOD_FILE ) === false ) {
			// Failed to write to file, handle error here.
			WP_CLI::error( "({$product_id}) Failed to write to temp file." );
		} else {
			WP_CLI::debug( 'Apperently we succcessfully put a file.' );
		}

		// Rename the temporary file with the correct file extension based on the filename and extension from the image URL.
		// rename( $temp_image_path, $final_image_path );
		copy( $temp_image_path, $final_image_path );

		// Return the path of the downloaded image.
		return $final_image_path;
	}

	function get_dealer_image_url( $product_id, $extension, $suffix = '' ) {
		WP_CLI::debug( "product_id: {$product_id}" );
		WP_CLI::debug( "suffix: {$suffix}" );
		$dealer = strtolower( ( get_field( 'dealer', $product_id ) ) );
		WP_CLI::debug( "Dealer: {$dealer}" );
		$product = wc_get_product( $product_id );
		$sku     = $product->get_sku();
		WP_CLI::debug( "SKU: {$sku}" );
		$url = '';
		if ( 'davidsons' === $dealer ) {
			$sku = strtolower( $sku );
			// $url = new WP_Error();
			// $url->add( 'invalid', 'Dfavidson\'s is so fouled up.' );

			// Davidsons
			// $url = "https://res.cloudinary.com/davidsons-inc/c_lpad,dpr_2.0,h_1536,q_100,w_1536/v1/media/catalog/product/" . substr($sku, 3, 1) . "/" . substr($sku, 4, 1) . "/" . substr($sku, 3) . "." . $extension;.
			// https://res.cloudinary.com/davidsons-inc/v1/media/catalog/product/s/c/scterdm390dns.jpg.jpg
			$url = 'https://res.cloudinary.com/davidsons-inc/v1/media/catalog/product/' . substr( $sku, 0, 1 ) . '/' . substr( $sku, 1, 1 ) . '/' . $sku . '.' . $extension;
		} elseif ( 'cssi' === $dealer ) {
			// CSSI.
			// $url = 'https://media.chattanoogashooting.com/images/product/' . substr( $sku, 3 ) . '/' . substr( $sku, 3 ) . '.' . $extension;
			$url = 'https://media.chattanoogashooting.com/images/product/' . $sku . '/' . $sku . $suffix . '.' . $extension;

		}
		WP_CLI::debug( "URL: {$url}" );
		return $url;
	}

	function getName( $n ) {
		$characters    = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$random_string = '';

		for ( $i = 0; $i < $n; $i++ ) {
			$index          = wp_rand( 0, strlen( $characters ) - 1 );
			$random_string .= $characters[ $index ];
		}

		return $random_string;
	}
}
