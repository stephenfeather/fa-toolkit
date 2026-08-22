<?php
/**
 * Autoload-time registration test for Bard_Meta_Box.
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
class BardMetaBoxAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register `add_meta_boxes`.
	 *
	 * Before the fix, `src/Product/class-bard-meta-box.php` ended with a
	 * file-scope `new Bard_Meta_Box();`. Including the file hooked
	 * `add_meta_boxes` a second time on top of the bootstrap's registration at
	 * `fa-toolkit.php:93`, so `register_meta_box()` ran twice on every admin
	 * screen that fires the hook.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Product\Bard_Meta_Box',
			'fa-toolkit.php:93'
		);
	}
}
