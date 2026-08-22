<?php
/**
 * CLI commands for file manipulation utilities.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\CLI\Tools;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Collection of file manipulation utilities for CSV/TSV processing.
 */
class FileToolsCommand {

	/**
	 * Constructor - Register WP-CLI commands.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fa:tools merge-files', array( $this, 'merge_files' ) );
			\WP_CLI::add_command( 'fa:tools sort-csv-by-column', array( $this, 'sort_csv_by_column' ) );
			\WP_CLI::add_command( 'fa:tools sort-tsv-by-column', array( $this, 'sort_tsv_by_column' ) );
		}
	}

	/**
	 * Merge the RETAIL-MAP column in a TSV file into a CSV file keyed by a column.
	 *
	 * ## OPTIONS
	 *
	 * <csv>
	 * : Path to the CSV file.
	 *
	 * <tsv>
	 * : Path to the TSV file.
	 *
	 * <column>
	 * : Column name to use as the key for merging.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:tools merge-files products.csv prices.tsv "Item #"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function merge_files( $args, $assoc_args ) {
		if ( count( $args ) < 3 ) {
			\WP_CLI::error( 'Missing arguments. Usage: wp fa:tools merge-files <csv> <tsv> <column>' );
		}

		list( $csv_file, $tsv_file, $id_column ) = $args;

		$csv_data = array_map( 'str_getcsv', file( $csv_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
		$tsv_data = array_map(
			'str_getcsv',
			file( $tsv_file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
			array_fill( 0, count( file( $tsv_file ) ), "\t" ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
		);

		// Skip blank lines before headers in TSV file.
		$tsv_data = array_filter(
			$tsv_data,
			function ( $row ) {
				return ! empty( $row[0] );
			}
		);

		$header = array_shift( $csv_data );
		array_shift( $tsv_data );

		$csv_index = array_search( $id_column, $header, true );
		$tsv_index = array_search( $id_column, $tsv_data[0], true );

		foreach ( $csv_data as &$row ) {
			$id = $row[ $csv_index ];
			foreach ( $tsv_data as $tsv_row ) {
				if ( $tsv_row[ $tsv_index ] === $id ) {
					$row[] = $tsv_row[ $tsv_index + 1 ];
					break;
				}
			}
		}
		unset( $row );

		array_unshift( $header, 'RETAIL-MAP' );
		array_unshift( $tsv_data[0], $id_column, 'RETAIL-MAP' );

		$merged_data = array_merge( array( $header ), $csv_data );
		$merged_data = array_merge( $merged_data, $tsv_data );

		$merged_file = fopen( 'merged_file.csv', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		foreach ( $merged_data as $row ) {
			fputcsv( $merged_file, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $merged_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		\WP_CLI::success( 'Files merged to merged_file.csv' );
	}

	/**
	 * Sort a CSV file by the provided column index.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * <column>
	 * : Column index to sort by (0-based).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:tools sort-csv-by-column products.csv 2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sort_csv_by_column( $args, $assoc_args ) {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( 'Missing arguments. Usage: wp fa:tools sort-csv-by-column <file> <column>' );
		}

		list( $file_path, $column_index ) = $args;

		$file    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$headers = fgetcsv( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		$data    = array();

		while ( ( $row = fgetcsv( $file ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$data[] = $row;
		}
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		array_multisort( array_column( $data, $column_index ), SORT_ASC, $data );

		$file = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $file, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( $data as $row ) {
			fputcsv( $file, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		\WP_CLI::success( "Sorted {$file_path} by column {$column_index}" );
	}

	/**
	 * Sort a TSV file by the provided column index.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the TSV file.
	 *
	 * <column>
	 * : Column index to sort by (0-based).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:tools sort-tsv-by-column products.tsv 2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sort_tsv_by_column( $args, $assoc_args ) {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( 'Missing arguments. Usage: wp fa:tools sort-tsv-by-column <file> <column>' );
		}

		list( $file_path, $column_index ) = $args;

		$file    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$headers = array();

		// Skip any extra lines before the headers.
		while ( ( $row = fgetcsv( $file, 0, "\t" ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! empty( $row[0] ) ) {
				$headers = $row;
				break;
			}
		}

		// Read the TSV data into an array.
		$data = array();
		while ( ( $row = fgetcsv( $file, 0, "\t" ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! empty( $row[0] ) ) {
				$data[] = $row;
			}
		}
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		array_multisort( array_column( $data, $column_index ), SORT_ASC, $data );

		$file = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! empty( $headers ) ) {
			fputcsv( $file, $headers, "\t" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		foreach ( $data as $row ) {
			fputcsv( $file, $row, "\t" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		\WP_CLI::success( "Sorted {$file_path} by column {$column_index}" );
	}
}
