<?php
/**
 * Tests for BrandLogoFetcher.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\BrandLogoFetcher;
use FAToolkit\Tests\TestCase;

/**
 * BrandLogoFetcher: downloads a logo once to learn its width, height, mime
 * and sha256. Only 404 and 410 are dead; anything else that is not a 200
 * image says nothing about the image (issue #95 rule).
 */
class BrandLogoFetcherTest extends TestCase {

	private const URL = 'https://ik.imagekit.io/featherarms/s3/files/product_brands/Glock-Logo.png';

	/**
	 * The bytes of a PNG header declaring the given size. getimagesize reads
	 * the IHDR chunk only, so no pixel data is needed.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	public static function png( $width, $height ) {
		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', $width, $height ) . "\x08\x02\x00\x00\x00" . "\x00\x00\x00\x00";
	}

	/**
	 * Stub the HTTP layer with one response.
	 *
	 * @param mixed  $response Response or WP_Error.
	 * @param int    $code     Status code.
	 * @param string $body     Body.
	 * @return void
	 */
	private function respond( $response, $code = 200, $body = '' ) {
		Functions\expect( 'wp_remote_get' )->once()->with( self::URL, array( 'timeout' => 20 ) )->andReturn( $response );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * A transport error is a failed fetch, not a dead image.
	 */
	public function test_a_transport_error_is_failed() {
		$this->respond( new \WP_Error( 'http_request_failed', 'timeout' ), 0 );

		$this->assertSame( array( 'status' => 'failed' ), ( new BrandLogoFetcher() )->fetch( self::URL ) );
	}

	/**
	 * Status codes that are no definite answer.
	 *
	 * @return array<string, array{0:int}>
	 */
	public static function indefinite_codes() {
		return array(
			'429' => array( 429 ),
			'500' => array( 500 ),
			'503' => array( 503 ),
			'403' => array( 403 ),
		);
	}

	/**
	 * Rate limits, server errors and other refusals are failed fetches.
	 *
	 * @param int $code Status code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'indefinite_codes' )]
	public function test_a_non_definite_status_is_failed( $code ) {
		$this->respond( array(), $code );

		$this->assertSame( array( 'status' => 'failed' ), ( new BrandLogoFetcher() )->fetch( self::URL ) );
	}

	/**
	 * 404 and 410 are dead.
	 *
	 * @return array<string, array{0:int}>
	 */
	public static function dead_codes() {
		return array(
			'404' => array( 404 ),
			'410' => array( 410 ),
		);
	}

	/**
	 * A 404 or 410 is a dead URL.
	 *
	 * @param int $code Status code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'dead_codes' )]
	public function test_a_missing_image_is_dead( $code ) {
		$this->respond( array(), $code );

		$this->assertSame( array( 'status' => 'dead' ), ( new BrandLogoFetcher() )->fetch( self::URL ) );
	}

	/**
	 * A 200 whose body is not an image (an HTML error page, an empty body) is
	 * reported as not an image.
	 */
	public function test_a_body_that_is_not_an_image_is_not_image() {
		$this->respond( array(), 200, '<html>nope</html>' );

		$this->assertSame( array( 'status' => 'not_image' ), ( new BrandLogoFetcher() )->fetch( self::URL ) );
	}

	/**
	 * A 200 image yields its real dimensions, mime and the sha256 of its bytes.
	 */
	public function test_an_image_yields_dimensions_mime_and_sha256() {
		$body = self::png( 640, 320 );
		$this->respond( array(), 200, $body );

		$this->assertSame(
			array(
				'status' => 'ok',
				'width'  => 640,
				'height' => 320,
				'mime'   => 'image/png',
				'sha256' => hash( 'sha256', $body ),
			),
			( new BrandLogoFetcher() )->fetch( self::URL )
		);
	}
}
