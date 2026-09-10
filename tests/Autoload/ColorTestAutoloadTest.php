<?php
/**
 * Autoload-time registration test for Color_Test.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoAutoloadRegistration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that merely loading the class file registers nothing.
 *
 * Mechanism and the reason process isolation is required are documented on
 * AssertsNoAutoloadRegistration.
 *
 * Issues #18 and #24.
 */
class ColorTestAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the WP-CLI command.
	 *
	 * Before the fix, `src/Utilities/class-color-test.php` declared the class
	 * inside `if ( is_wp_cli() )` and ended with a file-scope
	 * `\WP_CLI::add_command( 'color-test', new Color_Test() )`, so including the
	 * file WAS the registration and the bootstrap's `new` at
	 * `fa-toolkit.php:131` constructed a second, unregistered instance.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Utilities\Color_Test',
			'fa-toolkit.php:131'
		);
	}
}
