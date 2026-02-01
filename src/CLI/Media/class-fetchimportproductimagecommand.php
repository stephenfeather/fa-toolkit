<?php
/**
 * CLI command to download and import product images.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\File\UrlHelper;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Downloads and imports images for WooCommerce products.
 */
class FetchImportProductImageCommand {

	/**
	 * Known placeholder image hashes to reject.
	 *
	 * @var array
	 */
	private const PLACEHOLDER_HASHES = array(
		'75b8b48d7485cee17764f8b70b318136a4779bc38e8522279432cb327e0a448d',
		'9896278cac434b24892b14c3fb8fb93f5b675fd6fab45c12e73bb43058ff648e',
	);

	/**
	 * Constructor - Register WP-CLI command.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fa:media fetch-import-product-image', array( $this, 'execute' ) );
		}
	}

	/**
	 * Downloads and imports an image for a given product ID.
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
	 *     wp fa:media fetch-import-product-image 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function execute( $args, $assoc_args ) {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$suffixes   = array( '', '_1', '-1' );
		$product_id = $args[0] ?? null;
		$extension  = $assoc_args['extension'] ?? 'jpg';

		if ( null === $product_id ) {
			\WP_CLI::error( 'Missing Argument: <id>' );
		}

		// Check if product already has image.
		$media = get_attached_media( 'image', $product_id );
		if ( ! empty( $media ) ) {
			\WP_CLI::error( "($product_id) Post already has an image attached." );
		}

		// Get image source from ACF or construct from dealer.
		$image_source = get_field( 'image_source', $product_id );
		\WP_CLI::debug( "Image Source: {$image_source}" );

		if ( empty( $image_source ) ) {
			\WP_CLI::debug( "No image_source found for Product {$product_id}." );
			$image_source = $this->get_dealer_image_url( $product_id, $extension, $suffixes[0] );
			$this->handle_wp_error( $image_source, $product_id );
		}

		// Check if image already exists.
		$filename = pathinfo( $image_source, PATHINFO_FILENAME );
		if ( post_exists( $filename ) ) {
			\WP_CLI::error( "({$product_id}): {$filename} already exists. Not redownloading." );
		}

		// Download the image.
		$image_source    = str_replace( ' ', '%20', $image_source );
		$temp_image_path = $this->download_image( $image_source, $product_id );
		$this->handle_wp_error( $temp_image_path, $product_id );

		// Sanitize path.
		$sanitized_path = UrlHelper::clean_filename( $temp_image_path );
		$sanitized_path = str_replace( ' ', '_', $sanitized_path );
		$sanitized_path = str_replace( '%20', '_', $sanitized_path );

		$wp_filesystem->copy( $temp_image_path, $sanitized_path, true );

		// Calculate hash and check for placeholders.
		$hash = hash_file( 'sha256', $temp_image_path );
		\WP_CLI::debug( "SHA-256 Hash: {$hash}" );

		if ( in_array( $hash, self::PLACEHOLDER_HASHES, true ) ) {
			\WP_CLI::log( "$hash for $image_source" );
			\WP_CLI::error( "($product_id) Skipping import due to known placeholder hash." );
		}

		// Verify file size.
		$file_size = filesize( $sanitized_path );
		if ( 0 === $file_size ) {
			\WP_CLI::error( "({$product_id}) Downloaded file is empty." );
		}

		// Import into WordPress.
		$file_array = array(
			'name'        => basename( $sanitized_path ),
			'tmp_name'    => $sanitized_path,
			'description' => 'Product Image',
			'error'       => 0,
			'size'        => $file_size,
		);

		$attachment_id = media_handle_sideload( $file_array, $product_id );
		$this->handle_wp_error( $attachment_id, $product_id );

		// Save hash and attach to product.
		update_post_meta( $attachment_id, 'sha256_hash', $hash );
		set_post_thumbnail( $product_id, $attachment_id );

		// Publish the product.
		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_status' => 'publish',
			)
		);

		\WP_CLI::success( "({$product_id}) Image {$attachment_id} imported successfully and product published." );
	}

	/**
	 * Handle WP_Error by logging and halting.
	 *
	 * @param mixed $the_error Value to check for WP_Error.
	 * @param int   $post_id   Post ID for context.
	 */
	private function handle_wp_error( $the_error, $post_id = 0 ) {
		if ( is_wp_error( $the_error ) ) {
			\WP_CLI::error( "($post_id): " . $the_error->get_error_message() );
		}
	}

	/**
	 * Download image from URL.
	 *
	 * @param string $image_source Image URL.
	 * @param int    $product_id   Product ID for logging.
	 * @return string Path to downloaded file.
	 */
	private function download_image( $image_source, $product_id ) {
		global $wp_filesystem;

		$filename  = pathinfo( $image_source, PATHINFO_FILENAME );
		$extension = pathinfo( $image_source, PATHINFO_EXTENSION );

		if ( post_exists( $filename ) ) {
			\WP_CLI::error( "({$product_id}): {$filename} already exists. Not redownloading." );
		}

		// Download via cURL.
		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt( $ch, CURLOPT_URL, $image_source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		curl_setopt( $ch, CURLOPT_HEADER, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt

		$image_data    = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( $response_code >= 400 ) {
			\WP_CLI::error( "({$product_id}) HTTP Error: {$response_code}. {$image_source}" );
		}

		// Save to temp file.
		$temp_image_path  = '/tmp/faWeb-' . $this->generate_random_string( 5 );
		$final_image_path = pathinfo( $temp_image_path, PATHINFO_DIRNAME ) . '/' . $filename . '.' . $extension;

		if ( false === $wp_filesystem->put_contents( $temp_image_path, $image_data, FS_CHMOD_FILE ) ) {
			\WP_CLI::error( "({$product_id}) Failed to write to temp file." );
		}

		copy( $temp_image_path, $final_image_path );

		return $final_image_path;
	}

	/**
	 * Get dealer-specific image URL.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $extension  File extension.
	 * @param string $suffix     Optional suffix.
	 * @return string Image URL.
	 */
	private function get_dealer_image_url( $product_id, $extension, $suffix = '' ) {
		$dealer  = strtolower( get_field( 'dealer', $product_id ) );
		$product = wc_get_product( $product_id );
		$sku     = $product->get_sku();
		$url     = '';

		if ( 'davidsons' === $dealer ) {
			$sku = strtolower( $sku );
			$url = 'https://res.cloudinary.com/davidsons-inc/v1/media/catalog/product/'
				. substr( $sku, 0, 1 ) . '/' . substr( $sku, 1, 1 ) . '/' . $sku . '.' . $extension;
		} elseif ( 'cssi' === $dealer ) {
			$url = 'https://media.chattanoogashooting.com/images/product/'
				. $sku . '/' . $sku . $suffix . '.' . $extension;
		}

		\WP_CLI::debug( "URL: {$url}" );
		return $url;
	}

	/**
	 * Generate random string for temp filenames.
	 *
	 * @param int $length String length.
	 * @return string Random string.
	 */
	private function generate_random_string( $length ) {
		$characters    = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$random_string = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$index          = wp_rand( 0, strlen( $characters ) - 1 );
			$random_string .= $characters[ $index ];
		}

		return $random_string;
	}
}
