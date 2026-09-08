<?php
/**
 * Turns a product's `_fa_media` cell into WooCommerce attachments.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Creates pointer attachments for one product.
 *
 * One attachment per (product, image) pair rather than one per distinct image.
 * That is deliberate and was settled on data, not preference: 1,112 images on
 * the legacy site carry DIFFERENT alt text on different products, because a
 * single vendor photo serves a whole family of externally identical products
 * (a reloading die in .270 WSM and the same die in .308). WooCommerce reads
 * alt from the attachment with no product in scope — StoreApi's
 * ImageAttachmentSchema:107 is a raw get_post_meta on the attachment id — so a
 * shared attachment could only ever carry alt that is correct for one of them.
 *
 * Keyed on (product id, sha256), which makes a re-run a no-op and therefore
 * makes the command safe to interrupt and resume across 32,332 products.
 *
 * Reachability and lookup are injected rather than reached for, so this class
 * stays testable without a network or a database.
 */
class RemoteAttachmentCreator {

	/**
	 * Finds an existing attachment for a (product, sha) pair.
	 *
	 * @var callable
	 */
	private $finder;

	/**
	 * Reports whether a URL currently resolves.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Whether to report without writing.
	 *
	 * @var bool
	 */
	private $dry_run = false;

	/**
	 * Constructor.
	 *
	 * @param callable $finder fn( int $product_id, string $sha ): int Attachment id, or 0.
	 * @param callable $probe  fn( string $url ): bool Whether the URL resolves.
	 */
	public function __construct( callable $finder, callable $probe ) {
		$this->finder = $finder;
		$this->probe  = $probe;
	}

	/**
	 * Report without writing.
	 *
	 * @param bool $dry_run Whether to suppress writes.
	 * @return void
	 */
	public function set_dry_run( $dry_run ) {
		$this->dry_run = (bool) $dry_run;
	}

	/**
	 * Create attachments for one product and wire them to WooCommerce.
	 *
	 * @param int    $product_id Product post id.
	 * @param string $raw_media  Raw `_fa_media` postmeta value.
	 * @return array{created:int,existing:int,unreachable:int,no_usable_image:bool}
	 */
	public function create_for_product( $product_id, $raw_media ) {
		$result = array(
			'created'         => 0,
			'existing'        => 0,
			'unreachable'     => 0,
			'no_usable_image' => false,
		);

		$media = RemoteMedia::from_meta( $raw_media );

		if ( false === $media->has_images() ) {
			return $result;
		}

		$attachment_ids = array();

		foreach ( $media->all() as $entry ) {
			// Reachability is checked before creating, never cached on failure.
			// A cached failure would survive the upstream URL repair and the
			// self-healing re-run would silently skip the very images it was
			// meant to fix.
			if ( true !== call_user_func( $this->probe, $entry['url'] ) ) {
				++$result['unreachable'];
				continue;
			}

			$existing = (int) call_user_func( $this->finder, $product_id, $entry['sha256'] );

			if ( $existing > 0 ) {
				++$result['existing'];
				$attachment_ids[] = $existing;
				continue;
			}

			++$result['created'];

			if ( true === $this->dry_run ) {
				continue;
			}

			$attachment_ids[] = $this->insert( $product_id, $entry );
		}

		if ( array() === $attachment_ids ) {
			// Every image was unreachable. Leave the product wired to nothing
			// so WooCommerce shows its placeholder, and report it: a broken
			// image looks deliberate, a placeholder does not.
			$result['no_usable_image'] = 0 < $result['unreachable'];
			return $result;
		}

		if ( true !== $this->dry_run ) {
			$this->wire_to_product( $product_id, $attachment_ids );
		}

		return $result;
	}

	/**
	 * Insert one pointer attachment.
	 *
	 * @param int   $product_id Product post id.
	 * @param array $entry      Media entry.
	 * @return int Attachment id.
	 */
	private function insert( $product_id, array $entry ) {
		$attachment_id = (int) wp_insert_attachment(
			array(
				'post_title'     => (string) ( $entry['title'] ?? '' ),
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			$product_id
		);

		update_post_meta( $attachment_id, '_fa_remote_url', $entry['url'] );
		update_post_meta( $attachment_id, '_fa_media_sha256', $entry['sha256'] );

		// Dimensions travel in the media cell because they live in the
		// migration database, which WordPress cannot see. Absent is handled
		// honestly downstream rather than guessed at.
		if ( isset( $entry['width'], $entry['height'] ) ) {
			update_post_meta( $attachment_id, '_fa_remote_width', (int) $entry['width'] );
			update_post_meta( $attachment_id, '_fa_remote_height', (int) $entry['height'] );
		}

		// Alt is written only when it is real. The titles are vendor filenames
		// like "BX30264.jpg", and a screen reader announces alt text verbatim,
		// so an invented one is worse than none.
		if ( '' !== (string) ( $entry['alt'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $entry['alt'] );
		}

		return $attachment_id;
	}

	/**
	 * Point WooCommerce at the attachments.
	 *
	 * Runs even when every attachment already existed, so a product whose
	 * thumbnail was lost is repaired by a re-run rather than skipped.
	 *
	 * @param int              $product_id     Product post id.
	 * @param array<int, int>  $attachment_ids Ordered attachment ids, hero first.
	 * @return void
	 */
	private function wire_to_product( $product_id, array $attachment_ids ) {
		$hero    = array_shift( $attachment_ids );
		$gallery = $attachment_ids;

		update_post_meta( $product_id, '_thumbnail_id', $hero );

		if ( array() !== $gallery ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
		}
	}
}
