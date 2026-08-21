<?php
/**
 * CLI command to attach media to draft products based on SKU.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Services\SkuConverter;
use FAToolkit\Utilities\Helpers;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Attaches media to draft WooCommerce products based on SKU filename matching.
 */
class AttachMediaToDraftProductsCommand {

	/**
	 * Constructor - Register WP-CLI command.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fa:media attach-media-to-draft-products', array( $this, 'execute' ) );
		}
	}

	/**
	 * Attach media to draft products based on SKU.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview which attachments will be attached to which products, but do not make any changes.
	 *
	 * [--suffix=<suffix>]
	 * : Allows the addition of a suffix for matching.
	 *
	 * [--extension=<ext>]
	 * : Allows modification of the filename extension.
	 *
	 * [--sortorder=<order>]
	 * : Allows user to override default sort order.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:media attach-media-to-draft-products
	 *     wp fa:media attach-media-to-draft-products --suffix='_1'
	 *     wp fa:media attach-media-to-draft-products --extension=png
	 *     wp fa:media attach-media-to-draft-products --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function execute( $args, $assoc_args ) {
		$suffix    = $assoc_args['suffix'] ?? null;
		$extension = $assoc_args['extension'] ?? 'jpg';
		$sortorder = $assoc_args['sortorder'] ?? 'DESC';
		$dry_run   = isset( $assoc_args['dry-run'] );

		// Get a list of draft product IDs.
		\WP_CLI::debug( 'Loading Products..' );
		$draft_product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'draft',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => $sortorder,
			)
		);

		// Load all of our attachments into memory.
		\WP_CLI::debug( 'Loading Attachments..' );
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => -1,
				'orderby'        => 'post_title',
				'order'          => $sortorder,
			)
		);

		$matching_attachments = 0;
		$num_with_attachments = 0;
		$attachments_count    = count( $attachments );
		$products_count       = count( $draft_product_ids );

		// Loop through each draft product ID.
		foreach ( $draft_product_ids as $product_id ) {
			$sku               = get_post_meta( $product_id, '_sku', true );
			$filename_to_match = SkuConverter::to_filename( $sku, $extension, $suffix ?? '' );

			$attachment = $this->find_filename_in_attachment_array( $attachments, $filename_to_match, $product_id );

			if ( false === Helpers::is_empty( $attachment ) ) {
				$attachment  = $attachment->to_array();
				$is_attached = get_post_meta( $product_id, '_thumbnail_id', true );

				if ( ! empty( $is_attached ) && $is_attached === $attachment['ID'] ) {
					\WP_CLI::debug( sprintf( 'Attachment ID %d is already attached to product ID %d', $attachment['ID'], $product_id ) );
					++$matching_attachments;
				} elseif ( ! $dry_run ) {
					set_post_thumbnail( $product_id, $attachment['ID'] );
					\WP_CLI::success( sprintf( 'Product %d now parent of Attachment %d', $product_id, $attachment['ID'] ) );
					++$num_with_attachments;

					// Publish the product.
					$publish_response = wp_update_post(
						array(
							'ID'          => $product_id,
							'post_status' => 'publish',
						)
					);
					\WP_CLI::debug( sprintf( 'Product ID %s: %s', $product_id, $publish_response ) );
				} else {
					\WP_CLI::log( sprintf( 'Preview: Attachment %d: (%s) will be attached to Product %d: (%s)', $attachment['ID'], $attachment['post_title'], $product_id, $sku ) );
				}
			} else {
				\WP_CLI::debug( "No Matching Attachment for {$product_id}!" );
			}
		}

		\WP_CLI::log( "Draft Products: {$products_count}" );
		\WP_CLI::log( "Attachments: {$attachments_count}" );
		\WP_CLI::log( sprintf( 'Products with existing attachments: %d', $matching_attachments ) );
		\WP_CLI::log( sprintf( '%d products had attachments added', $num_with_attachments ) );
	}

	/**
	 * Find an attachment by filename in an array of attachments.
	 *
	 * @param array  $attachment_array Array of attachment objects to search.
	 * @param string $filename         The filename to match against post_title.
	 * @param int    $product_id       The product ID for debug logging.
	 * @return object|false The matching attachment object or false if not found.
	 */
	private function find_filename_in_attachment_array( $attachment_array, $filename, $product_id ) {
		foreach ( $attachment_array as $object ) {
			if ( $object->post_title === $filename ) {
				\WP_CLI::debug( sprintf( 'Matching sku>%s to post_title %s for product: %s attachment: %s', $filename, $object->post_title, $product_id, $object->ID ) );
				return $object;
			}
		}
		return false;
	}
}
