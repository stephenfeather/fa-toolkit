<?php
/**
 * Pins the two placeholder-hash lists to each other and to their authority.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\CLI\Media;

use FAToolkit\CLI\Media\FetchImportProductImageCommand;
use FAToolkit\CLI\Media\ScrapeProductMedia;
use FAToolkit\Tests\TestCase;
use ReflectionClass;

/**
 * The lists are two copies of one upstream file (issue #110).
 *
 * featherarms-pipeline's CI asserts its list is a superset of both copies, so
 * a hash added to only one of them here fails a different repository's gates.
 * These tests fail here first.
 */
class PlaceholderHashListsTest extends TestCase {

	/**
	 * The upstream file both doc comments must name.
	 */
	private const AUTHORITY = 'featherarms-pipeline/src/featherarms_pipeline/stage2/placeholder-hashes.txt';

	/**
	 * The RSR soft-404 body, the hash issue #110 reported missing.
	 */
	private const RSR_SOFT_404 = '32455f50f3e20291850f8b00f17f3aa7ae52f0a5bffb79108888a6fc6c975f45';

	/**
	 * The fetch command's list.
	 *
	 * @return array<int, string>
	 */
	private function fetch_list() {
		return ( new ReflectionClass( FetchImportProductImageCommand::class ) )->getConstant( 'PLACEHOLDER_HASHES' );
	}

	/**
	 * The scraper's list.
	 *
	 * @return array<int, string>
	 */
	private function scrape_list() {
		return ( new ReflectionClass( ScrapeProductMedia::class ) )->getDefaultProperties()['known_placeholder_hashes'];
	}

	/**
	 * The hash-list lines of a class file, comments included.
	 *
	 * @param string $class_name Class whose file to read.
	 * @return array<int, string>
	 */
	private function list_lines( $class_name ) {
		$source = file_get_contents( ( new ReflectionClass( $class_name ) )->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match_all( "/^\s*'[0-9a-f]{64}',.*$/m", $source, $matches );

		return array_map( 'trim', $matches[0] );
	}

	/**
	 * The `Authority:` line of a class file.
	 *
	 * @param string $class_name Class whose file to read.
	 * @return string
	 */
	private function authority_line( $class_name ) {
		$source = file_get_contents( ( new ReflectionClass( $class_name ) )->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match( '/^\s*\* Authority: (.+)$/m', $source, $match );

		return trim( $match[1] ?? '' );
	}

	/**
	 * Both lists hold the same hashes in the same order.
	 *
	 * @return void
	 */
	public function test_both_lists_hold_the_same_hashes() {
		$this->assertSame( $this->fetch_list(), $this->scrape_list() );
	}

	/**
	 * Both lists are the same bytes in source, trailing comments included.
	 *
	 * @return void
	 */
	public function test_both_lists_are_identical_in_source() {
		$this->assertSame(
			$this->list_lines( FetchImportProductImageCommand::class ),
			$this->list_lines( ScrapeProductMedia::class )
		);
	}

	/**
	 * The list carries the six upstream hashes, each a distinct sha256.
	 *
	 * @return void
	 */
	public function test_list_carries_six_distinct_sha256_hashes() {
		$hashes = $this->fetch_list();

		$this->assertCount( 6, $hashes );
		$this->assertCount( 6, array_unique( $hashes ) );
		$this->assertSame( $hashes, preg_grep( '/^[0-9a-f]{64}$/', $hashes ) );
	}

	/**
	 * The RSR soft-404 hash is rejected by both.
	 *
	 * @return void
	 */
	public function test_rsr_soft_404_hash_is_in_both_lists() {
		$this->assertContains( self::RSR_SOFT_404, $this->fetch_list() );
		$this->assertContains( self::RSR_SOFT_404, $this->scrape_list() );
	}

	/**
	 * Both doc comments name the pipeline file as the authority.
	 *
	 * @return void
	 */
	public function test_both_comments_name_the_pipeline_authority() {
		$this->assertSame( self::AUTHORITY, $this->authority_line( FetchImportProductImageCommand::class ) );
		$this->assertSame( self::AUTHORITY, $this->authority_line( ScrapeProductMedia::class ) );
	}
}
