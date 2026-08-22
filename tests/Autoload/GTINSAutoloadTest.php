<?php
/**
 * Autoload-time registration test for GTINS.
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
 * Issue #18.
 */
class GTINSAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the WP-CLI command.
	 *
	 * Before the fix, `src/Utilities/class-gtins.php` ended with a file-scope
	 * `new GTINS();`, so including the file registered
	 * `fa:utilities populateGTINS` a second time on top of the bootstrap's
	 * registration at `fa-toolkit.php:105`.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Utilities\GTINS',
			'fa-toolkit.php:105'
		);
	}
}
