<?php
/**
 * Autoload-time registration test for FixRankMathSchemas.
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
class FixRankMathSchemasAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the WP-CLI command.
	 *
	 * Before the fix, `src/Utilities/class-fixrankmathschemas.php` ended with a
	 * file-scope `new FixRankMathSchemas();`, so including the file registered
	 * `fa:utilities fix-rank-math-schemas` a second time on top of the
	 * bootstrap's registration at `fa-toolkit.php:104`.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Utilities\FixRankMathSchemas',
			'fa-toolkit.php:104'
		);
	}
}
