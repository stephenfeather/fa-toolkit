<?php
/**
 * Makes deleting a brand-logo attachment safe.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Two risks from issue #105, for brand-logo attachments.
 *
 * 1. Unlinking a real file. wp_delete_attachment() derives uploads paths from
 *    `_wp_attached_file` and the metadata sizes and unlinks them. Brand logos
 *    name paths under uploads/fa-remote/, which no real upload uses, and this
 *    class refuses to delete anything there.
 *
 *    The filter is permanent and stateless, deliberately. Core unlinks files
 *    in wp_delete_attachment_files() AFTER firing `deleted_post`
 *    (wp-includes/post.php), so a filter added on `delete_attachment` and
 *    removed on `deleted_post` would already be gone when core unlinks.
 *
 * 2. A stale thumbnail_id. WooCommerce Brands never clears term meta when the
 *    attachment goes, and a stale id renders a broken image. `delete_attachment`
 *    fires while the attachment's meta still exists, so it can tell a brand
 *    logo apart and clear the terms pointing at it.
 *
 * Product pointer attachments are out of scope here (#106).
 */
class BrandLogoDeleteGuard {

	/**
	 * Uploads subdirectory reserved for pointer attachments' nominal paths.
	 *
	 * @var string
	 */
	public const REMOTE_ROOT = 'fa-remote';

	/**
	 * The store.
	 *
	 * @var BrandLogoStore
	 */
	private $store;

	/**
	 * Constructor. Registers the hooks.
	 *
	 * @param BrandLogoStore|null $store Store; built when omitted.
	 */
	public function __construct( ?BrandLogoStore $store = null ) {
		$this->store = $store ?? new BrandLogoStore();

		add_action( 'delete_attachment', array( $this, 'forget_thumbnails' ), 10, 1 );
		add_filter( 'wp_delete_file', array( $this, 'keep_remote_file' ), 10, 1 );
	}

	/**
	 * Clear brand thumbnails pointing at a brand logo being deleted.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return void
	 */
	public function forget_thumbnails( $attachment_id ) {
		if ( '' === (string) get_post_meta( (int) $attachment_id, BrandLogoCreator::KEY_META, true ) ) {
			return;
		}

		$this->store->clear_thumbnails_pointing_at( (int) $attachment_id );
	}

	/**
	 * Refuse to delete a file under uploads/fa-remote/.
	 *
	 * @param string $file Path core is about to delete.
	 * @return string The path, or '' to skip the delete.
	 */
	public function keep_remote_file( $file ) {
		$uploads = wp_upload_dir( null, false );
		$basedir = rtrim( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ), '/' );

		if ( '' === $basedir ) {
			return $file;
		}

		return str_starts_with( wp_normalize_path( (string) $file ), $basedir . '/' . self::REMOTE_ROOT . '/' ) ? '' : $file;
	}
}
