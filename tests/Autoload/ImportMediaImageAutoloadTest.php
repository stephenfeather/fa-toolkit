<?php
/**
 * Autoload-time registration test for ImportMediaImage.
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
class ImportMediaImageAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register `rest_api_init`.
	 *
	 * Before the fix, `src/Rest/class-importmediaimage.php` ended with a
	 * file-scope `new ImportMediaImage();`. Including the file ran the
	 * constructor, which hooked `rest_api_init` a second time on top of the
	 * bootstrap's registration at `fa-toolkit.php:123`, so
	 * `register_rest_route()` ran twice for the same route on every REST request.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Rest\ImportMediaImage',
			'fa-toolkit.php:123'
		);
	}
}
