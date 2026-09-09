<?php
/**
 * Reads the `_fa_media` postmeta cell written by the Akeneo export.
 *
 * @package    fa-toolkit
 * @since 1.2.0
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * A product's remote media, parsed and ordered.
 *
 * The `_fa_media` cell is the Akeneo media array verbatim. Measured against
 * the full 71,430-row export: every entry carries role/kind/sha256/title/url,
 * `position` appears on gallery entries and never on heroes, there is exactly
 * one hero per product with media, and no product has images without a hero.
 *
 * Two things this class deliberately does NOT trust:
 *
 * - Array order. The hero is found by role. Today it is always first, but
 *   ordering is an incidental property of the exporter, not a contract, and
 *   a silently reordered array would otherwise pick the wrong hero image.
 * - Entry kind. The array carries documents on purpose, so that they need no
 *   re-export the day documents get a WooCommerce slot. The EXPORTER therefore
 *   does not filter them, and this class does — every accessor below returns
 *   images only.
 */
class RemoteMedia {

	/**
	 * Image entries, hero first then gallery by ascending position.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $images Ordered image entries.
	 */
	private function __construct( array $images ) {
		$this->images = $images;
	}

	/**
	 * Build from a raw `_fa_media` postmeta value.
	 *
	 * The export writes an EMPTY CELL rather than "[]" for a product with no
	 * media, so the empty case is the common one and must be cheap.
	 *
	 * @param string $raw Raw postmeta value.
	 * @return self
	 */
	public static function from_meta( $raw ) {
		if ( true !== is_string( $raw ) || '' === trim( $raw ) ) {
			return new self( array() );
		}

		$decoded = json_decode( $raw, true );

		if ( true !== is_array( $decoded ) ) {
			return new self( array() );
		}

		$hero    = null;
		$gallery = array();

		foreach ( $decoded as $entry ) {
			if ( true !== is_array( $entry ) || 'image' !== ( $entry['kind'] ?? null ) ) {
				continue;
			}

			// url and sha256 are both load-bearing: one renders, the other is
			// half the attachment's identity key. An entry missing either
			// cannot produce a usable attachment, so it never reaches one.
			if ( '' === (string) ( $entry['url'] ?? '' ) || '' === (string) ( $entry['sha256'] ?? '' ) ) {
				continue;
			}

			if ( 'hero' === ( $entry['role'] ?? null ) ) {
				$hero = $entry;
				continue;
			}

			$gallery[] = $entry;
		}

		usort(
			$gallery,
			function ( $a, $b ) {
				return ( (int) ( $a['position'] ?? PHP_INT_MAX ) ) <=> ( (int) ( $b['position'] ?? PHP_INT_MAX ) );
			}
		);

		$images = null === $hero ? $gallery : array_merge( array( $hero ), $gallery );

		return new self( $images );
	}

	/**
	 * Whether this product has any usable image.
	 *
	 * @return bool
	 */
	public function has_images() {
		return array() !== $this->images;
	}

	/**
	 * The hero entry, or null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	public function hero() {
		foreach ( $this->images as $image ) {
			if ( 'hero' === ( $image['role'] ?? null ) ) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * Gallery entries, ordered by position ascending, excluding the hero.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function gallery() {
		return array_values(
			array_filter(
				$this->images,
				function ( $image ) {
					return 'hero' !== ( $image['role'] ?? null );
				}
			)
		);
	}

	/**
	 * Every image entry, hero first then gallery by position.
	 *
	 * This is the order attachments are created in, and therefore the order
	 * the storefront shows them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		return $this->images;
	}
}
