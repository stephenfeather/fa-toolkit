<?php
/**
 * CLI command to find media attachments matching product SKU.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Services\SkuConverter;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Finds and attaches media to products based on SKU matching.
 */
class FindMediaForProductCommand {

	/**
	 * Constructor - Register WP-CLI command.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fa:media-dev find-media-for-product', array( $this, 'execute' ) );
		}
	}

	/**
	 * Find attachments with names like product SKU.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the product to match.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:media-dev find-media-for-product 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function execute( $args, $assoc_args ) {
		$post_id = $args[0] ?? 0;

		if ( empty( $post_id ) ) {
			\WP_CLI::error( 'Missing Argument: <id>' );
		}

		$product    = wc_get_product( $post_id );
		$product_id = $product->get_id();
		$sku        = $product->get_sku();

		// Our attachment query.
		$attachment_query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => 'image/jpeg',
			'posts_per_page' => -1,
			'orderby'        => 'post_title',
			'order'          => 'ASC',
		);

		// Load our attachments into memory.
		$attachments = $this->get_cached_posts( $attachment_query_args, 10 * MINUTE_IN_SECONDS );

		$basename      = SkuConverter::to_basename( $sku );
		$results_array = $this->graded_array_search( $attachments, $basename );

		\WP_CLI::log( sprintf( 'Finding media for product %d.', $product_id ) );
		\WP_CLI::log( sprintf( 'Product Title: %s', $product->get_name() ) );
		\WP_CLI::log( sprintf( '  Product SKU: %s', $sku ) );

		$gallery_images = array();
		foreach ( $results_array as $result ) {
			if ( 0 === $result['distance'] ) {
				// Make attachment featured.
				\WP_CLI::log( sprintf( 'Featured Image id: %d.', $result['id'] ) );
				$this->set_product_image( $product, $result['id'] );
			} else {
				// Interactive gallery selection.
				$response = $this->ask( 'Do you want to add ' . $result['title'] . ' to the gallery? (y/n)' );
				if ( 'y' === $response ) {
					array_push( $gallery_images, $result['id'] );
				}
				\WP_CLI::log( sprintf( 'Possible gallery item: %s.', $result['title'] ) );
			}
		}

		$product->set_gallery_image_ids( $gallery_images );
		$product->save();
	}

	/**
	 * Sets the featured image for a WooCommerce product.
	 *
	 * @param \WC_Product $product       The product to update.
	 * @param int         $attachment_id The attachment ID to set as the featured image.
	 * @return bool True when the attachment is set or already attached.
	 */
	private function set_product_image( $product, $attachment_id ) {
		if ( $product->get_image_id() === $attachment_id ) {
			\WP_CLI::log( sprintf( 'Attachment ID %d is already attached to product ID %d', $attachment_id, $product->get_id() ) );
			return true;
		}

		\WP_CLI::log( 'Setting product image' );
		$product->set_image_id( $attachment_id );
		$product->save();
		return true;
	}

	/**
	 * Search attachments and grade matches by Levenshtein distance.
	 *
	 * @param array  $attachment_array Array of attachment post objects.
	 * @param string $basename         The basename to match against.
	 * @return array Sorted array of matches with distance scores.
	 */
	private function graded_array_search( $attachment_array, $basename ) {
		$basename = strtolower( $basename );
		$result   = array();

		foreach ( $attachment_array as $object ) {
			// Check if title starts with the basename.
			if ( 0 === strpos( $object->post_title, $basename ) ) {
				$cleaned_title    = str_replace( array( '.jpg.jpg', '.jpg' ), '', $object->post_title );
				$cleaned_basename = str_replace( array( '.jpg.jpg', '.jpg' ), '', $basename );

				$distance = levenshtein( $cleaned_title, $cleaned_basename );
				$file     = wp_get_attachment_url( $object->ID );

				$result[] = array(
					'id'       => $object->ID,
					'distance' => $distance,
					'title'    => $object->post_title,
					'file'     => $file,
				);
			}
		}

		usort( $result, fn ( $a, $b ) => $a['distance'] <=> $b['distance'] );
		return $result;
	}

	/**
	 * Gets cached posts for a query using transients.
	 *
	 * @param array $query_args The parameters to pass to get_posts().
	 * @param int   $expires    The time a transient should live.
	 * @return array List of posts matching $query_args.
	 */
	private function get_cached_posts( $query_args, $expires = HOUR_IN_SECONDS ) {
		$post_list_name = 'get_posts_' . md5( wp_json_encode( $query_args ) );
		$post_list      = get_transient( $post_list_name );

		if ( false === $post_list ) {
			\WP_CLI::log( 'Cache Missed!' );
			$post_list = get_posts( $query_args );
			set_transient( $post_list_name, $post_list, $expires );
		}

		return $post_list;
	}

	/**
	 * Prompts the user with a question and returns the normalized answer.
	 *
	 * @param string $question The question text to display in the terminal.
	 * @return string The trimmed, lowercase user response.
	 */
	private function ask( $question ) {
		fwrite( STDOUT, $question . ' ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		return strtolower( trim( fgets( STDIN ) ) );
	}
}
