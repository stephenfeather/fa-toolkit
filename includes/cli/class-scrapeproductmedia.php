<?php
/**
 * Scrapes and imports an image for a given product ID.
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

namespace FAToolkit\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

use WP_CLI;

/**
 * Scrapes and imports media for a given product ID.
 * Currently only supports images.
 */
class ScrapeProductMedia {

	/**
	 * Distributors and their media types.
	 *
	 * @var array
	 */
	private $distributors = array(
		'CSSI'      => array(
			'base_url' => 'https://chattanoogashooting.com/catalog/product/',
			'images'   => array(
				'a',
				'class',
				'facebox',
				'href',
			),
		),
		'Davidsons' => array(
			'base_url' => 'https://www.davidsonsinc.com/catalogsearch/result/?q=',
			'images'   => array(
				'img',
				'class',
				'gallery-placeholder__image',
				'src',
			),
		),
	);

	/** Known placeholder hashes
	 *
	 * @var array
	 */
	private $known_placeholder_hashes = array(
		'75b8b48d7485cee17764f8b70b318136a4779bc38e8522279432cb327e0a448d',
		'9896278cac434b24892b14c3fb8fb93f5b675fd6fab45c12e73bb43058ff648e',
		'97e28fd1e99a48f854420bae4464fa74721e6143cb7a95a9ae7c31d68edb3de6',
	);

	/**
	 * Scrapes and imports media for a given product ID.
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : The ID of the product to scrape media for.
	 *
	 * [--media_type=<media_type>]
	 * : The type of media to scrape. Defaults to 'images'.
	 *
	 * [--override]
	 * : Whether to override existing media. Defaults to false.
	 *
	 * ## EXAMPLES
	 *
	 *    wp fa:media scrape-product-media 123 --media_type=images
	 *
	 * @param array $args       The positional arguments.
	 * @param array $assoc_args The associative arguments.
	 * @return void
	 * @since 1.0.4
	 * @access public
	 * @subcommand scrape-product-media
	 * @synopsis <product_id> [--media_type=<media_type>]
	 * @when after_wp_load
	 */
	public function wp_cli_scrape_product_media( $args, $assoc_args ) {
		$success = false;
		// Get the product_id.
		list( $product_id ) = $args;

		// Get the media_type.
		$media_type = isset( $assoc_args['media_type'] ) ? $assoc_args['media_type'] : 'images';

		// Get the override status.
		$override = isset( $assoc_args['override'] ) ? $assoc_args['override'] : false;

		// Get the dealer.
		$dealer_filter = isset( $assoc_args['dealer'] ) ? $assoc_args['dealer'] : '';

		// Get the product.
		$product = wc_get_product( $product_id );

		// Verify the product exists.
		if ( ! $product ) {
			WP_CLI::error( "Product ({$product_id}) does not exist." );
		}

		// Check if the product has a status of draft.
		if ( 'draft' !== $product->get_status() ) {
			WP_CLI::warning( "Product ({$product_id}) is published. ({$product->get_status()})" );
			if ( ! $override ) {
				exit;
			}
		}
		// Get the sku.
		$sku = $product->get_sku();

		// Check if product already has featured image.
		if ( has_post_thumbnail( $product_id ) ) {
			WP_CLI::warning( "Product ({$sku}) already has a featured image." );
			if ( ! $override ) {
				$product->set_status( 'publish' );
				$product->save();
				exit;
			}
		}

		// Check if product already has gallery media.
		if ( ! empty( $product->get_gallery_image_ids() ) ) {
			WP_CLI::warning( "Product ({$sku}) already has media gallery." );
			if ( ! $override ) {
				exit;
			}
		}

		// Get the distributor.
		$distributor = get_field( 'dealer', $product_id );
		WP_CLI::debug( 'Distributor: ' . $distributor );

		// Check if the dealer_filter is not empty and does not match the distributor.
		if ( ! empty( $dealer_filter ) && $dealer_filter !== $distributor ) {
			WP_CLI::warning( "Product ({$sku}) is not from the specified dealer ({$dealer_filter})." );
			exit;
		}

		// Get the distributor_settings.
		$distributor_settings = $this->distributors[ $distributor ];

		// Generate the distributor_product_url.
		$distributor_product_url = $this->generate_distributor_product_url( $sku, $distributor_settings );

		// Fetch the product_page.
		$product_page = $this->fetch_product_page( $distributor_product_url );

		switch ( $media_type ) {
			case 'images':
				// Scrape the page for images.
				list($media, $gallery) = $this->scrape_page( $product_page, $distributor_settings, $media_type );
				if ( ! empty( $media ) ) {
					// Import the media.
					$success = $this->import_and_attach_media( $product_id, $media, $gallery );
				}
				break;
		}

		if ( ! $success ) {
			WP_CLI::error( "Failed to import media for product ({$sku}): {$success}" );
		} else {
			// Publish the product.
			$product->set_status( 'publish' );
			$product->save();
			WP_CLI::success( "Successfully updated media ({$media_type}) for product ({$sku})" );
		}

	}

	/**
	 * Generates the distributor_product_url.
	 *
	 * @param string $sku         The product sku.
	 * @param string $distributor_settings The distributor settings.
	 * @return string
	 * @since 1.0.4
	 * @access private
	 */
	private function generate_distributor_product_url( $sku, $distributor_settings ) {
		$url = $distributor_settings['base_url'] . $sku;
		WP_CLI::debug( 'Distributor Product URL: ' . $url );
		return $url;
	}

