<?php
/**
 * Keeps pointer attachment deletes from unlinking local uploads.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Stops deleting a pointer attachment from unlinking a local file (issue #106).
 *
 * A pointer (product image or brand logo) lives on S3. It still stores a bare
 * basename in `_wp_attached_file`, and wp_delete_attachment() builds uploads
 * paths from it and unlinks them, so a real upload sharing the name would go.
 *
 * The guard cannot sit at unlink time: core deletes every postmeta row before
 * wp_delete_attachment_files() runs (wp-includes/post.php), and `wp_delete_file`
 * receives only a path. `pre_delete_attachment` fires while the meta exists, so
 * the pointer is recognised there and loses the two keys core derives unlink
 * paths from: `_wp_attached_file` (the file, its sizes, thumb and originals)
 * and `_wp_attachment_backup_sizes`. Core then deletes the post and the rest of
 * its meta as usual. No state is carried between hooks.
 */
class RemoteAttachmentDeleteGuard {

	/**
	 * Meta core derives unlink paths from.
	 *
	 * @var array<int, string>
	 */
	private const PATH_META = array( '_wp_attached_file', '_wp_attachment_backup_sizes' );

	/**
	 * Constructor. Registers the filter.
	 *
	 * Last, so a filter that cancels the delete runs first and the pointer keeps
	 * its meta.
	 */
	public function __construct() {
		add_filter( 'pre_delete_attachment', array( $this, 'forget_local_paths' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Remove a pointer's local paths before core deletes it.
	 *
	 * @param mixed $delete       Null to proceed; anything else has cancelled the delete.
	 * @param mixed $post         Attachment post.
	 * @param bool  $force_delete Whether the Trash is bypassed.
	 * @return mixed $delete, or false when a path could not be removed.
	 */
	public function forget_local_paths( $delete, $post, $force_delete = false ) {
		unset( $force_delete );

		if ( null !== $delete || true !== is_object( $post ) || false === isset( $post->ID ) ) {
			return $delete;
		}

		$id = (int) $post->ID;

		if ( '' === (string) get_post_meta( $id, '_fa_remote_url', true ) ) {
			return $delete;
		}

		foreach ( self::PATH_META as $key ) {
			delete_post_meta( $id, $key );

			// A surviving path would be unlinked; refusing the delete is safer.
			if ( '' !== get_post_meta( $id, $key, true ) ) {
				return false;
			}
		}

		return $delete;
	}
}
