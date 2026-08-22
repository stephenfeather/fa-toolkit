<?php
/**
 * Autoload-time registration test for Media_Fix_Ilab_Metadata.
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
class MediaFixIlabMetadataAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register its two WP-CLI commands.
	 *
	 * Before the fix, `src/Media/class-media-fix-ilab-metadata.php` ended with a
	 * file-scope `new Media_Fix_Ilab_Metadata();`, so including the file
	 * registered `fa:media fix-media-metadata` and `fa:media fix-all-media-metadata`
	 * a second time on top of the bootstrap's registration at `fa-toolkit.php:82`.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Media\Media_Fix_Ilab_Metadata',
			'fa-toolkit.php:82'
		);
	}
}
