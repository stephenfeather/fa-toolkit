<?php
/**
 * Automatically attach uploaded media to WooCommerce products based on filename hash.
 *
 * Monitors Media Library uploads and automatically associates images with products
 * when the filename matches the pattern {hash}_{number}.jpg where {hash} matches
 * a product's _unique_product_key meta value.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // Exit if accessed directly.
}

/**
 * Auto-attach uploaded media to products by hash-based filename matching.
 */
class AutoAttachUploadedMedia {

	/**
	 * Constructor - Register hooks.
	 */
	public function __construct() {
		add_action( 'add_attachment', array( $this, 'process_uploaded_attachment' ), 10, 1 );
	}

	/**
	 * Process newly uploaded attachment.
	 *
	 * Checks if the filename matches the pattern {hash}_{number}.extension,
	 * finds a product with matching _unique_product_key, and attaches the
	 * image as a featured image (if number is 0 and product has no featured)
	 * or to the gallery.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return void
	 */
	public function process_uploaded_attachment( $attachment_id ) {
		// 1. Validate: Is this an image?
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
			return;
		}

		// 2. Get filename.
		$file_path = get_attached_file( $attachment_id );
		if ( true === is_empty( $file_path ) ) {
			return;
		}
		$filename = basename( $file_path );

		// 3. Parse filename for hash and number.
		$parsed = $this->parse_filename( $filename );
		if ( true === is_empty( $parsed ) ) {
			return; // Filename doesn't match pattern.
		}

		// 4. Find product by hash.
		$product_id = $this->find_product_by_hash( $parsed['hash'] );
		if ( true === is_empty( $product_id ) ) {
			return; // No matching product.
		}

		// 5. Verify product exists and is valid.
		$product = wc_get_product( $product_id );
		if ( ! $product || 'trash' === $product->get_status() ) {
			return;
		}

		// 6. Determine image type (featured vs gallery).
		$type = $this->determine_image_type( $product_id, $parsed['number'] );

		// 7. Attach image to product.
		$success = false;
		if ( 'featured' === $type ) {
			$success = $this->set_as_featured_image( $product_id, $attachment_id );
		} else {
			$success = $this->append_to_gallery( $product_id, $attachment_id );
		}

		// 8. Calculate and store SHA-256 hash (regardless of attachment success).
		$this->calculate_and_store_hash( $attachment_id );

		// 9. Log result.
		if ( true !== is_empty( $success ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[FA-Toolkit] Auto-attached image %d to product %d (SKU: %s) as %s',
					$attachment_id,
					$product_id,
					$product->get_sku(),
					$type
				)
			);
		}
	}

	/**
	 * Parse filename to extract hash and number.
	 *
	 * Expects filename format: {hash}_{number}.extension
	 * Example: abc123_0.jpg → ['hash' => 'abc123', 'number' => 0]
	 *
	 * @param string $filename The attachment filename.
	 * @return array|false Array with 'hash' and 'number' keys, or false if no match.
	 */
	private function parse_filename( $filename ) {
		// Remove extension to get basename.
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		// Match pattern: hash_number (hash can be alphanumeric, number is digits only).
		if ( preg_match( '/^([a-zA-Z0-9]+)_(\d+)$/', $basename, $matches ) ) {
			return array(
				'hash'   => $matches[1],
				'number' => (int) $matches[2],
			);
		}

		return false;
	}

	/**
	 * Find product by unique product key meta.
	 *
	 * @param string $hash The hash to search for.
	 * @return int|false Product ID or false if not found.
	 */
	private function find_product_by_hash( $hash ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array(
					'key'     => '_unique_product_key',
					'value'   => $hash,
					'compare' => '=',
				),
			),
		);

		$products = get_posts( $args );

		if ( true === empty( $products ) ) {
			return false;
		}

		return $products[0];
	}

	/**
	 * Determine if attachment should be featured or gallery image.
	 *
	 * If number is 0 AND product has no featured image, it becomes featured.
	 * Otherwise, it's added to gallery (APPEND mode).
	 *
	 * @param int $product_id The product ID.
	 * @param int $number     The number from filename (0 = potential featured).
	 * @return string Either 'featured' or 'gallery'.
	 */
	private function determine_image_type( $product_id, $number ) {
		// If number is 0 AND product has no featured image → featured.
		if ( 0 === $number && ! has_post_thumbnail( $product_id ) ) {
			return 'featured';
		}

		// Otherwise → gallery (APPEND mode).
		return 'gallery';
	}

	/**
	 * Set attachment as product's featured image.
	 *
	 * @param int $product_id    The product ID.
	 * @param int $attachment_id The attachment ID.
	 * @return bool Success status.
	 */
	private function set_as_featured_image( $product_id, $attachment_id ) {
		$result = set_post_thumbnail( $product_id, $attachment_id );
		return (bool) $result;
	}

	/**
	 * Append attachment to product's gallery.
	 *
	 * Gets existing gallery IDs and appends the new attachment,
	 * preventing duplicates and preserving existing images.
	 *
	 * @param int $product_id    The product ID.
	 * @param int $attachment_id The attachment ID.
	 * @return bool Success status.
	 */
	private function append_to_gallery( $product_id, $attachment_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		// Get existing gallery IDs.
		$gallery_ids = $product->get_gallery_image_ids();

		// Check if attachment is already in gallery (prevent duplicates).
		if ( in_array( $attachment_id, $gallery_ids, true ) ) {
			return true; // Already exists, consider success.
		}

		// Append new attachment ID.
		$gallery_ids[] = $attachment_id;

		// Update gallery meta (WooCommerce stores as comma-separated string).
		$success = update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );

		return (bool) $success;
	}

	/**
	 * Calculate and store SHA-256 hash for attachment.
	 *
	 * Consistent with existing FA-Toolkit patterns for image deduplication.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The hash or false on failure.
	 */
	private function calculate_and_store_hash( $attachment_id ) {
		// Get the file path.
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// Calculate SHA-256 hash.
		$hash = hash_file( 'sha256', $file_path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_hash_file

		if ( true === is_empty( $hash ) ) {
			return false;
		}

		// Store hash as meta.
		update_post_meta( $attachment_id, 'sha256_hash', $hash );

		return $hash;
	}
}

// Instantiate to register hooks.
new AutoAttachUploadedMedia();
