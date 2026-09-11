<?php
/**
 * Turns a product's `_fa_media` cell into WooCommerce attachments.
 *
 * @package    fa-toolkit
 * @since 1.2.0
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
	 * Probe answer: the URL serves the image.
	 *
	 * @var string
	 */
	public const PROBE_OK = 'ok';

	/**
	 * Probe answer: the image is definitely gone (404 or 410).
	 *
	 * @var string
	 */
	public const PROBE_DEAD = 'dead';

	/**
	 * Probe answer: no definite answer (timeout, 429, 5xx). Says nothing
	 * about the image, so it must never clear wiring (issue #95).
	 *
	 * @var string
	 */
	public const PROBE_FAILED = 'failed';

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
	 * Meta writes that did not store, for the product being processed.
	 *
	 * @var int
	 */
	private $write_failures = 0;

	/**
	 * Constructor.
	 *
	 * @param callable $finder fn( int $product_id, string $sha ): int Attachment id, or 0.
	 * @param callable $probe  fn( string $url ): string|bool One of the PROBE_* answers; true and false mean ok and dead.
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
	 * @return array{created:int,existing:int,unreachable:int,probe_failed:int,failed:int,write_failed:int,no_usable_image:bool}
	 */
	public function create_for_product( $product_id, $raw_media ) {
		$this->write_failures = 0;

		$result = array(
			'created'         => 0,
			'existing'        => 0,
			'unreachable'     => 0,
			'probe_failed'    => 0,
			'failed'          => 0,
			'write_failed'    => 0,
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
			$answer = $this->probe_answer( $entry['url'] );

			if ( self::PROBE_DEAD === $answer ) {
				++$result['unreachable'];
				continue;
			}

			// No definite answer: the image may be fine. Count it apart from
			// dead URLs so nothing below clears or rewires on its strength.
			if ( self::PROBE_OK !== $answer ) {
				++$result['probe_failed'];
				continue;
			}

			$existing = (int) call_user_func( $this->finder, $product_id, $entry['sha256'] );

			if ( $existing > 0 ) {
				++$result['existing'];
				$attachment_ids[] = $existing;

				// Refresh rather than skip. insert() is the only other writer of
				// these fields, so an attachment created before the entry carried
				// dimensions or alt would otherwise stay on the degraded path for
				// ever — the enrichment would land in _fa_media and never reach
				// WordPress. A re-run is how those attachments improve.
				if ( true !== $this->dry_run ) {
					$this->write_entry_meta( $existing, $entry );
				}

				continue;
			}

			if ( true === $this->dry_run ) {
				// A placeholder id, so the bookkeeping below sees that this
				// product WOULD have images. Without it a dry run reports
				// no_usable_image on a product whose images are perfectly fine.
				++$result['created'];
				$attachment_ids[] = 0;
				continue;
			}

			$inserted = $this->insert( $product_id, $entry );

			// Counted only once it exists. Incrementing before the insert
			// reports attachments that were never created, which is the one
			// number an operator uses to decide whether a run worked.
			if ( $inserted > 0 ) {
				++$result['created'];
				$attachment_ids[] = $inserted;
				continue;
			}

			++$result['failed'];
		}

		if ( array() === $attachment_ids ) {
			// The product HAS images — has_images() returned true above — and not
			// one of them produced an attachment. Report it, so an operator sees
			// a product that ended up with nothing. A product held back only by
			// failed probes is not "no usable image": the images may be fine and
			// the next run retries them.
			$result['no_usable_image'] = 0 === $result['probe_failed'];

			// But only CLEAR existing wiring when the cause is a dead URL.
			//
			// The two causes are not alike. An unreachable URL is a durable fact
			// about the image: it will still be dead next run, and leaving
			// _thumbnail_id pointing at it renders a broken image that looks
			// deliberate. A failed insert is a transient fact about this run —
			// a database hiccup, a full disk — and the images are fine. Deleting
			// a product's wiring because our own write failed would turn a
			// retryable error into data loss, and the next successful run would
			// have to rebuild what we destroyed.
			// A failed probe is the same kind of fact as a failed insert: about
			// this run, not the image (issue #95).
			$clear = true !== $this->dry_run && 0 < $result['unreachable'] && 0 === $result['failed'] && 0 === $result['probe_failed'];

			if ( true === $clear ) {
				$this->delete_meta( $product_id, '_thumbnail_id' );
				$this->delete_meta( $product_id, '_product_image_gallery' );
			}

			$result['write_failed'] = $this->write_failures;

			return $result;
		}

		// With a probe unanswered the attachment list is incomplete, and wiring
		// it would promote a gallery image over a hero that may be fine. Leave
		// the product as it is; the next run, with a definite answer, wires it.
		if ( true !== $this->dry_run && 0 === $result['probe_failed'] ) {
			$this->wire_to_product( $product_id, $attachment_ids );
		}

		$result['write_failed'] = $this->write_failures;

		return $result;
	}

	/**
	 * Insert one pointer attachment.
	 *
	 * @param int   $product_id Product post id.
	 * @param array $entry      Media entry.
	 * @return int Attachment id, or 0 on failure.
	 */
	private function insert( $product_id, array $entry ) {
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => (string) ( $entry['title'] ?? '' ),
				'post_mime_type' => $this->mime_type( $entry['url'] ),
				'post_status'    => 'inherit',
			),
			false,
			$product_id
		);

		// wp_insert_attachment() returns 0 or a WP_Error on failure. Without
		// this guard the meta writes below would land on post 0 and the caller
		// would wire the product to an attachment that does not exist.
		if ( true === is_wp_error( $attachment_id ) || (int) $attachment_id < 1 ) {
			return 0;
		}

		$attachment_id = (int) $attachment_id;

		$this->write_meta( $attachment_id, '_fa_remote_url', $entry['url'] );
		$this->write_meta( $attachment_id, '_fa_media_sha256', $entry['sha256'] );

		$this->write_entry_meta( $attachment_id, $entry );

		return $attachment_id;
	}

	/**
	 * Write the optional, refreshable fields of an entry.
	 *
	 * Shared by insert() and by the reuse path, so that an attachment created
	 * before the entry carried dimensions or alt is enriched by a later re-run
	 * rather than staying degraded for ever.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $entry         Media entry.
	 * @return void
	 */
	private function write_entry_meta( $attachment_id, array $entry ) {
		// The URL can change while the bytes do not — the same image lives at
		// two s3 keys for 2,306 of these — so it is refreshed, not assumed.
		$this->write_meta( $attachment_id, '_fa_remote_url', $entry['url'] );

		// Dimensions travel in the media cell because they live in the
		// migration database, which WordPress cannot see. Absent is handled
		// honestly downstream rather than guessed at.
		if ( isset( $entry['width'], $entry['height'] ) ) {
			$this->write_meta( $attachment_id, '_fa_remote_width', (int) $entry['width'] );
			$this->write_meta( $attachment_id, '_fa_remote_height', (int) $entry['height'] );

			$this->write_wordpress_metadata( $attachment_id, $entry );
		}

		// Alt is written only when it is real. The titles are vendor filenames
		// like "BX30264.jpg", and a screen reader announces alt text verbatim,
		// so an invented one is worse than none. Never cleared when absent:
		// alt edited by hand in WordPress must survive a re-run.
		if ( '' !== (string) ( $entry['alt'] ?? '' ) ) {
			$this->write_meta( $attachment_id, '_wp_attachment_image_alt', $entry['alt'] );
		}
	}

	/**
	 * Write the metadata rows WordPress requires to treat this as an image.
	 *
	 * Stored, not filtered — and that distinction was learned the hard way.
	 *
	 * `wp_get_attachment_metadata()` reads `_wp_attachment_metadata` and
	 * returns false BEFORE applying its own `wp_get_attachment_metadata`
	 * filter when the row is missing or not an array. A filter therefore
	 * cannot supply metadata for an attachment that has none, which is
	 * precisely the case here. Everything downstream depends on it:
	 * `wp_calculate_image_srcset()` guards on `sizes` and `file`, and
	 * `wp_calculate_image_sizes()` resolves a named size through it — so
	 * without a real row, srcset and sizes are silently absent on rendered
	 * pages while every unit test that calls the callbacks directly passes.
	 *
	 * `_wp_attached_file` is written for the same class of reason:
	 * `wp_attachment_is()` calls `get_attached_file()` and returns false
	 * immediately when it is empty, so without it `wp_attachment_is_image()`
	 * is false for every one of these and anything gating on it skips them.
	 *
	 * The stored `sizes` are nominal. Core would otherwise build size URLs by
	 * swapping the basename within a directory, which is wrong for a transform
	 * carried in a query string — the RemoteAttachmentUrls filters replace the
	 * URLs. These entries exist so core proceeds far enough to ask.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $entry         Media entry, known to carry width and height.
	 * @return void
	 */
	private function write_wordpress_metadata( $attachment_id, array $entry ) {
		$width  = (int) $entry['width'];
		$height = (int) $entry['height'];
		$file   = wp_basename( (string) wp_parse_url( $entry['url'], PHP_URL_PATH ) );
		$mime   = $this->mime_type( $entry['url'] );

		$sizes = array();

		foreach ( ImageSizeCandidates::up_to( $width ) as $candidate ) {
			$sizes[ 'fa-' . $candidate ] = array(
				'file'      => $file,
				'width'     => $candidate,
				'height'    => (int) round( $height * ( $candidate / $width ) ),
				'mime-type' => $mime,
			);
		}

		if ( array() === $sizes ) {
			// Smaller than every candidate. One entry so core's guard clears.
			$sizes['fa-full'] = array(
				'file'      => $file,
				'width'     => $width,
				'height'    => $height,
				'mime-type' => $mime,
			);
		}

		$this->write_meta( $attachment_id, '_wp_attached_file', $file );
		$this->write_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'width'  => $width,
				'height' => $height,
				'file'   => $file,
				'sizes'  => $sizes,
			)
		);
	}

	/**
	 * Mime type for a remote image, from its extension.
	 *
	 * Hard-coding image/jpeg would mislabel every png and webp in the
	 * catalogue, and WordPress uses this to decide what an attachment is.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private function mime_type( $url ) {
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$known = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return $known[ $extension ] ?? 'image/jpeg';
	}

	/**
	 * Point WooCommerce at the attachments.
	 *
	 * Runs even when every attachment already existed, so a product whose
	 * thumbnail was lost is repaired by a re-run rather than skipped.
	 *
	 * @param int             $product_id     Product post id.
	 * @param array<int, int> $attachment_ids Ordered attachment ids, hero first.
	 * @return void
	 */
	private function wire_to_product( $product_id, array $attachment_ids ) {
		$hero    = array_shift( $attachment_ids );
		$gallery = $attachment_ids;

		$this->write_meta( $product_id, '_thumbnail_id', $hero );

		if ( array() !== $gallery ) {
			$this->write_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
			return;
		}

		// Gallery images removed upstream, or died, must stop being referenced.
		$this->delete_meta( $product_id, '_product_image_gallery' );
	}

	/**
	 * Write one meta value, counting it when it did not store.
	 *
	 * WordPress's update_post_meta() returns false both on failure and when nothing
	 * changed: the stored value already equals the new one, or MySQL reports
	 * zero rows affected for a value that compares equal once stored. So false
	 * alone is not a failure. Only a stored value that still differs is.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value to store.
	 * @return void
	 */
	private function write_meta( $post_id, $key, $value ) {
		if ( false !== update_post_meta( $post_id, $key, $value ) || true === $this->stored_equals( $post_id, $key, $value ) ) {
			return;
		}

		++$this->write_failures;
	}

	/**
	 * Delete one meta key, counting it when the key survives.
	 *
	 * WordPress's delete_post_meta() returns false when there was nothing to delete, which
	 * is the outcome wanted, not a failure.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return void
	 */
	private function delete_meta( $post_id, $key ) {
		if ( false !== delete_post_meta( $post_id, $key ) || true !== metadata_exists( 'post', $post_id, $key ) ) {
			return;
		}

		++$this->write_failures;
	}

	/**
	 * Whether the stored value already equals the one being written.
	 *
	 * Scalars are compared as strings, because postmeta stores 1200 as "1200".
	 * Arrays round-trip through serialisation with their types intact, so they
	 * are compared strictly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value being written.
	 * @return bool
	 */
	private function stored_equals( $post_id, $key, $value ) {
		$stored = get_post_meta( $post_id, $key, true );

		if ( true === is_array( $value ) ) {
			return $stored === $value;
		}

		return true !== is_array( $stored ) && (string) $stored === (string) $value;
	}

	/**
	 * The probe's answer for a URL, as one of the PROBE_* constants.
	 *
	 * A boolean probe is still accepted: true is ok, false is dead. Anything
	 * else that is not a known answer counts as no definite answer.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private function probe_answer( $url ) {
		$answer = call_user_func( $this->probe, $url );

		if ( true === $answer ) {
			return self::PROBE_OK;
		}

		if ( false === $answer ) {
			return self::PROBE_DEAD;
		}

		return in_array( $answer, array( self::PROBE_OK, self::PROBE_DEAD ), true ) ? $answer : self::PROBE_FAILED;
	}
}
