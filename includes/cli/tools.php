<?php
/**
 * Unfinished collection of minor utilities.
 *
 * @package FA-Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

if ( ! function_exists( 'wp_cli_merge_files' ) ) {

	/**
	 * Merge the RETAIL-MAP column in a tsv file into a csv file keyed by a column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fa:tools merge-files <csv> <tsv> <column>
	 *
	 * @when   after_wp_load
	 * @param  array $args Arguments passed to the WP-CLI command.
	 * @param  array $assoc_args Associated arguments passed to the WP-CLI command.
	 * @return void
	 */
	function wp_cli_merge_files( $args, $assoc_args ) {
		list( $csv_file, $tsv_file, $id_column) = $args;
		if ( ! $csv_file && ! $tsv_file && ! $id_column ) {
			WP_CLI::error( 'Missing arguments.' );
		}
		$csv_data = array_map( 'str_getcsv', file( $csv_file ) );
		$tsv_data = array_map( 'str_getcsv', file( $tsv_file ), array_fill( 0, count( file( $tsv_file ) ), "\t" ) );

		// Csv Header: Item #
		// Tsv Header: "ITEM NO".

		// skip blank lines before headers in TSV file.
		$tsv_data = array_filter(
			$tsv_data,
			function( $row ) {
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

		array_unshift( $header, 'RETAIL-MAP' );
		array_unshift( $tsv_data[0], $id_column, 'RETAIL-MAP' );

		$merged_data = array_merge( array( $header ), $csv_data );
		$merged_data = array_merge( $merged_data, $tsv_data );

		$merged_file = fopen( 'merged_file.csv', 'w' );
		foreach ( $merged_data as $row ) {
			fputcsv( $merged_file, $row );
		}
		fclose( $merged_file );
	}
	WP_CLI::add_command( 'fa:tools merge-files', 'wp_cli_merge_files' );
}

if ( ! function_exists( 'wp_cli_sort_csv_by_column' ) ) {
	function wp_cli_sort_csv_by_column( $args, $assoc_args ) {
		list( $file_path, $column_index ) = $args;
		// Open the CSV file for reading.
		$file = fopen( $file_path, 'r' );

		// Read the CSV data into an array.
		$data    = array();
		$headers = fgetcsv( $file ); // First row is headers.
		while ( false !== ( fgetcsv( $file ) === $row ) ) {
			$data[] = $row;
		}

		// Sort the data array by the specified column.

		array_multisort( array_column( $data, $column_index ), SORT_ASC, $data );

		// Write the sorted data array back to the CSV file.
		$file = fopen( $file_path, 'w' );
		fputcsv( $file, $headers ); // Write headers.
		foreach ( $data as $row ) {
			fputcsv( $file, $row );
		}
		fclose( $file );
	}
	WP_CLI::add_command( 'fa:tools sort-csv-by-column', 'wp_cli_sort_csv_by_column' );
}

if ( ! function_exists( 'wp_cli_sort_tsv_by_column' ) ) {
	function wp_cli_sort_tsv_by_column( $args, $assoc_args ) {
		list( $file_path, $column_index ) = $args;
		// Open the TSV file for reading.
		$file = fopen( $file_path, 'r' );

		// Skip any extra lines before the headers.
		$headers = array();
		while ( false !== ( fgetcsv( $file, 0, "\t" ) === $row ) ) {
			if ( ! empty( $row ) ) {
				$headers = $row;
				break;
			}
		}

		// Read the TSV data into an array.
		$data = array();
		while ( false !== ( fgetcsv( $file, 0, "\t" ) === $row ) ) {
			if ( ! empty( $row ) ) {
				$data[] = $row;
			}
		}

		// Sort the data array by the specified column.
		$intermediate_array = array_column( $data, $column_index );

		array_multisort( $intermediate_array, SORT_ASC, $data );

		// Write the sorted data array back to the TSV file.
		$file = fopen( $file_path, 'w' );
		if ( ! empty( $headers ) ) {
			fputcsv( $file, $headers, "\t" ); // Write headers.
		}
		foreach ( $data as $row ) {
			fputcsv( $file, $row, "\t" );
		}
		fclose( $file );
	}
	WP_CLI::add_command( 'fa:tools sort-tsv-by-column', 'wp_cli_sort_tsv_by_column' );
}
