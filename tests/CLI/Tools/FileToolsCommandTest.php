<?php
/**
 * Tests for FileToolsCommand.
 *
 * @package FAToolkit\Tests\CLI\Tools
 */

namespace FAToolkit\Tests\CLI\Tools;

use FAToolkit\CLI\Tools\FileToolsCommand;
use FAToolkit\Tests\TestCase;

/**
 * FileToolsCommand: `wp fa:tools merge-files|sort-csv-by-column|sort-tsv-by-column`.
 *
 * These commands read and rewrite real files, so each test works in its own
 * temporary directory and the process is chdir'd there because merge-files
 * writes `merged_file.csv` relative to the working directory. Issue #24.
 */
class FileToolsCommandTest extends TestCase {

	/**
	 * Working directory before the test chdir'd away.
	 *
	 * @var string
	 */
	private $original_cwd;

	/**
	 * Per-test scratch directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Create a scratch directory and move into it.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
		$this->original_cwd = getcwd();
		$this->dir          = sys_get_temp_dir() . '/fa-filetools-' . uniqid();
		mkdir( $this->dir );
		chdir( $this->dir );
	}

	/**
	 * Return to the original directory and remove the scratch files.
	 */
	protected function tearDown(): void {
		chdir( $this->original_cwd );
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * Write a scratch file and return its path.
	 *
	 * @param string $name    File name.
	 * @param string $content File body.
	 * @return string
	 */
	private function file( $name, $content ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * Read a file back as rows of parsed CSV/TSV.
	 *
	 * @param string $path      File path.
	 * @param string $separator Field separator.
	 * @return array
	 */
	private function rows( $path, $separator = ',' ) {
		$rows = array();
		foreach ( file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$rows[] = str_getcsv( $line, $separator, '"', '\\' );
		}
		return $rows;
	}

	/**
	 * The constructor registers all three commands on this instance.
	 */
	public function test_constructor_registers_three_commands() {
		$tools = new FileToolsCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertSame(
			array(
				array( 'fa:tools merge-files', array( $tools, 'merge_files' ) ),
				array( 'fa:tools sort-csv-by-column', array( $tools, 'sort_csv_by_column' ) ),
				array( 'fa:tools sort-tsv-by-column', array( $tools, 'sort_tsv_by_column' ) ),
			),
			array_column( $calls, 'args' )
		);
	}

	/**
	 * merge-files refuses to run with fewer than three positional arguments.
	 */
	public function test_merge_files_errors_without_three_arguments() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Missing arguments' );

		( new FileToolsCommand() )->merge_files( array( 'a.csv', 'b.tsv' ), array() );
	}

	/**
	 * merge-files appends the TSV's RETAIL-MAP value to each matching CSV row,
	 * keyed on the named column, and writes merged_file.csv in the cwd.
	 *
	 * The full file is pinned, defects included, so that the fix for #91
	 * rewrites exactly this expected array. Today the command prepends the
	 * RETAIL-MAP header while appending the values (columns misaligned), and
	 * then dumps every TSV row, header included, onto the end of the file.
	 */
	public function test_merge_files_appends_retail_map_keyed_on_column() {
		$csv = $this->file( 'products.csv', "Item #,Name\nA1,Widget\nB2,Gadget\nC3,Unmatched\n" );
		$tsv = $this->file( 'prices.tsv', "\n\nItem #\tRETAIL-MAP\nB2\t20.00\nA1\t10.00\n" );

		( new FileToolsCommand() )->merge_files( array( $csv, $tsv, 'Item #' ), array() );

		$this->assertFileExists( $this->dir . '/merged_file.csv' );
		$this->assertSame(
			array(
				array( 'RETAIL-MAP', 'Item #', 'Name' ),
				array( 'A1', 'Widget', '10.00' ),
				array( 'B2', 'Gadget', '20.00' ),
				array( 'C3', 'Unmatched' ),
				array( 'Item #', 'RETAIL-MAP', 'B2', '20.00' ),
				array( 'A1', '10.00' ),
			),
			$this->rows( $this->dir . '/merged_file.csv' ),
			'Pinned current output; see #91 for the two defects this encodes.'
		);

		$success = \WP_CLI::get_calls( 'success' );
		$this->assertCount( 1, $success );
		$this->assertStringContainsString( 'merged_file.csv', $success[0]['args'][0] );
	}

	/**
	 * sort-csv-by-column refuses to run with fewer than two positional arguments.
	 */
	public function test_sort_csv_by_column_errors_without_two_arguments() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Missing arguments' );

		( new FileToolsCommand() )->sort_csv_by_column( array( 'a.csv' ), array() );
	}

	/**
	 * sort-csv-by-column rewrites the file in place, header first, rows
	 * ascending by the chosen column.
	 */
	public function test_sort_csv_by_column_sorts_rows_in_place_keeping_header() {
		$csv = $this->file( 'data.csv', "sku,name,qty\nc,Charlie,3\na,Alpha,1\nb,Bravo,2\n" );

		( new FileToolsCommand() )->sort_csv_by_column( array( $csv, 1 ), array() );

		$this->assertSame(
			array(
				array( 'sku', 'name', 'qty' ),
				array( 'a', 'Alpha', '1' ),
				array( 'b', 'Bravo', '2' ),
				array( 'c', 'Charlie', '3' ),
			),
			$this->rows( $csv )
		);
		$this->assertStringContainsString( 'by column 1', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
	}

	/**
	 * sort-tsv-by-column refuses to run with fewer than two positional arguments.
	 */
	public function test_sort_tsv_by_column_errors_without_two_arguments() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Missing arguments' );

		( new FileToolsCommand() )->sort_tsv_by_column( array( 'a.tsv' ), array() );
	}

	/**
	 * sort-tsv-by-column skips blank lines before the header, drops blank
	 * data lines, and rewrites the file sorted by the chosen column.
	 */
	public function test_sort_tsv_by_column_skips_blank_lines_and_sorts_in_place() {
		$tsv = $this->file( 'data.tsv', "\n\nsku\tprice\nz\t9\n\na\t1\nm\t5\n" );

		( new FileToolsCommand() )->sort_tsv_by_column( array( $tsv, 0 ), array() );

		$this->assertSame(
			array(
				array( 'sku', 'price' ),
				array( 'a', '1' ),
				array( 'm', '5' ),
				array( 'z', '9' ),
			),
			$this->rows( $tsv, "\t" )
		);
		$this->assertStringStartsNotWith( "\n", file_get_contents( $tsv ), 'Leading blank lines are not written back.' );
	}
}
