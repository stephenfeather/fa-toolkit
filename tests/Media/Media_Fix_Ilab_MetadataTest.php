<?php
/**
 * Tests for Media_Fix_Ilab_Metadata class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Media;

use FAToolkit\Tests\TestCase;
use FAToolkit\Media\Media_Fix_Ilab_Metadata;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test Media_Fix_Ilab_Metadata functionality.
 *
 * @coversDefaultClass \FAToolkit\Media\Media_Fix_Ilab_Metadata
 */
class Media_Fix_Ilab_MetadataTest extends TestCase {

	/**
	 * Instance of the class under test.
	 *
	 * @var Media_Fix_Ilab_Metadata
	 */
	private $instance;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new Media_Fix_Ilab_Metadata();
	}

	/**
	 * Test constructor registers WP-CLI commands.
	 *
	 * @covers ::__construct
	 */
	public function test_constructor_registers_commands() {
		\WP_CLI::$calls = [];

		new Media_Fix_Ilab_Metadata();

		$this->assertCount( 2, \WP_CLI::$calls );
		$this->assertEquals( 'add_command', \WP_CLI::$calls[0]['method'] );
		$this->assertEquals( 'fa:media fix-media-metadata', \WP_CLI::$calls[0]['args'][0] );
		$this->assertEquals( 'add_command', \WP_CLI::$calls[1]['method'] );
		$this->assertEquals( 'fa:media fix-all-media-metadata', \WP_CLI::$calls[1]['args'][0] );
	}

	/**
	 * Test fix_media_metadata errors on invalid post ID.
	 *
	 * @covers ::fix_media_metadata
	 */
	public function test_fix_media_metadata_invalid_post_id() {
		\WP_CLI::$calls = [];

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'valid post ID' );

		$this->instance->fix_media_metadata( [ '0' ], [] );
	}

	/**
	 * Test fix_media_metadata errors when post doesn't exist.
	 *
	 * @covers ::fix_media_metadata
	 */
	public function test_fix_media_metadata_post_not_exists() {
		\WP_CLI::$calls = [];

		Functions\expect( 'post_exists' )
			->once()
			->with( 123 )
			->andReturn( false );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( "doesn't exist" );

		$this->instance->fix_media_metadata( [ '123' ], [] );
	}

	/**
	 * Test fix_media_metadata success.
	 *
	 * @covers ::fix_media_metadata
	 */
	public function test_fix_media_metadata_success() {
		\WP_CLI::$calls = [];

		Functions\expect( 'post_exists' )
			->once()
			->with( 123 )
			->andReturn( true );

		// Mock StorageUtilities.
		$storage_utilities = Mockery::mock( 'overload:MediaCloud\Plugin\Tools\Storage\StorageUtilities' );
		$storage_utilities->shouldReceive( 'fixMetadata' )
			->once()
			->with( 123 )
			->andReturn( true );

		$this->instance->fix_media_metadata( [ '123' ], [] );

		$success_calls = array_filter( \WP_CLI::$calls, function( $call ) {
			return 'success' === $call['method'];
		} );

		$this->assertCount( 1, $success_calls );
		$this->assertStringContainsString( 'Metadata fixed for post ID: 123', reset( $success_calls )['args'][0] );
	}

	/**
	 * Test fix_media_metadata handles exceptions.
	 *
	 * @covers ::fix_media_metadata
	 */
	public function test_fix_media_metadata_handles_exception() {
		\WP_CLI::$calls = [];

		Functions\expect( 'post_exists' )
			->once()
			->with( 123 )
			->andReturn( true );

		// Mock StorageUtilities throwing exception.
		$storage_utilities = Mockery::mock( 'overload:MediaCloud\Plugin\Tools\Storage\StorageUtilities' );
		$storage_utilities->shouldReceive( 'fixMetadata' )
			->once()
			->with( 123 )
			->andThrow( new \Exception( 'Test error' ) );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Test error' );

		$this->instance->fix_media_metadata( [ '123' ], [] );
	}

	/**
	 * Test fix_all_media_metadata processes attachments.
	 *
	 * @covers ::fix_all_media_metadata
	 */
	public function test_fix_all_media_metadata_processes_attachments() {
		\WP_CLI::$calls = [];

		Functions\expect( 'get_option' )
			->once()
			->with( 'fa_toolkit_last_processed_post_id' )
			->andReturn( 100 );

		Functions\expect( 'get_posts' )
			->once()
			->with( Mockery::on( function( $args ) {
				return 'attachment' === $args['post_type']
					&& 'ids' === $args['fields'];
			} ) )
			->andReturn( [ 101, 102 ] );

		// Mock StorageUtilities.
		$storage_utilities = Mockery::mock( 'overload:MediaCloud\Plugin\Tools\Storage\StorageUtilities' );
		$storage_utilities->shouldReceive( 'fixMetadata' )
			->twice()
			->andReturn( true );

		Functions\expect( 'update_option' )
			->twice()
			->with( 'fa_toolkit_last_processed_post_id', Mockery::anyOf( 101, 102 ) );

		// A delete_option() expectation used to sit here, with a note about an
		// "undefined $option_name on line 140" in the source. Both are stale:
		// fix_all_media_metadata() ends at line 131, calls delete_option()
		// nowhere, and the file has no line 140. The expectation described a
		// version of the source that no longer exists, so it could only ever
		// fail.

		$this->instance->fix_all_media_metadata( [ '100' ], [] );

		$success_calls = array_filter( \WP_CLI::$calls, function( $call ) {
			return 'success' === $call['method'];
		} );

		$this->assertCount( 2, $success_calls );
	}

	/**
	 * Test fix_all_media_metadata handles failures.
	 *
	 * @covers ::fix_all_media_metadata
	 */
	public function test_fix_all_media_metadata_handles_failures() {
		\WP_CLI::$calls = [];

		Functions\expect( 'get_option' )
			->once()
			->andReturn( 100 );

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( [ 101 ] );

		// Mock StorageUtilities returning false (failure).
		$storage_utilities = Mockery::mock( 'overload:MediaCloud\Plugin\Tools\Storage\StorageUtilities' );
		$storage_utilities->shouldReceive( 'fixMetadata' )
			->once()
			->with( 101 )
			->andReturn( false );

		Functions\expect( 'update_option' )
			->once();

		// Stale delete_option() expectation removed - see the note in
		// test_fix_all_media_metadata_processes_attachments above.

		$this->instance->fix_all_media_metadata( [ '100' ], [] );

		$warning_calls = array_filter( \WP_CLI::$calls, function( $call ) {
			return 'warning' === $call['method'];
		} );

		$this->assertCount( 1, $warning_calls );
		$this->assertStringContainsString( 'Failed to fix metadata', reset( $warning_calls )['args'][0] );
	}
}
