<?php
/**
 * Tests for BrandLogoDeleteGuard.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\BrandLogoDeleteGuard;
use FAToolkit\Media\BrandLogoStore;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * BrandLogoDeleteGuard: deleting a brand-logo attachment clears the brand
 * thumbnails that pointed at it. WooCommerce Brands never does, and a stale
 * thumbnail_id renders a broken image.
 *
 * It guards no file: the logo lives on S3, and WordPress cannot delete it.
 */
class BrandLogoDeleteGuardTest extends TestCase {

	/**
	 * The constructor registers the delete action and no file filter.
	 */
	public function test_constructor_registers_only_the_delete_action() {
		$guard = new BrandLogoDeleteGuard( Mockery::mock( BrandLogoStore::class ) );

		$this->assertNotFalse( has_action( 'delete_attachment', array( $guard, 'forget_thumbnails' ) ) );
		$this->assertFalse( has_filter( 'wp_delete_file' ) );
	}

	/**
	 * Deleting a brand logo clears the thumbnails pointing at it.
	 */
	public function test_deleting_a_brand_logo_clears_its_thumbnails() {
		Functions\when( 'get_post_meta' )->justReturn( 'files/product_brands/Glock-Logo.jpg' );
		$store = Mockery::mock( BrandLogoStore::class );
		$store->shouldReceive( 'clear_thumbnails_pointing_at' )->once()->with( 50 )->andReturn( 2 );

		( new BrandLogoDeleteGuard( $store ) )->forget_thumbnails( 50 );
	}

	/**
	 * Deleting any other attachment touches no brand term.
	 */
	public function test_deleting_another_attachment_clears_nothing() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$store = Mockery::mock( BrandLogoStore::class );
		$store->shouldReceive( 'clear_thumbnails_pointing_at' )->never();

		( new BrandLogoDeleteGuard( $store ) )->forget_thumbnails( 51 );
	}
}
