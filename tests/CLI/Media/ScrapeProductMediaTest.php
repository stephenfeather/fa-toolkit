<?php
/**
 * Tests for ScrapeProductMedia.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\ScrapeProductMedia;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * ScrapeProductMedia: `wp fa:media scrape-product-media <id>`.
 *
 * Gated on the image-intake contract and expected to retire; source
 * untouched. Every path that reaches import_media() hits the unconditional
 * die() tracked by #38, and every guard without --override also die()s, so
 * those paths cannot be driven from PHPUnit. What remains, and is covered
 * here: registration, the missing-product error, the --override warnings, the
 * Davidsons soft-404 trash-and-error, and a page with no matching tags.
 * Issue #24.
 */
class ScrapeProductMediaTest extends TestCase {

	/**
	 * Reset the recorder.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * A draft product with no media, ready to scrape.
	 *
	 * @param string $status Post status.
	 * @return \Mockery\MockInterface
	 */
	private function product( $status = 'draft' ) {
		$product = Mockery::mock( 'WC_Product_for_test' );
		$product->shouldReceive( 'get_status' )->andReturn( $status );
		$product->shouldReceive( 'get_sku' )->andReturn( 'SKU-1' );
		$product->shouldReceive( 'get_gallery_image_ids' )->andReturn( array() );
		return $product;
	}

	/**
	 * Stub everything a clean Davidsons scrape needs up to the page fetch.
	 *
	 * @param string $page HTML body the vendor returns.
	 */
	private function stub_davidsons_scrape( $page ) {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Functions\when( 'get_field' )->justReturn( 'Davidsons' );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://www.davidsonsinc.com/catalogsearch/result/?q=SKU-1' )
			->andReturn( 'response' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $page );
	}

	/**
	 * Warnings logged, in order.
	 *
	 * @return array
	 */
	private function warnings() {
		return array_column( array_column( \WP_CLI::get_calls( 'warning' ), 'args' ), 0 );
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new ScrapeProductMedia();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media scrape-product-media', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'wp_cli_scrape_product_media' ), $calls[0]['args'][1] );
	}

	/**
	 * A product id that resolves to nothing is an error.
	 */
	public function test_errors_when_the_product_does_not_exist() {
		Functions\when( 'wc_get_product' )->justReturn( false );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Product (5) does not exist.' );

		( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array() );
	}

	/**
	 * A Davidsons soft 404 trashes the product and stops.
	 */
	public function test_davidsons_soft_404_trashes_the_product() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
		$this->stub_davidsons_scrape( '<html><body><h1>404 Not Found</h1></body></html>' );
		Functions\expect( 'wp_delete_post' )->once()->with( 5 );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Product doesnt exist at Davidsons for (5) Moved to trash.' );

		( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array() );
	}

	/**
	 * A vendor page with no image tags of the configured class yields no media,
	 * which the command reports as a failed import without publishing.
	 */
	public function test_page_without_matching_images_is_a_failed_import() {
		$product = $this->product();
		$product->shouldReceive( 'set_status' )->never();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->stub_davidsons_scrape( '<html><body><img class="other" src="https://x.test/a.jpg"><p>Text</p></body></html>' );
		Functions\expect( 'wp_delete_post' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Failed to import media for product (5)' );

		( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array() );
	}

	/**
	 * --override turns the published-product and placeholder-flag guards into
	 * warnings and carries on to the scrape.
	 */
	public function test_override_continues_past_published_and_placeholder_guards() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'publish' ) );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Functions\when( 'get_field' )->justReturn( 'Davidsons' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( 'response' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '<html><body></body></html>' );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Failed to import media' );

		try {
			( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array( 'override' => true ) );
		} finally {
			$this->assertSame(
				array(
					'Product (5) is already published. (publish)',
					'Product (5) has been previously tagged as having a placeholder image.',
				),
				$this->warnings()
			);
		}
	}

	/**
	 * --dealer filters on the product's dealer; a matching filter proceeds to
	 * the vendor fetch (a mismatch die()s and cannot be driven here).
	 */
	public function test_matching_dealer_filter_proceeds_to_fetch() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
		$this->stub_davidsons_scrape( '<html><body></body></html>' );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Failed to import media' );

		( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array( 'dealer' => 'Davidsons' ) );
	}

	/**
	 * A failed vendor request is reported through WP_CLI::error.
	 */
	public function test_vendor_request_failure_is_an_error() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_post_thumbnail' )->justReturn( false );
		Functions\when( 'get_field' )->justReturn( 'CSSI' );
		$error = new \WP_Error( 'http', 'connection refused' );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://chattanoogashooting.com/catalog/product/SKU-1' )
			->andReturn( $error );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Error: connection refused' );

		( new ScrapeProductMedia() )->wp_cli_scrape_product_media( array( 5 ), array() );
	}
}
