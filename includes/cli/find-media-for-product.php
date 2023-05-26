<?php
/**
 * Find attachments with names like product SKU.
 *
 * @package FA-Toolkit
 * @since 1.0.1
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
		$post_id = isset( $args[0] ) ? $args[0] : 0;
		$post    = get_post( $post_id );
		$sku     = get_post_meta( $post_id, '_sku', true );
		$matches = 0;
		$fails   = 0;

		// Load all of our attachments into memory.
		WP_CLI::debug( 'Loading Attachments..' );
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'image/jpeg',
				'posts_per_page' => -1,
				'orderby'        => 'post_title',
				'order'          => 'ASC',
			)
		);
		$basename    = sku_to_basename( $sku );

		$results_array = graded_array_search( $attachments, $basename );
		// Do we push the lowest distance into _thumbnail_id?
		// Do we push the rest into _product_image_gallery?
		// Do we default to interactive with a --no-interaction flag?
		// Should we accept an optional value with the full array of attachments from another process to make this faster in scripts?

		var_dump( $results_array );
		$basename = $basename . '.jpg';

		$attachments_count = count( $attachments );
		WP_CLI::line( sprintf( 'Finding media for post %d.', $post->ID ) );
		WP_CLI::line( sprintf( 'Post Title: %s', $post->post_title ) );
		WP_CLI::line( sprintf( 'SKU: %s', $sku ) );
		WP_CLI::line( sprintf( 'basename: %s', $basename ) );
		WP_CLI::line( "Total Attachments loaded: {$attachments_count}" );

	}
	WP_CLI::add_command( 'fa:media-dev find-media-for-product', 'wp_cli_find_media_for_product' );
}

if ( ! function_exists( 'graded_array_search' ) ) {
	/**
	 * Undocumented function
	 *
	 * @param  array  $attachment_array An array of all attachments.
	 * @param  string $basename The basename created from SKU.
	 */
	function graded_array_search( $attachment_array = array(), $basename = '' ) {
		$result = array();
		foreach ( $attachment_array as $object ) {
			if ( str_contains( $object->post_title, $basename ) ) {
				$distance_l= levenshtein( $object->post_title, $basename );
				$file     = wp_get_attachment_url( $object->ID );
				WP_CLI::line( "Distance {$distance_l} from {$object->post_title} to $basename" );
				WP_CLI::line( "Filename: $file." );
				array_push(
					$result,
					array(
						'id'       => $object->ID,
						'distance' => $distance_l,
						'title'    => $object->post_title,
						'file'     => $file,
					)
				);
				$matches++;
			} else {
				$fails++;
			}
		}

		WP_CLI::line( "Matches: {$matches}" );
		WP_CLI::line( "Failed: {$fails}" );
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
