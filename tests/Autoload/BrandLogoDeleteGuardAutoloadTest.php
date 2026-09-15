<?php
/**
 * Autoload-time registration test for BrandLogoDeleteGuard.
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
 * Issue #18 pattern; the bootstrap in fa-toolkit.php is the one registration.
 */
class BrandLogoDeleteGuardAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the delete hooks.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNoHooks(
			'FAToolkit\Media\BrandLogoDeleteGuard',
			'fa-toolkit.php (Media section)'
		);
	}
}
