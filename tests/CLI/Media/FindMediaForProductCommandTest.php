<?php
/**
 * Tests for FindMediaForProductCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\FindMediaForProductCommand;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * FindMediaForProductCommand: `wp fa:media-dev find-media-for-product <id>`.
 *
 * The gallery branch prompts on STDIN for every near match and is not driven
 * here; every fixture below is either an exact match (featured) or no match.
 * Issue #24.
 */
class FindMediaForProductCommandTest extends TestCase {

	/**
	 * Define the WordPress time constants the command reads.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_get_attachment_url' )->alias( fn( $id ) => 'https://example.test/' . $id . '.jpg' );
	}

	/**
	 * An attachment post stub.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $title Post title.
	 * @return object
	 */
	private function attachment( $id, $title ) {
		$post             = new \stdClass();
		$post->ID         = $id;
		$post->post_title = $title;
		return $post;
	}

	/**
	 * A WC_Product mock with the given identity and current image.
	 *
	 * @param string $sku      Product SKU.
	 * @param int    $image_id Current featured image id.
	 * @return \Mockery\MockInterface
	 */
	private function product( $sku, $image_id = 0 ) {
		$product = Mockery::mock( 'WC_Product_for_test' );
		$product->shouldReceive( 'get_id' )->andReturn( 12 );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );
		$product->shouldReceive( 'get_name' )->andReturn( 'Thing' );
		$product->shouldReceive( 'get_image_id' )->andReturn( $image_id );
		return $product;
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new FindMediaForProductCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media-dev find-media-for-product', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'execute' ), $calls[0]['args'][1] );
	}

	/**
	 * A missing product id is an error before any lookup.
	 */
	public function test_execute_errors_without_an_id() {
		Functions\expect( 'wc_get_product' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Missing Argument: <id>' );

		( new FindMediaForProductCommand() )->execute( array(), array() );
	}

	/**
	 * On a cache miss the attachment list is queried, cached for ten minutes,
	 * and an exact title match (SKU basename, .jpg stripped) becomes the
	 * featured image.
	 */
	public function test_exact_match_becomes_featured_image_and_list_is_cached() {
		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => 'image/jpeg',
			'posts_per_page' => -1,
			'orderby'        => 'post_title',
			'order'          => 'ASC',
		);
		$list  = array( $this->attachment( 7, '1001.jpg' ), $this->attachment( 8, 'zzz.jpg' ) );

		$product = $this->product( 'FA-1001' );
		$product->shouldReceive( 'set_image_id' )->once()->with( 7 );
		$product->shouldReceive( 'set_gallery_image_ids' )->once()->with( array() );
		$product->shouldReceive( 'save' )->twice();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\expect( 'get_transient' )->once()->with( 'get_posts_' . md5( json_encode( $query ) ) )->andReturn( false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\expect( 'get_posts' )->once()->with( $query )->andReturn( $list );
		Functions\expect( 'set_transient' )->once()->with( 'get_posts_' . md5( json_encode( $query ) ), $list, 600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		( new FindMediaForProductCommand() )->execute( array( 12 ), array() );

		$logs = array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
		$this->assertContains( 'Cache Missed!', $logs );
		$this->assertContains( 'Finding media for product 12.', $logs );
		$this->assertContains( '  Product SKU: FA-1001', $logs );
		$this->assertContains( 'Featured Image id: 7.', $logs );
		$this->assertContains( 'Setting product image', $logs );
	}

	/**
	 * On a cache hit no query runs, and an attachment that is already the
	 * featured image is left alone.
	 */
	public function test_cache_hit_skips_query_and_already_featured_image_is_not_reset() {
		$product = $this->product( 'FA-1001', 7 );
		$product->shouldReceive( 'set_image_id' )->never();
		$product->shouldReceive( 'set_gallery_image_ids' )->once()->with( array() );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_transient' )->justReturn( array( $this->attachment( 7, '1001.jpg' ) ) );
		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'set_transient' )->never();

		( new FindMediaForProductCommand() )->execute( array( 12 ), array() );

		$logs = array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
		$this->assertNotContains( 'Cache Missed!', $logs );
		$this->assertContains( 'Attachment ID 7 is already attached to product ID 12', $logs );
	}

	/**
	 * Titles are matched case-insensitively against the lower-cased basename,
	 * and a SKU without the FA- prefix is used as-is.
	 */
	public function test_match_is_case_insensitive_and_keeps_non_fa_skus_whole() {
		$product = $this->product( 'abc-9' );
		$product->shouldReceive( 'set_image_id' )->once()->with( 3 );
		$product->shouldReceive( 'set_gallery_image_ids' )->once();
		$product->shouldReceive( 'save' )->twice();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_transient' )->justReturn( array( $this->attachment( 3, 'abc-9.jpg' ), $this->attachment( 4, 'ABC-9.jpg' ) ) );

		( new FindMediaForProductCommand() )->execute( array( 12 ), array() );

		$logs = array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
		$this->assertContains( 'Featured Image id: 3.', $logs );
		$this->assertNotContains( 'Featured Image id: 4.', $logs, 'strpos() is case-sensitive: an upper-case title does not match.' );
	}
}
