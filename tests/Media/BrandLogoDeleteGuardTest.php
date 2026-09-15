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
 * BrandLogoDeleteGuard: deleting a brand-logo attachment (from wp-admin or
 * anywhere) never unlinks an uploads file, and clears the brand thumbnails
 * that pointed at it.
 *
 * The file filter is stateless on purpose: wp_delete_attachment() unlinks
 * files AFTER `deleted_post`, so a filter added on `delete_attachment` and
 * removed on `deleted_post` would already be gone when core unlinks.
 */
class BrandLogoDeleteGuardTest extends TestCase {

	/**
	 * Stub the uploads helpers.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/srv/wp/wp-content/uploads' ) );
		Functions\when( 'wp_normalize_path' )->alias( fn( $path ) => str_replace( '\\', '/', $path ) );
	}

	/**
	 * The constructor registers both hooks.
	 */
	public function test_constructor_registers_hooks() {
		$guard = new BrandLogoDeleteGuard( Mockery::mock( BrandLogoStore::class ) );

		$this->assertNotFalse( has_action( 'delete_attachment', array( $guard, 'forget_thumbnails' ) ) );
		$this->assertNotFalse( has_filter( 'wp_delete_file', array( $guard, 'keep_remote_file' ) ) );
	}

	/**
	 * A file under uploads/fa-remote/ is never deleted.
	 */
	public function test_a_file_under_the_remote_prefix_is_kept() {
		$guard = new BrandLogoDeleteGuard( Mockery::mock( BrandLogoStore::class ) );

		$this->assertSame( '', $guard->keep_remote_file( '/srv/wp/wp-content/uploads/fa-remote/product_brands/Glock-Logo.jpg' ) );
	}

	/**
	 * Paths that are not under the prefix.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function ordinary_paths() {
		return array(
			'uploads root'      => array( '/srv/wp/wp-content/uploads/Glock-Logo.jpg' ),
			'dated upload'      => array( '/srv/wp/wp-content/uploads/2026/09/Glock-Logo.jpg' ),
			'lookalike sibling' => array( '/srv/wp/wp-content/uploads/fa-remote-old/Glock-Logo.jpg' ),
			'outside uploads'   => array( '/tmp/fa-remote/Glock-Logo.jpg' ),
		);
	}

	/**
	 * Every other file deletes as before.
	 *
	 * @param string $path File path.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ordinary_paths' )]
	public function test_other_files_are_untouched( $path ) {
		$guard = new BrandLogoDeleteGuard( Mockery::mock( BrandLogoStore::class ) );

		$this->assertSame( $path, $guard->keep_remote_file( $path ) );
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
