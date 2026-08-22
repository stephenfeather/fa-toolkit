<?php
/**
 * Tests for Attachment_SHA256_Hash_Meta_Box class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Admin;

use FAToolkit\Admin\Attachment_SHA256_Hash_Meta_Box;
use FAToolkit\Tests\TestCase;
use Mockery;
use ReflectionClass;

/**
 * Test Attachment_SHA256_Hash_Meta_Box class.
 *
 * Note: Constructor tests skipped due to Brain Monkey callback validation issues
 * with auto-instantiated classes. The class auto-instantiates at file load,
 * registering hooks in the constructor.
 */
class Attachment_SHA256_Hash_Meta_BoxTest extends TestCase {

	/**
	 * Create instance without calling constructor.
	 *
	 * @return Attachment_SHA256_Hash_Meta_Box
	 */
	private function create_instance_without_constructor() {
		$reflection = new ReflectionClass( Attachment_SHA256_Hash_Meta_Box::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Test add_attachment_sha256_hash_meta_box method.
	 */
	public function test_add_attachment_sha256_hash_meta_box() {
		$meta_box = $this->create_instance_without_constructor();

		// Mock add_meta_box function.
		\Brain\Monkey\Functions\expect( 'add_meta_box' )
			->once()
			->with(
				'attachment_sha256_hash_meta_box',
				'SHA256 Hash',
				Mockery::type( 'array' ),
				'attachment',
				'side',
				'default'
			);

		$meta_box->add_attachment_sha256_hash_meta_box();
	}

	/**
	 * Test display_attachment_sha256_hash_meta_box with existing hash.
	 */
	public function test_display_attachment_sha256_hash_meta_box_with_hash() {
		$meta_box = $this->create_instance_without_constructor();

		$post           = new \stdClass();
		$post->ID       = 123;
		$expected_hash  = 'abc123def456';

		// Mock get_post_meta to return a hash.
		\Brain\Monkey\Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'sha256_hash', true )
			->andReturn( $expected_hash );

		// Mock esc_attr.
		\Brain\Monkey\Functions\expect( 'esc_attr' )
			->once()
			->with( $expected_hash )
			->andReturn( $expected_hash );

		ob_start();
		$meta_box->display_attachment_sha256_hash_meta_box( $post );
		$output = ob_get_clean();

		// Verify the input field is present with the hash value.
		$this->assertStringContainsString( '<input type="text"', $output );
		$this->assertStringContainsString( 'value="' . $expected_hash . '"', $output );
		$this->assertStringContainsString( 'readonly="readonly"', $output );

		// Verify the generate button is NOT present (hash exists).
		$this->assertStringNotContainsString( 'id="generate_sha256_hash"', $output );
		$this->assertStringNotContainsString( 'Generate SHA256 Hash', $output );
	}

	/**
	 * Test display_attachment_sha256_hash_meta_box without hash.
	 */
	public function test_display_attachment_sha256_hash_meta_box_without_hash() {
		$meta_box = $this->create_instance_without_constructor();

		$post     = new \stdClass();
		$post->ID = 456;

		// Mock get_post_meta to return empty string.
		\Brain\Monkey\Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, 'sha256_hash', true )
			->andReturn( '' );

		// Mock esc_attr.
		\Brain\Monkey\Functions\expect( 'esc_attr' )
			->once()
			->with( '' )
			->andReturn( '' );

		// Mock admin_url for AJAX URL.
		\Brain\Monkey\Functions\expect( 'admin_url' )
			->once()
			->with( 'admin-ajax.php' )
			->andReturn( 'http://example.com/wp-admin/admin-ajax.php' );

		// Mock esc_js for AJAX URL and post ID.
		\Brain\Monkey\Functions\expect( 'esc_js' )
			->twice()
			->andReturnUsing(
				function ( $arg ) {
					return $arg;
				}
			);

		ob_start();
		$meta_box->display_attachment_sha256_hash_meta_box( $post );
		$output = ob_get_clean();

		// Verify the input field is present (empty).
		$this->assertStringContainsString( '<input type="text"', $output );
		$this->assertStringContainsString( 'value=""', $output );
		$this->assertStringContainsString( 'readonly="readonly"', $output );

		// Verify the generate button IS present (no hash).
		$this->assertStringContainsString( 'id="generate_sha256_hash"', $output );
		$this->assertStringContainsString( 'Generate SHA256 Hash', $output );

		// Verify AJAX script is present.
		$this->assertStringContainsString( 'jQuery(document).ready', $output );
		$this->assertStringContainsString( 'action: \'generate_sha256_hash\'', $output );
		$this->assertStringContainsString( 'post_id: 456', $output );
	}
}