	/**
	 * Fetches the product page.
	 *
	 * @param string $url The url to fetch.
	 * @return string
	 * @since 1.0.4
	 * @access private
	 */
	private function fetch_product_page( $url ) {
		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			// There was an error in the request.
			die( 'Error: ' . esc_html( $response->get_error_message() ) );
		} else {
			$body = wp_remote_retrieve_body( $response );
			WP_CLI::debug( 'Product Page: ' . $body );
			return $body;
		}

	}

	/**
	 * Scrapes the page for media.
	 *
	 * @param string $page        The page to scrape.
	 * @param string $distributor_settings The distributor.
	 * @param string $media_type  The media type to scrape.
	 * @return array
	 * @since 1.0.4
	 * @access private
	 */
	private function scrape_page( $page, $distributor_settings, $media_type ) {
		$dom = new \DOMDocument();
		@$dom->loadHTML( $page );
		$tags       = $dom->getElementsByTagName( $distributor_settings[ $media_type ][0] );
		$main_image = null;
		$gallery    = array();
		foreach ( $tags as $tag ) {
			$class_attr = $tag->getAttribute( $distributor_settings[ $media_type ][1] );
			if ( strpos( $class_attr, $distributor_settings[ $media_type ][2] ) !== false ) {
				$ref = $tag->getAttribute( $distributor_settings[ $media_type ][3] );
				$ref = $this->scrub( $ref );
				if ( null === $main_image ) {
					$main_image = $ref;
				} else {
					$gallery[] = $ref;
				}
			}
		}
		WP_CLI::debug( 'Main Image: ' . $main_image );
		WP_CLI::debug( 'Gallery: ' . implode( ', ', $gallery ) );
		return array( $main_image, $gallery );
	}

	/**
	 * Import media files from URLs and attach them to a product.
	 *
	 * @param int    $product_id  The product ID.
	 * @param string $featured_image_url       The media URL.
	 * @param array  $gallery_urls     The gallery URLs.
	 * @return int $success
	 * @since 1.0.4
	 * @access private
	 */
	private function import_and_attach_media( $product_id, $featured_image_url, $gallery_urls ) {
		// if we have a main image, import it and set it as the product featured image.
		if ( ! empty( $featured_image_url ) ) {
			$featured_image_id = $this->import_media( $featured_image_url, $product_id );
			WP_CLI::debug( 'Featured Image ID: ' . $featured_image_id );
			if ( ! is_wp_error( $featured_image_id ) ) {
				$success = set_post_thumbnail( $product_id, $featured_image_id );
			}
		} else {
			$success = false;
		}

		// if we have a gallery, import each item and set it as the product gallery.
		if ( ! empty( $gallery_urls ) && is_array( $gallery_urls ) ) {
			$gallery_ids = array();
			foreach ( $gallery_urls as $image ) {
				$gallery_ids[] = $this->import_media( $image, $product_id );
			}
			WP_CLI::debug( 'Gallery IDs: ' . implode( ', ', $gallery_ids ) );
			if ( ! is_wp_error( $gallery_ids ) ) {
				$success = update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
			}
		}
		return $success;
	}

	/**
	 * Imports a media file from a URL and attaches it to a product.
	 *
	 * @param string $url         The media URL.
	 * @param int    $product_id  The product ID.
	 * @return int $attachment_id
	 * @since 1.0.4
	 * @access private
	 */
	private function import_media( $url, $product_id ) {
		// Check the type of file. We'll use this as the 'post_mime_type'.
		$remote_basename = basename( $url );
		$filetype        = wp_check_filetype( $remote_basename, null );

		// Verify this attachment is not already in the media library.
		$existing_post = post_exists( $remote_basename );
		if ( $existing_post ) {
			WP_CLI::warning( "({$product_id}): {$remote_basename} already exists. Not redownloading." );
			return $existing_post;
		}
		// Get the file.
		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			// There was an error in the request.
			die( 'Error: ' . esc_html( $response->get_error_message() ) );
		}

		// Set variables for storage.
		$upload = wp_upload_bits( basename( $url ), null, wp_remote_retrieve_body( $response ) );

		if ( ! empty( $upload['error'] ) ) {
			// There was an error uploading the file.
			die( 'Error: ' . esc_html( $upload['error'] ) );
		}

		// Verify that the file hash is not a known placeholder.
		$hash = hash_file( 'sha256', $upload['file'] );
		if ( in_array( $hash, $this->known_placeholder_hashes, true ) ) {
			WP_CLI::warning( "({$product_id}): {$remote_basename} is a placeholder image. Not redownloading." );
			return 0;
		}
		// Construct the attachment array.
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $url ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		// Insert the attachment.
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $product_id );

		// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Generate the metadata for the attachment, and update the database record.
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );
		update_post_meta( $attachment_id, 'sha256_hash', $hash );

		return $attachment_id;

	}

	/**
	 * Scrubs a URL of inline image params.
	 *
	 * @param string $url The media URL.
	 * @return string $scrubbed_url
	 * @since 1.0.4
	 * @access private
	 */
	private function scrub( $url ) {
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
}

WP_CLI::add_command( 'fa:media scrape-product-media', array( new ScrapeProductMedia(), 'wp_cli_scrape_product_media' ) );
