<?php
/**
 * Tests for ExportACFField.
 *
 * @package FAToolkit\Tests\CLI\Tools
 */

namespace FAToolkit\Tests\CLI\Tools;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Tools\ExportACFField;
use FAToolkit\Tests\TestCase;

/**
 * ExportACFField: `wp fa:tools export-acf-field <postmeta key>`.
 *
 * The command keeps its historical name; since #77 it reads plain postmeta.
 * Issue #24.
 */
class ExportACFFieldTest extends TestCase {

	/**
	 * Scratch upload directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Create the scratch upload directory.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		$this->dir = sys_get_temp_dir() . '/fa-export-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Remove the scratch upload directory.
	 */
	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * A product post stub with an ID.
	 *
	 * @param int $id Post ID.
	 * @return object
	 */
	private function post( $id ) {
		$post     = new \stdClass();
		$post->ID = $id;
		return $post;
	}

	/**
	 * Stub the progress bar and return the recorder.
	 *
	 * @return object Records tick() and finish() counts.
	 */
	private function stub_progress_bar() {
		$progress         = new class() {
			/**
			 * Tick count.
			 *
			 * @var int
			 */
			public $ticks = 0;
			/**
			 * Finish count.
			 *
			 * @var int
			 */
			public $finished = 0;
			/**
			 * Advance.
			 */
			public function tick() {
				++$this->ticks;
			}
			/**
			 * Finish.
			 */
			public function finish() {
				++$this->finished;
			}
		};
		Functions\when( 'WP_CLI\Utils\make_progress_bar' )->justReturn( $progress );
		return $progress;
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$export = new ExportACFField();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:tools export-acf-field', $calls[0]['args'][0] );
		$this->assertSame( array( $export, 'export_fields' ), $calls[0]['args'][1] );
	}

	/**
	 * Products with a value for the key are written to a CSV in the upload
	 * directory; products without one are skipped; the progress bar ticks once
	 * per product.
	 */
	public function test_export_fields_writes_non_empty_values_to_upload_dir() {
		$progress = $this->stub_progress_bar();
		Functions\expect( 'get_posts' )
			->once()
			->with(
				array(
					'post_type'      => 'product',
					'posts_per_page' => -1,
					'order'          => 'DESC',
					'orderby'        => 'ID',
				)
			)
			->andReturn( array( $this->post( 30 ), $this->post( 20 ), $this->post( 10 ) ) );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				$this->assertSame( '_global_unique_id', $key );
				return array(
					30 => '00012345678905',
					20 => '',
					10 => '00098765432109',
				)[ $id ];
			}
		);
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'path' => $this->dir ) );

		( new ExportACFField() )->export_fields( array( '_global_unique_id' ), array() );

		$files = glob( $this->dir . '/acf_field_export__global_unique_id_*.csv' );
		$this->assertCount( 1, $files );
		$rows = array_map( 'str_getcsv', file( $files[0], FILE_IGNORE_NEW_LINES ) );
		$this->assertSame(
			array(
				array( 'Product ID', 'ACF Field Value' ),
				array( '30', '00012345678905' ),
				array( '10', '00098765432109' ),
			),
			$rows
		);

		$this->assertSame( 3, $progress->ticks );
		$this->assertSame( 1, $progress->finished );
		$this->assertStringContainsString( $files[0], \WP_CLI::get_calls( 'line' )[0]['args'][0] );
		$this->assertCount( 1, \WP_CLI::get_calls( 'success' ) );
	}

	/**
	 * With no products the CSV still carries its header row and nothing else.
	 */
	public function test_export_fields_with_no_products_writes_header_only() {
		$this->stub_progress_bar();
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\expect( 'get_post_meta' )->never();
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'path' => $this->dir ) );

		( new ExportACFField() )->export_fields( array( 'any_key' ), array() );

		$files = glob( $this->dir . '/acf_field_export_any_key_*.csv' );
		$this->assertCount( 1, $files );
		$this->assertSame( "\"Product ID\",\"ACF Field Value\"\n", file_get_contents( $files[0] ) );
	}
}
