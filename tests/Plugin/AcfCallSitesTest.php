<?php
/**
 * Regression net for issue #77: nothing under src/ may call ACF.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Plugin;

use FAToolkit\Tests\Support\AssertsNoAcfFunctionCalls;
use FAToolkit\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every PHP file under src/ is free of ACF function calls.
 *
 * @since 1.2.1
 */
class AcfCallSitesTest extends TestCase {

	use AssertsNoAcfFunctionCalls;

	/**
	 * Files still carrying ACF calls, deferred to their own overhauls.
	 *
	 * Stephen's ruling on #77: these three CLI media commands have other
	 * defects (see #38 for ScrapeProductMedia) and their ACF removal lands with
	 * those overhauls, not here. Each will fatal if run until then. Remove an
	 * entry when its file is cleaned up; the test then guards it.
	 *
	 * @var string[] Paths relative to src/.
	 */
	private const DEFERRED_TO_OVERHAUL = array(
		'CLI/Media/class-exportdraftproductimagesourcescommand.php',
		'CLI/Media/class-fetchimportproductimagecommand.php',
		'CLI/Media/class-scrapeproductmedia.php',
	);

	/**
	 * Data provider: every .php file under src/ not deferred to an overhaul.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function source_files() {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = array();
		$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $iter as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( in_array( $relative, self::DEFERRED_TO_OVERHAUL, true ) ) {
				continue;
			}
			$files[ $relative ] = array( $file->getPathname() );
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Every deferred entry still exists and still has the call it is excused for.
	 *
	 * A stale entry would silently exempt a file that has since been cleaned up,
	 * or one that no longer exists.
	 */
	public function test_deferred_files_still_carry_acf_calls() {
		$root = dirname( __DIR__, 2 ) . '/src/';
		foreach ( self::DEFERRED_TO_OVERHAUL as $relative ) {
			$this->assertFileExists( $root . $relative );
			try {
				$this->assertNoAcfFunctionCalls( $root . $relative );
			} catch ( AssertionFailedError $e ) {
				continue;
			}
			$this->fail( $relative . ' no longer calls ACF; remove it from DEFERRED_TO_OVERHAUL so the net guards it.' );
		}
	}

	/**
	 * The assertion itself must detect a real call.
	 */
	public function test_assertion_detects_a_bare_get_field_call() {
		$fixture = tempnam( sys_get_temp_dir(), 'acf' );
		file_put_contents( $fixture, "<?php\n\$v = get_field( 'dealer', 1 );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$this->assertNoAcfFunctionCalls( $fixture );
			$this->fail( 'Expected the assertion to fail on a bare get_field() call.' );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( 'get_field() at line 2', $e->getMessage() );
		} finally {
			unlink( $fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Method calls, static calls, definitions, strings and comments are not flagged.
	 */
	public function test_assertion_ignores_non_calls() {
		$fixture = tempnam( sys_get_temp_dir(), 'acf' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$fixture,
			"<?php\n// get_field( 'x' ) in a comment\n\$s = 'get_field(';\n\$o->get_field( 1 );\n\$o?->get_field( 1 );\nFoo::get_field( 1 );\nfunction get_field() {}\n"
		);

		try {
			$this->assertNoAcfFunctionCalls( $fixture );
		} finally {
			unlink( $fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * No file under src/ calls an ACF function.
	 *
	 * @param string $file Absolute path.
	 */
	#[DataProvider( 'source_files' )]
	public function test_source_file_calls_no_acf_function( $file ) {
		$this->assertNoAcfFunctionCalls( $file );
	}
}
