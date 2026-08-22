<?php
/**
 * Autoload-time registration test for UpdraftPlusSettings.
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
class UpdraftPlusSettingsAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the exclude-directory filter.
	 *
	 * Before the fix, `src/Modules/class-updraftplussettings.php` ended with a
	 * file-scope `new UpdraftPlusSettings();`. Including the file added
	 * `updraftplus_exclude_directory` a second time on top of the bootstrap's
	 * registration at `fa-toolkit.php:97`. A filter running twice matters more
	 * than an action: each pass receives the previous pass's return value.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Modules\UpdraftPlusSettings',
			'fa-toolkit.php:97'
		);
	}
}
