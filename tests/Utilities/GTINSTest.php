<?php
/**
 * Tests for GTINS class
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Utilities;

use FAToolkit\Tests\TestCase;
use FAToolkit\Utilities\GTINS;
use Brain\Monkey\Functions;

/**
 * Test case for GTINS WP-CLI command.
 */
class GTINSTest extends TestCase {
	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
	}

	/**
	 * Test constructor registers WP-CLI command.
	 *
	 * @return void
	 */
	public function test_constructor_registers_command() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		new GTINS();

		$add_command_calls = \WP_CLI::get_calls( 'add_command' );
		// The class is instantiated in the source file, so we expect at least one call.
		$this->assertGreaterThanOrEqual( 1, count( $add_command_calls ) );

		// Verify the correct command was registered.
		$found = false;
		foreach ( $add_command_calls as $call ) {
			if ( $call['args'][0] === 'fa:utilities populateGTINS' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Command fa:utilities populateGTINS was not registered' );
	}

	/**
	 * Test populate method with valid category and products.
	 *
	 * @return void
	 */
	public function test_populate_with_valid_category_and_products() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$category_id = 123;
		$product_ids = array( 101, 102, 103 );

		// Mock get_posts.
		Functions\expect( 'get_posts' )
			->once()
			->with( \Mockery::type( 'array' ) )
			->andReturn( $product_ids );

		// Mock get_post_meta for each product.
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 101, 'upc_code', true )
			->andReturn( '123456789012' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 102, 'upc_code', true )
			->andReturn( '987654321098' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 103, 'upc_code', true )
			->andReturn( '' ); // Empty UPC code.

		// Mock update_post_meta - should only be called for products with UPC codes.
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 101, '_rank_math_gtin_code', '123456789012' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 102, '_rank_math_gtin_code', '987654321098' )
			->andReturn( true );

		$gtins = new GTINS();
		$gtins->populate( array( '123' ), array() );

		$success_calls = \WP_CLI::get_calls( 'success' );
		$this->assertCount( 1, $success_calls );
		$this->assertEquals( 'GTIN codes populated successfully.', $success_calls[0]['args'][0] );
	}

	/**
	 * Test populate method with invalid category ID (zero).
	 *
	 * @return void
	 */
	public function test_populate_with_invalid_category_id_zero() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$gtins = new GTINS();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid category ID.' );

		$gtins->populate( array( '0' ), array() );
	}

	/**
	 * Test populate method with missing category ID.
	 *
	 * @return void
	 */
	public function test_populate_with_missing_category_id() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$gtins = new GTINS();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid category ID.' );

		$gtins->populate( array(), array() );
	}

	/**
	 * Test populate method with no products in category.
	 *
	 * @return void
	 */
	public function test_populate_with_no_products_in_category() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() ); // No products.

		$gtins = new GTINS();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'No products found in the specified category.' );

		$gtins->populate( array( '456' ), array() );
	}

	/**
	 * Test populate method skips products with null UPC codes.
	 *
	 * @return void
	 */
	public function test_populate_skips_products_with_null_upc_codes() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( 301, 302 ) );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 301, 'upc_code', true )
			->andReturn( null );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 302, 'upc_code', true )
			->andReturn( false );

		// update_post_meta should NOT be called since both are empty.
		Functions\expect( 'update_post_meta' )
			->never();

		$gtins = new GTINS();
		$gtins->populate( array( '999' ), array() );

		$success_calls = \WP_CLI::get_calls( 'success' );
		$this->assertCount( 1, $success_calls );
	}
}
