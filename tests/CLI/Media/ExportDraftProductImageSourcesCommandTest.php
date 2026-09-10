<?php
/**
 * Tests for ExportDraftProductImageSourcesCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\ExportDraftProductImageSourcesCommand;
use FAToolkit\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * ExportDraftProductImageSourcesCommand: `wp fa:media export-draft-product-image-sources [<file>]`.
 *
 * Expected to retire under the image-intake contract; source untouched.
 * The command gates on `class_exists( 'acf' )`. The ACF-absent test runs in
 * the shared process, where no such class exists. The ACF-present tests load
 * tests/fixtures/class-acf-stub.php and run in isolation so the class never
 * leaks into other tests. Issue #24.
 */
class ExportDraftProductImageSourcesCommandTest extends TestCase {

	/**
	 * Mocked $wp_filesystem.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $filesystem;

	/**
	 * Reset the recorder and stub the filesystem bootstrap.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$this->filesystem         = Mockery::mock( 'WP_Filesystem_for_test' );
		$GLOBALS['wp_filesystem'] = $this->filesystem;
	}

	/**
	 * Drop the filesystem global.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		parent::tearDown();
	}

	/**
	 * Load the ACF marker class for tests that need to pass the gate.
	 */
	private function with_acf_present() {
		require_once dirname( __DIR__, 2 ) . '/fixtures/class-acf-stub.php';
	}

	/**
	 * The draft-product query the command issues.
	 *
	 * @return array
	 */
	private function draft_query() {
		return array(
			'post_type'      => 'product',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new ExportDraftProductImageSourcesCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media export-draft-product-image-sources', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'execute' ), $calls[0]['args'][1] );
	}

	/**
	 * Without ACF the command stops before querying anything.
	 */
	public function test_execute_errors_when_acf_is_absent() {
		$this->assertFalse( class_exists( 'acf', false ), 'Precondition: the ACF stub must not be loaded in this process.' );
		Functions\expect( 'get_posts' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Advanced Custom Fields is not installed or active.' );

		( new ExportDraftProductImageSourcesCommand() )->execute( array(), array() );
	}

	/**
	 * Non-empty image sources are written one per line to the default file.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_execute_writes_non_empty_sources_to_default_file() {
		$this->with_acf_present();
		Functions\expect( 'get_posts' )->once()->with( $this->draft_query() )->andReturn( array( 1, 2, 3 ) );
		Functions\when( 'get_field' )->alias(
			fn( $field, $id ) => array(
				1 => 'https://a.test/1.jpg',
				2 => '',
				3 => 'https://a.test/3.jpg',
			)[ $id ]
		);
		Functions\expect( 'wp_reset_postdata' )->once();
		$this->filesystem->shouldReceive( 'put_contents' )
			->once()
			->with( 'draft-product-image-sources.txt', "https://a.test/1.jpg\nhttps://a.test/3.jpg\n" )
			->andReturn( true );

		( new ExportDraftProductImageSourcesCommand() )->execute( array(), array() );

		$this->assertSame(
			'Draft product image sources exported to draft-product-image-sources.txt.',
			\WP_CLI::get_calls( 'success' )[0]['args'][0]
		);
	}

	/**
	 * A positional argument names the output file, and a failed write is an error.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_execute_errors_when_the_write_fails() {
		$this->with_acf_present();
		Functions\when( 'get_posts' )->justReturn( array( 1 ) );
		Functions\when( 'get_field' )->justReturn( 'https://a.test/1.jpg' );
		$this->filesystem->shouldReceive( 'put_contents' )
			->once()
			->with( 'images.txt', "https://a.test/1.jpg\n" )
			->andReturn( false );
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Error exporting draft product image sources to images.txt.' );

		( new ExportDraftProductImageSourcesCommand() )->execute( array( 'images.txt' ), array() );
	}

	/**
	 * With nothing to export, no file is written and the run is an error.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_execute_errors_when_no_source_is_found() {
		$this->with_acf_present();
		Functions\when( 'get_posts' )->justReturn( array( 1, 2 ) );
		Functions\when( 'get_field' )->justReturn( '' );
		$this->filesystem->shouldReceive( 'put_contents' )->never();
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'No draft product image sources found.' );

		( new ExportDraftProductImageSourcesCommand() )->execute( array(), array() );
	}
}
