<?php
/**
 * Clears brand thumbnails when their logo attachment is deleted.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Keeps product_brand thumbnail_id from outliving its attachment (issue #105).
 *
 * WooCommerce Brands never clears term meta when an attachment is deleted,
 * and a stale id renders a broken image. `delete_attachment` fires while the
 * attachment's meta still exists (wp-includes/post.php, wp_delete_attachment),
 * so a brand logo can be told apart there and its terms cleared.
 *
 * No file is guarded: the logo lives on S3 and WordPress cannot delete it.
 */
class BrandLogoDeleteGuard {

	/**
	 * The store.
	 *
	 * @var BrandLogoStore
	 */
	private $store;

	/**
	 * Constructor. Registers the hook.
	 *
	 * @param BrandLogoStore|null $store Store; built when omitted.
	 */
	public function __construct( ?BrandLogoStore $store = null ) {
		$this->store = $store ?? new BrandLogoStore();

		add_action( 'delete_attachment', array( $this, 'forget_thumbnails' ), 10, 1 );
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
}
