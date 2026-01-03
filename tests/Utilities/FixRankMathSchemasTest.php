<?php
/**
 * Tests for FixRankMathSchemas class
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Utilities;

use FAToolkit\Tests\TestCase;
use FAToolkit\Utilities\FixRankMathSchemas;
use Brain\Monkey\Functions;

/**
 * Test case for FixRankMathSchemas WP-CLI command.
 */
class FixRankMathSchemasTest extends TestCase {
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

		new FixRankMathSchemas();

		$add_command_calls = \WP_CLI::get_calls( 'add_command' );
		// The class is instantiated in the source file, so we expect at least one call.
		$this->assertGreaterThanOrEqual( 1, count( $add_command_calls ) );

		// Verify the correct command was registered.
		$found = false;
		foreach ( $add_command_calls as $call ) {
			if ( $call['args'][0] === 'fa:utilities fix-rank-math-schemas' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Command fa:utilities fix-rank-math-schemas was not registered' );
	}

	/**
	 * Test fix_schemas method with products found.
	 *
	 * @return void
	 */
	public function test_fix_schemas_with_products() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		global $wpdb;

		// Mock wpdb.
		$wpdb           = \Mockery::mock( '\wpdb' );
		$wpdb->postmeta = 'wp_postmeta';

		$product_ids = array( 101, 102, 103 );

		// Mock wpdb->prepare.
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				'rank_math_schema_Off'
			)
			->andReturn( 'PREPARED_QUERY' );

		// Mock wpdb->get_col to return product IDs.
		$wpdb->shouldReceive( 'get_col' )
			->once()
			->with( 'PREPARED_QUERY' )
			->andReturn( $product_ids );

		// Mock wpdb->prepare for delete_schema.
		$wpdb->shouldReceive( 'prepare' )
			->times( 3 )
			->with( \Mockery::type( 'string' ), \Mockery::type( 'int' ), \Mockery::type( 'string' ) )
			->andReturn( 'DELETE_PREPARED_QUERY' );

		// Mock wpdb->esc_like.
		$wpdb->shouldReceive( 'esc_like' )
			->times( 3 )
			->with( 'rank_math_schema_' )
			->andReturn( 'rank_math_schema_' );

		// Mock wpdb->query for delete operations.
		$wpdb->shouldReceive( 'query' )
			->times( 3 )
			->with( \Mockery::type( 'string' ) )
			->andReturn( 1 ); // Success.

		// Mock delete_post_meta.
		Functions\expect( 'delete_post_meta' )
			->times( 3 )
			->with( \Mockery::type( 'int' ), 'rank_math_rich_snippet' )
			->andReturn( true );

		$fixer = new FixRankMathSchemas();
		$fixer->fix_schemas();

		$log_calls = \WP_CLI::get_calls( 'log' );
		$this->assertCount( 3, $log_calls );

		$success_calls = \WP_CLI::get_calls( 'success' );
		$this->assertCount( 1, $success_calls );
		$this->assertStringContainsString( '3 out of 3', $success_calls[0]['args'][0] );
	}

	/**
	 * Test delete_schema method returns true on success.
	 *
	 * @return void
	 */
	public function test_delete_schema_returns_true_on_success() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		global $wpdb;

		$wpdb           = \Mockery::mock( '\wpdb' );
		$wpdb->postmeta = 'wp_postmeta';

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( \Mockery::type( 'string' ), 123, \Mockery::type( 'string' ) )
			->andReturn( 'DELETE_QUERY' );

		$wpdb->shouldReceive( 'esc_like' )
			->once()
			->with( 'rank_math_schema_' )
			->andReturn( 'rank_math_schema_' );

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 1 ); // Success (number of rows affected).

		$fixer  = new FixRankMathSchemas();
		$result = $fixer->delete_schema( 123 );

		$this->assertTrue( $result );
	}

	/**
	 * Test delete_schema method returns false on failure.
	 *
	 * @return void
	 */
	public function test_delete_schema_returns_false_on_failure() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		global $wpdb;

		$wpdb           = \Mockery::mock( '\wpdb' );
		$wpdb->postmeta = 'wp_postmeta';

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'DELETE_QUERY' );

		$wpdb->shouldReceive( 'esc_like' )
			->once()
			->andReturn( 'rank_math_schema_' );

		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( false ); // Failure.

		$fixer  = new FixRankMathSchemas();
		$result = $fixer->delete_schema( 456 );

		$this->assertFalse( $result );
	}
}
