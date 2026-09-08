<?php
/**
 * Serves attachment URLs for images that live on s3, not on disk.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Resolves URLs for pointer attachments.
 *
 * Product images are canonically stored in s3 and served through ImageKit
 * (Stephen's ruling, 2026-09-08). The attachments this plugin creates carry no
 * file: they hold a remote URL in `_fa_remote_url` and exist so WooCommerce
 * has an attachment ID to reference. Every URL WordPress would normally derive
 * from a local path therefore has to be answered here.
 *
 * Four filters, not three. The Store API builds its product image response
 * with wp_get_attachment_image_srcset() AND wp_get_attachment_image_sizes()
 * (StoreApi/Schemas/V1/ImageAttachmentSchema.php:102-105), and both read
 * attachment metadata that pointer attachments do not have. Filtering srcset
 * alone ships empty sizes attributes on every product image on the block
 * storefront, which nothing errors on.
 *
 * Every method returns its input untouched for attachments that are not ours.
 * These filters run for every attachment on the site.
 */
class RemoteAttachmentUrls {

	/**
	 * Candidate widths advertised in srcset, when the original is big enough.
	 *
	 * @var array<int, int>
	 */
	private const SRCSET_WIDTHS = array( 150, 300, 600, 768, 1024, 1536 );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'downsize' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 10, 5 );
		add_filter( 'wp_calculate_image_sizes', array( $this, 'sizes' ), 10, 5 );
	}

	/**
	 * The remote URL for an attachment, or '' when it is not a pointer.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string
	 */
	private function remote_url( $attachment_id ) {
		return (string) get_post_meta( (int) $attachment_id, '_fa_remote_url', true );
	}

	/**
	 * Stored intrinsic dimensions, or null when not known.
	 *
	 * Absent for images the fetcher found by walking the bucket rather than by
	 * downloading — a bucket walk lists keys and never reads bytes, so there
	 * was no point at which dimensions could have been captured.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{0:int,1:int}|null
	 */
	private function dimensions( $attachment_id ) {
		$width  = (int) get_post_meta( (int) $attachment_id, '_fa_remote_width', true );
		$height = (int) get_post_meta( (int) $attachment_id, '_fa_remote_height', true );

		if ( $width < 1 || $height < 1 ) {
			return null;
		}

		return array( $width, $height );
	}

	/**
	 * Append an ImageKit width transform to a URL.
	 *
	 * Width only, deliberately. Constraining width alone preserves the aspect
	 * ratio; constraining both would let ImageKit return something other than
	 * the ratio we then declare, which distorts the image.
	 *
	 * @param string $url   Base URL.
	 * @param int    $width Target width.
	 * @return string
	 */
	private function transform( $url, $width ) {
		return $url . '?tr=w-' . (int) $width;
	}

	/**
	 * Replace the URL of a pointer attachment.
	 *
	 * @param string $url           Local URL WordPress derived.
	 * @param int    $attachment_id Attachment id.
	 * @return string
	 */
	public function attachment_url( $url, $attachment_id ) {
		$remote = $this->remote_url( $attachment_id );

		return '' === $remote ? $url : $remote;
	}

	/**
	 * Serve an intermediate size from ImageKit.
	 *
	 * Returns false when intrinsic dimensions are unknown. That is a deliberate
	 * decline rather than a guess: WordPress then serves the full image with no
	 * width or height attributes, which costs bandwidth and shifts layout but
	 * stays truthful. Declaring a height we do not know would distort the
	 * image as well as shifting the layout, because browsers derive
	 * aspect-ratio from those attributes.
	 *
	 * Capturing dimensions at fetch time upstream turns every image onto the
	 * good path here with no change to this code.
	 *
	 * @param bool|array   $downsize      Short-circuit value from earlier filters.
	 * @param int          $attachment_id Attachment id.
	 * @param string|array $size          Requested size.
	 * @return array{0:string,1:int,2:int,3:bool}|false
	 */
	public function downsize( $downsize, $attachment_id, $size ) {
		$remote = $this->remote_url( $attachment_id );

		if ( '' === $remote ) {
			return $downsize;
		}

		$dimensions = $this->dimensions( $attachment_id );

		if ( null === $dimensions ) {
			return false;
		}

		list( $original_width, $original_height ) = $dimensions;

		$target = $this->target_width( $size, $original_width );

		if ( $target >= $original_width ) {
			// Never upscale. Serve the original and report its real size.
			return array( $remote, $original_width, $original_height, false );
		}

		$height = (int) round( $original_height * ( $target / $original_width ) );

		return array( $this->transform( $remote, $target ), $target, $height, true );
	}

	/**
	 * Resolve a requested size name or array to a target width.
	 *
	 * @param string|array $size           Requested size.
	 * @param int          $original_width Original width, used as the ceiling.
	 * @return int
	 */
	private function target_width( $size, $original_width ) {
		if ( true === is_array( $size ) && isset( $size[0] ) ) {
			return (int) $size[0];
		}

		$registered = wp_get_registered_image_subsizes();

		if ( true === is_string( $size ) && isset( $registered[ $size ]['width'] ) ) {
			return (int) $registered[ $size ]['width'];
		}

		return $original_width;
	}

	/**
	 * Build srcset candidates from ImageKit transforms.
	 *
	 * Empty when the original width is unknown: without it there is no way to
	 * tell which candidates are upscales, and advertising an upscale is worse
	 * than advertising nothing — the browser will pick it.
	 *
	 * @param array  $sources       Existing sources.
	 * @param array  $size_array    Requested size.
	 * @param string $image_src     Image src.
	 * @param array  $image_meta    Attachment metadata.
	 * @param int    $attachment_id Attachment id.
	 * @return array
	 */
	public function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$remote = $this->remote_url( $attachment_id );

		if ( '' === $remote ) {
			return $sources;
		}

		$dimensions = $this->dimensions( $attachment_id );

		if ( null === $dimensions ) {
			return array();
		}

		$original_width = $dimensions[0];
		$candidates     = array();

		foreach ( self::SRCSET_WIDTHS as $width ) {
			if ( $width > $original_width ) {
				continue;
			}

			$candidates[ $width ] = array(
				'url'        => $this->transform( $remote, $width ),
				'descriptor' => 'w',
				'value'      => $width,
			);
		}

		return $candidates;
	}

	/**
	 * Build a sizes attribute for a pointer attachment.
	 *
	 * @param string       $sizes         Existing sizes attribute.
	 * @param array|string $size          Requested size.
	 * @param string       $image_src     Image src.
	 * @param array        $image_meta    Attachment metadata.
	 * @param int          $attachment_id Attachment id.
	 * @return string
	 */
	public function sizes( $sizes, $size, $image_src, $image_meta, $attachment_id ) {
		$remote = $this->remote_url( $attachment_id );

		if ( '' === $remote ) {
			return $sizes;
		}

		$width = true === is_array( $size ) && isset( $size[0] ) ? (int) $size[0] : 0;

		if ( $width < 1 ) {
			return $sizes;
		}

		return sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width );
	}
}
