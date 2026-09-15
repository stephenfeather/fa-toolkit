<?php
/**
 * Tests for BrandLogoMap.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Media\BrandLogoMap;
use FAToolkit\Tests\TestCase;

/**
 * BrandLogoMap: parses brand_logo_map.json (infra PR #621) into entries and
 * errors. A bad entry becomes an error, never an exception, and never an
 * entry.
 */
class BrandLogoMapTest extends TestCase {

	private const URL = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/A-Zoom-Logo.jpg';
	private const KEY = 'files/product_brands/A-Zoom-Logo.jpg';

	/**
	 * Inputs that are not a JSON object.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function not_an_object() {
		return array(
			'invalid json' => array( '{"a_zoom":' ),
			'empty string' => array( '' ),
			'json list'    => array( '[{"status":"MISSING"}]' ),
			'json scalar'  => array( '"logo"' ),
			'empty object' => array( '{}' ),
		);
	}

	/**
	 * Anything that is not a non-empty JSON object is one error and no entries.
	 *
	 * @param string $json Raw map.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'not_an_object' )]
	public function test_a_map_that_is_not_a_non_empty_object_is_an_error( $json ) {
		$this->assertSame(
			array(
				'entries' => array(),
				'errors'  => array( 'map is not a non-empty JSON object' ),
			),
			BrandLogoMap::parse( $json )
		);
	}

	/**
	 * An entry that is not an object is reported by code.
	 */
	public function test_an_entry_that_is_not_an_object_is_an_error() {
		$result = BrandLogoMap::parse( '{"a_zoom":"logo"}' );

		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( array( 'a_zoom: entry is not an object' ), $result['errors'] );
	}

	/**
	 * A status outside the four the matcher emits is reported.
	 */
	public function test_an_unknown_status_is_an_error() {
		$result = BrandLogoMap::parse( '{"a_zoom":{"status":"approved"}}' );

		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( array( 'a_zoom: unknown status "approved"' ), $result['errors'] );
	}

	/**
	 * Logo entries missing either field.
	 *
	 * @return array<string, array{0:array}>
	 */
	public static function incomplete_logos() {
		return array(
			'no url'      => array( array( 'status' => 'logo', 's3_key' => self::KEY ) ),
			'no s3_key'   => array( array( 'status' => 'logo', 'url' => self::URL ) ),
			'empty url'   => array( array( 'status' => 'logo', 's3_key' => self::KEY, 'url' => '' ) ),
			'non-string'  => array( array( 'status' => 'logo', 's3_key' => array(), 'url' => self::URL ) ),
		);
	}

	/**
	 * A logo entry needs both an s3_key and a url.
	 *
	 * @param array $entry Entry.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'incomplete_logos' )]
	public function test_a_logo_without_url_or_s3_key_is_an_error( array $entry ) {
		$result = BrandLogoMap::parse( wp_json_encode_for_test( array( 'a_zoom' => $entry ) ) );

		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( array( 'a_zoom: logo entry needs s3_key and url' ), $result['errors'] );
	}

	/**
	 * A url that does not serve the named s3 key is a mismatch, not a logo:
	 * the attachment is keyed on s3_key and renders url, so they must agree.
	 */
	public function test_a_url_that_does_not_end_in_its_s3_key_is_an_error() {
		$result = BrandLogoMap::parse(
			wp_json_encode_for_test(
				array(
					'a_zoom' => array(
						'status' => 'logo',
						's3_key' => self::KEY,
						'url'    => 'https://ik.imagekit.io/featherarms/s3/files/product_brands/Other-Logo.jpg',
					),
				)
			)
		);

		$this->assertSame( array(), $result['entries'] );
		$this->assertSame( array( 'a_zoom: url does not end in s3_key' ), $result['errors'] );
	}

	/**
	 * Valid entries survive alongside errors, keyed by code, in map order.
	 */
	public function test_valid_entries_are_kept_next_to_errors() {
		$result = BrandLogoMap::parse(
			wp_json_encode_for_test(
				array(
					'a_zoom'       => array( 'status' => 'logo', 's3_key' => self::KEY, 'url' => self::URL ),
					'10_ring'      => array( 'status' => 'MISSING' ),
					'broken'       => array( 'status' => 'logo' ),
					'heckler_koch' => array( 'status' => 'conflict', 'candidates' => array( 'a', 'b' ) ),
					'burris'       => array( 'status' => 'near_identical', 'candidates' => array( 'c' ), 'group' => array( 'burris', 'burris_company_inc' ) ),
				)
			)
		);

		$this->assertSame(
			array(
				'a_zoom'       => array( 'status' => 'logo', 's3_key' => self::KEY, 'url' => self::URL ),
				'10_ring'      => array( 'status' => 'MISSING' ),
				'heckler_koch' => array( 'status' => 'conflict' ),
				'burris'       => array( 'status' => 'near_identical' ),
			),
			$result['entries']
		);
		$this->assertSame( array( 'broken: logo entry needs s3_key and url' ), $result['errors'] );
	}
}

/**
 * JSON encoding for fixtures without a WordPress stub.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_test( $value ) {
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
