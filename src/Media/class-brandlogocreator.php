<?php
/**
 * Carries out a brand-logo plan.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Creates or refreshes brand-logo attachments and sets term thumbnails.
 *
 * A brand logo takes the product-image path exactly (operator, 2026-09-15:
 * "images are stored on S3, images are not stored on the web host"). It is a
 * pointer attachment with no parent, carrying the meta contract of
 * RemoteAttachmentCreator plus `_fa_brand_logo_s3_key`, its identity:
 *
 * - Nothing is downloaded. Width, height and sha256 come from the map; when
 *   the map lacks them none is written, and the logo renders the raw ImageKit
 *   URL unsized, as a product image without dimensions does.
 * - `_wp_attached_file` is the bare basename, as for products.
 *
 * An insert that fails sets no thumbnail; the brand is reported and a rerun
 * retries it.
 */
class BrandLogoCreator {

	/**
	 * Attachment meta holding the map's s3_key.
	 *
	 * @var string
	 */
	public const KEY_META = '_fa_brand_logo_s3_key';

	/**
	 * Totals for the run in progress.
	 *
	 * @var array<string, mixed>
	 */
	private $totals = array();

	/**
	 * Apply a plan.
	 *
	 * @param array $plan Plan from BrandLogoPlan::build().
	 * @return array{created:int,refreshed:int,thumbnails_set:int,insert_failed:int,write_failed:int,blocked:array<int,string>}
	 */
	public function apply( array $plan ) {
		$this->totals = array(
			'created'        => 0,
			'refreshed'      => 0,
			'thumbnails_set' => 0,
			'insert_failed'  => 0,
			'write_failed'   => 0,
			'blocked'        => array(),
		);

		$ids = array();

		foreach ( $plan['attachments'] as $key => $attachment ) {
			$ids[ $key ] = $attachment['attachment_id'] > 0 ? $this->refresh( $attachment ) : $this->create( $attachment );
		}

		foreach ( $plan['rows'] as $row ) {
			if ( 'set' !== $row['action'] ) {
				continue;
			}

			$id = (int) ( $ids[ $row['s3_key'] ] ?? 0 );

			if ( $id < 1 ) {
				$this->totals['blocked'][] = $row['code'];
				continue;
			}

			$this->set_thumbnail( (int) $row['term_id'], $id );
		}

		return $this->totals;
	}

	/**
	 * Create one attachment.
	 *
	 * @param array $attachment Planned attachment.
	 * @return int Attachment id, or 0 when nothing was created.
	 */
	private function create( array $attachment ) {
		$id = wp_insert_attachment(
			array(
				'post_title'     => $attachment['alt'],
				'post_mime_type' => RemoteAttachmentMetadata::mime_type( $attachment['url'] ),
				'post_status'    => 'inherit',
			),
			false,
			0
		);

		// Without this guard the meta writes below would land on post 0.
		if ( true === is_wp_error( $id ) || (int) $id < 1 ) {
			++$this->totals['insert_failed'];
			return 0;
		}

		$id = (int) $id;

		$this->write_meta( $id, self::KEY_META, $attachment['s3_key'] );
		$this->write_meta( $id, '_fa_remote_url', $attachment['url'] );
		$this->write_image_fields( $id, $attachment );
		$this->write_alt( $id, $attachment['alt'] );

		++$this->totals['created'];

		return $id;
	}

	/**
	 * Refresh an existing attachment where the plan says it differs.
	 *
	 * @param array $attachment Planned attachment.
	 * @return int Attachment id.
	 */
	private function refresh( array $attachment ) {
		$id      = (int) $attachment['attachment_id'];
		$refresh = $attachment['refresh'];

		if ( true === $refresh['url'] ) {
			$this->write_meta( $id, '_fa_remote_url', $attachment['url'] );
		}

		if ( true === $refresh['alt'] ) {
			$this->write_alt( $id, $attachment['alt'] );
		}

		if ( true === $refresh['dimensions'] ) {
			$this->write_image_fields( $id, $attachment );
		}

		if ( true === $refresh['url'] || true === $refresh['alt'] || true === $refresh['dimensions'] ) {
			++$this->totals['refreshed'];
		}

		return $id;
	}

	/**
	 * Write the map's sha256 and dimensions, and the metadata WordPress needs.
	 *
	 * Mirrors RemoteAttachmentCreator::write_entry_meta(): without dimensions
	 * nothing image-shaped is written, rather than guessed.
	 *
	 * @param int   $id         Attachment id.
	 * @param array $attachment Planned attachment.
	 * @return void
	 */
	private function write_image_fields( $id, array $attachment ) {
		if ( null !== $attachment['sha256'] ) {
			$this->write_meta( $id, '_fa_media_sha256', $attachment['sha256'] );
		}

		if ( null === $attachment['width'] || null === $attachment['height'] ) {
			return;
		}

		$file = wp_basename( (string) wp_parse_url( $attachment['url'], PHP_URL_PATH ) );

		$this->write_meta( $id, '_fa_remote_width', (int) $attachment['width'] );
		$this->write_meta( $id, '_fa_remote_height', (int) $attachment['height'] );
		$this->write_meta( $id, '_wp_attached_file', $file );
		$this->write_meta( $id, '_wp_attachment_metadata', RemoteAttachmentMetadata::build( $attachment['url'], $attachment['width'], $attachment['height'], $file ) );
	}

	/**
	 * Write alt text when there is one (operator ruling on #105: the brand name).
	 *
	 * @param int    $id  Attachment id.
	 * @param string $alt Alt text.
	 * @return void
	 */
	private function write_alt( $id, $alt ) {
		if ( '' !== (string) $alt ) {
			$this->write_meta( $id, '_wp_attachment_image_alt', (string) $alt );
		}
	}

	/**
	 * Point a term at an attachment.
	 *
	 * @param int $term_id Term id.
	 * @param int $id      Attachment id.
	 * @return void
	 */
	private function set_thumbnail( $term_id, $id ) {
		if ( false !== update_term_meta( $term_id, 'thumbnail_id', $id ) || (string) get_term_meta( $term_id, 'thumbnail_id', true ) === (string) $id ) {
			++$this->totals['thumbnails_set'];
			return;
		}

		++$this->totals['write_failed'];
	}

	/**
	 * Write one post meta value, counting it when it did not store.
	 *
	 * WordPress's update_post_meta() returns false when the value is unchanged,
	 * so false alone is not a failure; only a stored value that still differs is.
	 *
	 * @param int    $id    Post id.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function write_meta( $id, $key, $value ) {
		if ( false !== update_post_meta( $id, $key, $value ) ) {
			return;
		}

		$stored = get_post_meta( $id, $key, true );
		$equal  = true === is_array( $value ) ? $stored === $value : ( true !== is_array( $stored ) && (string) $stored === (string) $value );

		if ( true !== $equal ) {
			++$this->totals['write_failed'];
		}
	}
}
