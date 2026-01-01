<?php
/**
 * CLI command to hook into ilab-media-tools and fix the metadata media.
 *
 * @package FA-Toolkit
 * @since 1.0.7
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
};

if ( ( defined( 'WP_CLI' ) && WP_CLI ) === false ) {
	return;
}

use WP_CLI;
use WP_CLI_Command;
use Exception;
use MediaCloud\Plugin\Tools\Storage;

/**
 * Class to fix the metadata of media.
 */
class Media_Fix_Ilab_Metadata {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\WP_CLI::add_command( 'fa:media fix-media-metadata', array( $this, 'fix_media_metadata' ) );
		\WP_CLI::add_command( 'fa:media fix-all-media-metadata', array( $this, 'fix_all_media_metadata' ) );
	}

	/**
	 * Fix the metadata of media.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to fix the metadata for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa-toolkit:media fix-media-metadata 123
	 *
	 * @param array $args The arguments passed to the command.
	 * @param array $assoc_args The associative arguments passed to the command.
	 */
	public function fix_media_metadata( $args, $assoc_args ) {
		$post_id = absint( $args[0] );

		if ( ! $post_id ) {
			\WP_CLI::error( 'Please provide a valid post ID.' );
		}

		if ( ! post_exists( $post_id ) ) {
			\WP_CLI::error( 'Post ID ' . $post_id . " doesn't exist." );
		}

		$storage_utilities = new \MediaCloud\Plugin\Tools\Storage\StorageUtilities();
		try {
			$success = $storage_utilities->fixMetadata( $post_id );
		} catch ( Exception $e ) {
			\WP_CLI::error( 'Error processing attachment ' . $post_id . ': ' . $e->getMessage() );
		}

		if ( ! $success ) {
			\WP_CLI::error( 'Failed to fix metadata for post ID: ' . $post_id );
		}
		\WP_CLI::success( 'Metadata fixed for post ID: ' . $post_id );
	}

	/**
	 * Fix the metadata of media.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to fix the metadata for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa-toolkit:media fix-media-metadata 123
	 *
	 * @param array $args The arguments passed to the command.
	 * @param array $assoc_args The associative arguments passed to the command.
	 */
	public function fix_all_media_metadata( $args, $assoc_args ) {
		// get last processed post id.
		$last_processed_post_id = get_option( 'fa_toolkit_last_processed_post_id' );
		$starting_post_id       = absint( $args[0] ) ?? 0;
		$override               = $assoc_args['override'] ?? false;

		// if ( $override ) {
			$x = $starting_post_id;
		// } else {
		// $x = $last_processed_post_id;
		// }

		// Set the order (either 'ASC' for ascending or 'DESC' for descending).
		$order = 'ASC'; // Use 'DESC' for descending order.

		// Set the IDs range (first and last IDs of the range).
		$y = $starting_post_id + 1000;

		// Query arguments.
		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => -1, // Retrieve all attachments in the range.
			// 'post_status'    => 'inherit', // Include only attachments with the 'inherit' status.
			'orderby'        => 'ID', // Order by ID.
			'order'          => $order,
			'post__in'       => range( $x, $y ), // Specify the IDs range.
			'fields'         => 'ids', // Return only IDs.
		);

		// Query the attachments.
		$attachments = get_posts( $args );

		$storage_utilities = new \MediaCloud\Plugin\Tools\Storage\StorageUtilities();

		// Loop through the attachments.
		foreach ( $attachments as $attachment_id ) {
			update_option( 'fa_toolkit_last_processed_post_id', $attachment_id );
							$success = $storage_utilities->fixMetadata( $attachment_id );
			// } catch ( Exception $e ) {
				// \WP_CLI::warning( 'Error processing attachment ' . $attachment_id . ': ' . $e->getMessage() );
				// continue; // Continue to the next iteration of the loop.
			// }
			if ( ! $success ) {
				\WP_CLI::warning( 'Failed to fix metadata for post ID: ' . $attachment_id );
			} else {
				\WP_CLI::success( 'Metadata fixed for post ID: ' . $attachment_id );
			}
		}

		delete_option( $option_name );

	}
}

new Media_Fix_Ilab_Metadata();
