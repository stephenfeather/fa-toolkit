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
 * The guard cannot sit at unlink time. Line numbers are wp-includes/post.php
 * on wordpress-develop trunk, 2026-09-15:
 *
 * - wp_delete_attachment() applies `pre_delete_attachment` (6947) while the
 *   meta exists, deletes every postmeta row (6989-6992), fires `deleted_post`
 *   (7001) and only then calls wp_delete_attachment_files() (7003).
 * - `wp_delete_file` receives only a path (functions.php 7917), so nothing
 *   identifies a pointer by the time a file is unlinked.
 * - wp_delete_attachment_files() builds the main file, sizes, thumb, original
 *   and companion paths from `$file` = get_attached_file() (6957; 7029-7126,
 *   7144), and backup paths from `_wp_attachment_backup_sizes` (6956; 7128-7141).
 *
 * So the pointer is recognised at `pre_delete_attachment` and loses those two
 * keys; with an empty `$file` every derived path is empty. Core then deletes
 * the post and the rest of its meta as usual. No state is carried between hooks.
 *
 * If the delete does not complete after the strip (a filter registered later
 * at the same priority cancels it), the attachment survives without
 * `_wp_attached_file`. `_fa_remote_url`, the dimensions and
 * `_wp_attachment_metadata` remain, so it still renders through
 * RemoteAttachmentUrls, but wp_attachment_is() (7444-7448) no longer counts it
 * as an image. It is recoverable, not self-healing: the stripped value is
 * wp_basename() of the `_fa_remote_url` path.
 *
 * - Product pointer: the next `create-remote-attachments` run rewrites it
 *   (RemoteAttachmentCreator reuse path, when the entry carries dimensions).
 * - Brand logo: `brand-logos` does not rewrite it (it refreshes dimensions only
 *   when they change); it must be rewritten by hand.
 *
 * Pointers have no edited backups, so no backup sizes are lost.
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
