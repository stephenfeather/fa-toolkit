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
 * A brand-logo attachment is a pointer attachment with no parent: it carries
 * the same meta contract as a product pointer (see RemoteAttachmentCreator),
 * plus `_fa_brand_logo_s3_key`, its identity. Its `_wp_attached_file` sits
 * under `fa-remote/`, a path no real upload uses, which BrandLogoDeleteGuard
 * protects from deletion.
 *
 * A fetch or insert that fails creates nothing and sets no thumbnail; the
 * brand is reported and a rerun retries it.
 */
class BrandLogoCreator {

	/**
	 * Attachment meta holding the map's s3_key.
	 *
	 * @var string
	 */
	public const KEY_META = '_fa_brand_logo_s3_key';

	/**
	 * Directory, relative to uploads, that `_wp_attached_file` names.
	 *
	 * @var string
	 */
	public const ATTACHED_DIR = BrandLogoDeleteGuard::REMOTE_ROOT . '/product_brands';

	/**
	 * The fetcher.
	 *
	 * @var BrandLogoFetcher
	 */
	private $fetcher;

	/**
	 * Totals for the run in progress.
	 *
	 * @var array<string, mixed>
	 */
	private $totals = array();

	/**
	 * Constructor.
	 *
	 * @param BrandLogoFetcher $fetcher Fetcher.
	 */
	public function __construct( BrandLogoFetcher $fetcher ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Apply a plan.
	 *
	 * @param array $plan Plan from BrandLogoPlan::build().
	 * @return array{created:int,refreshed:int,thumbnails_set:int,dead:int,fetch_failed:int,not_image:int,insert_failed:int,write_failed:int,blocked:array<int,string>}
	 */
	public function apply( array $plan ) {
		$this->totals = array(
			'created'        => 0,
			'refreshed'      => 0,
			'thumbnails_set' => 0,
			'dead'           => 0,
			'fetch_failed'   => 0,
			'not_image'      => 0,
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
		$image = $this->fetcher->fetch( $attachment['url'] );

		if ( 'ok' !== $image['status'] ) {
			$this->count_fetch_failure( $image['status'] );
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_title'     => $attachment['alt'],
				'post_mime_type' => $image['mime'],
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
		$this->write_image( $id, $attachment['url'], $image );
		$this->write_alt( $id, $attachment['alt'] );

		++$this->totals['created'];

		return $id;
	}

	/**
	 * Refresh an existing attachment where the plan says it differs.
	 *
	 * A failed dimension fetch still leaves a usable attachment: it renders
	 * the raw URL unsized, so its thumbnails are still set.
	 *
	 * @param array $attachment Planned attachment.
	 * @return int Attachment id.
	 */
	private function refresh( array $attachment ) {
		$id      = (int) $attachment['attachment_id'];
		$changed = false;

		if ( true === $attachment['refresh']['url'] ) {
			$this->write_meta( $id, '_fa_remote_url', $attachment['url'] );
			$changed = true;
		}

		if ( true === $attachment['refresh']['alt'] && '' !== (string) $attachment['alt'] ) {
			$this->write_alt( $id, $attachment['alt'] );
			$changed = true;
		}

		if ( true === $attachment['refresh']['dimensions'] ) {
			$changed = $this->refresh_dimensions( $id, $attachment['url'] ) || $changed;
		}

		if ( true === $changed ) {
			++$this->totals['refreshed'];
		}

		return $id;
	}

	/**
	 * Fetch and store the dimensions of an existing attachment.
	 *
	 * @param int    $id  Attachment id.
	 * @param string $url Logo URL.
	 * @return bool Whether anything was written.
	 */
	private function refresh_dimensions( $id, $url ) {
		$image = $this->fetcher->fetch( $url );

		if ( 'ok' !== $image['status'] ) {
			$this->count_fetch_failure( $image['status'] );
			return false;
		}

		$this->write_image( $id, $url, $image );

		return true;
	}

	/**
	 * Write what the fetch learned, and the metadata WordPress needs.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $url   Logo URL.
	 * @param array  $image Successful fetch result.
	 * @return void
	 */
	private function write_image( $id, $url, array $image ) {
		$attached_file = self::ATTACHED_DIR . '/' . wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$this->write_meta( $id, '_fa_media_sha256', $image['sha256'] );
		$this->write_meta( $id, '_fa_remote_width', (int) $image['width'] );
		$this->write_meta( $id, '_fa_remote_height', (int) $image['height'] );
		$this->write_meta( $id, '_wp_attached_file', $attached_file );
		$this->write_meta( $id, '_wp_attachment_metadata', RemoteAttachmentMetadata::build( $url, $image['width'], $image['height'], $attached_file ) );
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
	 * Count a fetch that did not yield an image.
	 *
	 * @param string $status Fetch status.
	 * @return void
	 */
	private function count_fetch_failure( $status ) {
		$totals = array(
			'dead'      => 'dead',
			'not_image' => 'not_image',
		);

		++$this->totals[ $totals[ $status ] ?? 'fetch_failed' ];
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
