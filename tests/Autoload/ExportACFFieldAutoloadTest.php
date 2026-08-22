<?php
/**
 * Autoload-time registration test for ExportACFField.
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
class ExportACFFieldAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the WP-CLI command.
	 *
	 * Before the fix, `src/CLI/Tools/class-exportacffield.php` ended with a
	 * file-scope `new ExportACFField();`, so including the file registered
	 * `fa:tools export-acf-field` a second time on top of the bootstrap's
	 * registration at `fa-toolkit.php:127`.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\CLI\Tools\ExportACFField',
			'fa-toolkit.php:127'
		);
	}
}
