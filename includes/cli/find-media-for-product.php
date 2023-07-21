<?php
/**
 * Find attachments with names like product SKU.
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

if ( ! function_exists( 'wp_cli_find_media_for_product' ) ) {
	/**
	 * Find attachments with names like product SKU
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * :The ID of the post to match.
	 *
	 * @param  [type] $args  Arguments passed to the WP-CLI command.
	 * @param  [type] $assoc_args Associative arguments passed to the WP-CLI command.
	 */
	function wp_cli_find_media_for_product( $args, $assoc_args ) {
		$post_id    = isset( $args[0] ) ? $args[0] : 0;
		$post       = get_post( $post_id );
		$product    = wc_get_product( $post_id );
		$product_id = $product->get_id();
		$sku        = $product->get_sku();
		$matches    = 0;
		$fails      = 0;

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
		$attachments = get_cached_posts( $attachment_query_args, 10 * MINUTE_IN_SECONDS );

		$basename = sku_to_basename( $sku );

		$results_array = graded_array_search( $attachments, $basename );

		// var_dump( $results_array );

		// $basename = $basename . '.jpg';

		$attachments_count = count( $attachments );
		WP_CLI::log( sprintf( 'Finding media for product %d.', $product_id ) );
		WP_CLI::log( sprintf( 'Product Title: %s', $product->get_name() ) );
		WP_CLI::log( sprintf( '  Product SKU: %s', $sku ) );
		// WP_CLI::log( sprintf( '     basename: %s', $basename ) );

		// Do we push the lowest distance into _thumbnail_id?
		// Do we push the rest into _product_image_gallery?
		// Do we default to non-interactive with an --interactive flag?
		// Should we accept an optional value with the full array of attachments from another process to make this faster in scripts?
		$gallery_images = array();
		foreach ( $results_array as $result ) {

			if ( 0 === $result['distance'] ) {
				// Make attachment featured.
				WP_CLI::log( sprintf( 'Featured Image id: %d.', $result['id'] ) );
				set_product_image( $product, $result['id'] );
			} else {
				// How do we add/determine for image gallery?
				// This is where jaro-wrinkler weighting would be better?
				$response = ask( 'Do you want to add ' . $result['title'] . ' to the gallery? (y/n)' );
				if ( 'y' === $response ) {
					// Push the id into the array.
					array_push( $gallery_images, $result['id'] );
				}
				WP_CLI::log( sprintf( 'Possible gallery item: %s.', $result['title'] ) );
			}
		}

		$product->set_gallery_image_ids( $gallery_images );
		$product->save();

	}
	WP_CLI::add_command( 'fa:media-dev find-media-for-product', 'wp_cli_find_media_for_product' );
}

function set_product_image( $product, $attachment_id ) {

	// $is_attached = get_post_meta( $product_id, '_thumbnail_id', true );
	$image_id = $product->get_image_id();
	// Verify we dont already have a thumbnail.
	if ( $product->get_image_id() == $attachment_id ) {
		WP_CLI::log( sprintf( 'Attachment ID %d is already attached to product ID %d', $product->get_id(), $attachment_id ) );
		return true;
	} else {
		// $success = set_post_thumbnail( $product_id, $attachment_id );
		WP_CLI::log( 'Setting product image' );
		$product->set_image_id( $attachment_id );
		$product->save();
		return $success;
	}

}

if ( ! function_exists( 'graded_array_search' ) ) {
	/**
	 * Undocumented function
	 *
	 * @param  array  $attachment_array An array of all attachments.
	 * @param  string $basename The basename created from SKU.
	 */
	function graded_array_search( $attachment_array = array(), $basename = '' ) {

		$basename = strtolower( $basename );
		$result = array();
		foreach ( $attachment_array as $object ) {

			// Does the title contain the adjusted basename?
			// Does the title start with the adjusted basename?

// this works for davidsons.
// need to test with CSSI.
			if ( 0 === strpos( $object->post_title, $basename ) ) {

				$cleaned_title = str_replace( '.jpg.jpg', '', $object->post_title );
				$cleaned_title = str_replace( '.jpg', '', $cleaned_title );

				$cleaned_basename = str_replace( '.jpg.jpg', '', $basename );
				$cleaned_basename = str_replace( '.jpg', '', $cleaned_basename );

				$distance = levenshtein( $cleaned_title, $cleaned_basename );
				$file     = wp_get_attachment_url( $object->ID );

				array_push(
					$result,
					array(
						'id'       => $object->ID,
						'distance' => $distance,
						'title'    => $object->post_title,
						'file'     => $file,
					)
				);
				$matches++;
			} else {
				$fails++;
			}
		}

		usort( $result, fn ( $a, $b) => $a['distance'] <=> $b['distance'] );
		unset( $object );
		return $result;
	}
}

if ( ! function_exists( 'sku_to_basename' ) ) {
	/**
	 * Takes a SKU and reduces it to a vendor filename.
	 *
	 * @param  string $sku // product sku.
	 * @param  string $basename_suffix // optional suffix.
	 */
	function sku_to_basename( $sku, $basename_suffix = '' ) {
		$prefix = substr( $sku, 0, 3 );
		if ( 'FA-' === $prefix ) {
			$numeric_part = substr( $sku, 3 );
			return $numeric_part;
		} else {
			return $sku;
		}
	}
}

/**
 * Gets cached posts for a query. Results are stored against a hash of the
 * parameter array. If there's nothing in the cache, a fresh query is made.
 *
 * Source: https://wordpress.stackexchange.com/a/306970/215668
 *
 * @param Array $query_args The parameters to pass to get_posts().
 * @param Int   $expires The time a transient should live.
 * @return Array List of posts matching $args.
 */
function get_cached_posts( $query_args, $expires = HOUR_IN_SECONDS ) {
	$post_list_name = 'get_posts_' . md5( json_encode( $query_args ) );

	if ( false === ( $post_list = get_transient( $post_list_name ) ) ) {
		WP_CLI::log( 'Cached Missed!' );
		$post_list = get_posts( $query_args );

		set_transient( $post_list_name, $post_list, $expires );
	}

	return $post_list;
}

function ask( $question ) {
	// Adding space to question and showing it.
	fwrite( STDOUT, $question . ' ' );

	return strtolower( trim( fgets( STDIN ) ) );
}
