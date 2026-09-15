<?php
/**
 * Reads brand_logo_map.json, the brand code to logo map built in infra PR #621.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Parses the map into entries and errors. Pure.
 *
 * The map is a JSON object: approved manufacturer code => entry. The matcher
 * emits four statuses; only `logo` carries a file (`s3_key`) and the ImageKit
 * `url` serving it. A bad entry becomes an error, never an entry, so nothing
 * downstream can attach a logo on the strength of a half-formed row.
 */
final class BrandLogoMap {

	/**
	 * Statuses the matcher emits.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = array( 'logo', 'MISSING', 'conflict', 'near_identical' );

	/**
	 * Parse a raw map.
	 *
	 * @param string $json Raw map file contents.
	 * @return array{entries:array<string,array{status:string,s3_key?:string,url?:string}>,errors:array<int,string>}
	 */
	public static function parse( $json ) {
		$decoded = json_decode( (string) $json, true );

		if ( true !== is_array( $decoded ) || array() === $decoded || true === array_is_list( $decoded ) ) {
			return array(
				'entries' => array(),
				'errors'  => array( 'map is not a non-empty JSON object' ),
			);
		}

		$result = array(
			'entries' => array(),
			'errors'  => array(),
		);

		foreach ( $decoded as $code => $entry ) {
			$code  = (string) $code;
			$error = self::entry_error( $code, $entry );

			if ( null !== $error ) {
				$result['errors'][] = $error;
				continue;
			}

			$result['entries'][ $code ] = self::normalise( $entry );
		}

		return $result;
	}

	/**
	 * Why an entry is unusable, or null when it is usable.
	 *
	 * @param string $code  Brand code.
	 * @param mixed  $entry Decoded entry.
	 * @return string|null
	 */
	private static function entry_error( $code, $entry ) {
		if ( true !== is_array( $entry ) || ( array() !== $entry && true === array_is_list( $entry ) ) ) {
			return $code . ': entry is not an object';
		}

		$status = $entry['status'] ?? null;

		if ( true !== is_string( $status ) || true !== in_array( $status, self::STATUSES, true ) ) {
			return sprintf( '%s: unknown status "%s"', $code, is_scalar( $status ) ? (string) $status : '' );
		}

		if ( 'logo' !== $status ) {
			return null;
		}

		$key = $entry['s3_key'] ?? null;
		$url = $entry['url'] ?? null;

		if ( true !== is_string( $key ) || true !== is_string( $url ) || '' === $key || '' === $url ) {
			return $code . ': logo entry needs s3_key and url';
		}

		// The attachment is keyed on s3_key and renders url, so they must name
		// the same file.
		$path = explode( '?', $url, 2 )[0];

		if ( true !== str_ends_with( $path, '/' . ltrim( $key, '/' ) ) ) {
			return $code . ': url does not end in s3_key';
		}

		return null;
	}

	/**
	 * The fields of a usable entry that the plan reads.
	 *
	 * @param array $entry Usable entry.
	 * @return array{status:string,s3_key?:string,url?:string}
	 */
	private static function normalise( array $entry ) {
		if ( 'logo' !== $entry['status'] ) {
			return array( 'status' => $entry['status'] );
		}

		return array(
			'status' => 'logo',
			's3_key' => $entry['s3_key'],
			'url'    => $entry['url'],
		);
	}
}
