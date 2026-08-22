<?php
/**
 * Autoload-time registration test for GoogleTagManager.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoAutoloadRegistration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that merely loading the class file registers no hooks.
 *
 * Mechanism and the reason process isolation is required are documented on
 * AssertsNoAutoloadRegistration.
 *
 * Issue #18, row 3.
 */
class GoogleTagManagerAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register wp_head or wp_body_open.
	 *
	 * Before the fix, `src/Site/class-googletagmanager.php` ended with a
	 * file-scope `new GoogleTagManager();`. Including the file ran the
	 * constructor, which registered both hooks — a second registration on top
	 * of the bootstrap's at `fa-toolkit.php:111`, so the GTM-NQJ5QVD container
	 * script and its noscript iframe were emitted twice on every page.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_no_hooks() {
		$this->assertAutoloadRegistersNoHooks(
			'FAToolkit\Site\GoogleTagManager',
			'fa-toolkit.php:111'
		);
	}
}
