<?php
/**
 * Tests for FetchImportProductImageCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\FetchImportProductImageCommand;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * FetchImportProductImageCommand: `wp fa:media fetch-import-product-image <id>`.
 *
 * This command is expected to retire under the image-intake contract
 * (featherarms-operations-digitalocean PR #572) and its source is untouched
 * here. The tests cover the guards and the vendor URL construction; the
 * download path uses raw cURL against a live host and is not driven.
 * Issue #24.
 */
class FetchImportProductImageCommandTest extends TestCase {

	/**
	 * Reset the recorder and stub the filesystem bootstrap the command runs
	 * before any guard.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$GLOBALS['wp_filesystem'] = Mockery::mock( 'WP_Filesystem_for_test' );
	}

	/**
	 * Drop the filesystem global.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		parent::tearDown();
	}

	/**
	 * A product mock answering get_sku().
	 *
	 * @param string $sku SKU.
	 * @return \Mockery\MockInterface
	 */
	private function product( $sku ) {
		$product = Mockery::mock( 'WC_Product_for_test' );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );
		return $product;
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new FetchImportProductImageCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media fetch-import-product-image', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'execute' ), $calls[0]['args'][1] );
	}

	/**
	 * A missing product id is an error before any lookup.
	 */
	public function test_execute_errors_without_a_product_id() {
		Functions\expect( 'get_attached_media' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Missing Argument: <id>' );

		( new FetchImportProductImageCommand() )->execute( array(), array() );
	}

	/**
	 * A product that already has an image attached is refused.
	 */
	public function test_execute_refuses_a_product_with_an_attached_image() {
		Functions\expect( 'get_attached_media' )->once()->with( 'image', 5 )->andReturn( array( 'an attachment' ) );
		Functions\expect( 'get_field' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '(5) Post already has an image attached.' );

		( new FetchImportProductImageCommand() )->execute( array( 5 ), array() );
	}

	/**
	 * An explicit image_source is used as-is; a file of that name already in
	 * the library stops the run before download.
	 */
	public function test_execute_uses_image_source_and_refuses_an_existing_file() {
		Functions\when( 'get_attached_media' )->justReturn( array() );
		Functions\when( 'get_field' )->justReturn( 'https://cdn.example.test/path/photo-1.jpg' );
		Functions\expect( 'post_exists' )->once()->with( 'photo-1' )->andReturn( 123 );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '(5): photo-1 already exists. Not redownloading.' );

		( new FetchImportProductImageCommand() )->execute( array( 5 ), array() );
	}

	/**
	 * Without an image_source, a Davidsons product's URL is built from the
	 * lower-cased SKU's first two characters and the SKU itself.
	 */
	public function test_davidsons_url_is_built_from_the_sku() {
		Functions\when( 'get_attached_media' )->justReturn( array() );
		Functions\when( 'get_field' )->alias(
			fn( $field ) => 'dealer' === $field ? 'Davidsons' : ''
		);
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'AB12' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'post_exists' )->once()->with( 'ab12' )->andReturn( 1 );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'ab12 already exists' );

		try {
			( new FetchImportProductImageCommand() )->execute( array( 5 ), array() );
		} finally {
			$debug = array_column( array_column( \WP_CLI::get_calls( 'debug' ), 'args' ), 0 );
			$this->assertContains( 'URL: https://res.cloudinary.com/davidsons-inc/v1/media/catalog/product/a/b/ab12.jpg', $debug );
		}
	}

	/**
	 * A CSSI product's URL keeps the SKU's case and honours --extension.
	 */
	public function test_cssi_url_keeps_sku_case_and_uses_extension_flag() {
		Functions\when( 'get_attached_media' )->justReturn( array() );
		Functions\when( 'get_field' )->alias(
			fn( $field ) => 'dealer' === $field ? 'cssi' : ''
		);
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'X9' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'post_exists' )->once()->with( 'X9' )->andReturn( 1 );
		$this->expectException( \Exception::class );

		try {
			( new FetchImportProductImageCommand() )->execute( array( 5 ), array( 'extension' => 'png' ) );
		} finally {
			$debug = array_column( array_column( \WP_CLI::get_calls( 'debug' ), 'args' ), 0 );
			$this->assertContains( 'URL: https://media.chattanoogashooting.com/images/product/X9/X9.png', $debug );
		}
	}

	/**
	 * An unknown dealer yields an empty URL, which then fails the existence
	 * check on an empty filename rather than being reported as unknown.
	 *
	 * Pinned, not endorsed: the command has no branch for a dealer it does not
	 * know, and `_fa_vendor` now carries five slugs where this code knows two.
	 */
	public function test_unknown_dealer_produces_an_empty_url() {
		Functions\when( 'get_attached_media' )->justReturn( array() );
		Functions\when( 'get_field' )->alias(
			fn( $field ) => 'dealer' === $field ? 'rsrgroup' : ''
		);
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'R1' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\expect( 'post_exists' )->once()->with( '' )->andReturn( 1 );
		$this->expectException( \Exception::class );

		try {
			( new FetchImportProductImageCommand() )->execute( array( 5 ), array() );
		} finally {
			$debug = array_column( array_column( \WP_CLI::get_calls( 'debug' ), 'args' ), 0 );
			$this->assertContains( 'URL: ', $debug );
		}
	}
}
