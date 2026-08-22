<?php
/**
 * Autoload-time registration test for WPAllImportSettings.
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
 * Issue #18. This is the row the issue calls immune: the constructor registers
 * STRING callbacks (`'wpai_send_email'`, `'fa_img_import'`), and WordPress keys
 * string callbacks on the function name rather than on `spl_object_hash()`, so
 * a second registration of the same string collapses onto the first and the
 * callback still fires once.
 *
 * The file-scope construction is removed anyway, and this test still earns its
 * place: immunity is a property of the CALLBACK SHAPE, not of the class. The day
 * one of those hooks becomes `array( $this, ... )` the immunity vanishes
 * silently. This test fails at that moment rather than in production.
 */
class WPAllImportSettingsAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the import hooks.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Modules\WPAllImportSettings',
			'fa-toolkit.php:98'
		);
	}
}
