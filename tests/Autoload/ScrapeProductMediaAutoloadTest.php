<?php
/**
 * Autoload-time registration test for ScrapeProductMedia.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\CLI\Media\ScrapeProductMedia;
use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoAutoloadRegistration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that loading the class file registers nothing, and that constructing it does.
 *
 * Issue #18, the row whose fix INVERTS. The other rows had the registration in
 * the constructor and a redundant file-scope `new`; this one had the
 * registration at file scope — `\WP_CLI::add_command( 'fa:media
 * scrape-product-media', array( new ScrapeProductMedia(), ... ) )` — and a
 * bootstrap `new` at `fa-toolkit.php:129` that ran no constructor because the
 * class had none.
 *
 * That bootstrap line looked like dead code and was recorded on the issue as
 * safe to delete. It was not: under a CLASSMAP autoloader the `new` is what
 * triggers the include, and the include is what performed the registration.
 * Nothing else in the tree references this class, so deleting the line would
 * have removed the `fa:media scrape-product-media` command entirely — a
 * "cleanup" that silently deletes a command in active use.
 *
 * The fix moves the registration into a constructor, matching every other CLI
 * command class, so the bootstrap line is a real registration rather than an
 * include trigger dressed as dead code. These two tests pin both halves.
 */
class ScrapeProductMediaAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the command.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\CLI\Media\ScrapeProductMedia',
			'fa-toolkit.php:129'
		);
	}

	/**
	 * Constructing the class must register the command exactly once.
	 *
	 * This is the half that keeps the command alive. Without it, "no
	 * registration at include time" would be satisfiable by a class that
	 * registers nothing at all.
	 *
	 * @return void
	 */
	public function test_constructing_registers_the_command_once() {
		\WP_CLI::reset_calls();

		$instance = new ScrapeProductMedia();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media scrape-product-media', $calls[0]['args'][0] );
		$this->assertSame(
			array( $instance, 'wp_cli_scrape_product_media' ),
			$calls[0]['args'][1]
		);
	}
}
