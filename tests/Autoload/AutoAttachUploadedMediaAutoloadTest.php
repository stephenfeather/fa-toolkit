<?php
/**
 * Autoload-time registration test for AutoAttachUploadedMedia.
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
 * This lives in its own test class because
 * AutoAttachUploadedMediaTest::setUp() constructs the class, which would load
 * it before the precondition could be asserted.
 *
 * Issue #18, row 1.
 */
class AutoAttachUploadedMediaAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register the add_attachment hook.
	 *
	 * Before the fix, `src/Media/class-autoattachuploadedmedia.php` ended with a
	 * file-scope `new AutoAttachUploadedMedia();`. Including the file ran the
	 * constructor, which registered `add_attachment` — a second registration on
	 * top of the bootstrap's at `fa-toolkit.php:78`, so every media upload ran
	 * process_uploaded_attachment twice.
	 *
	 * The harm is not the repeated write. Each write is idempotent on its own;
	 * append_to_gallery() even guards duplicates explicitly. The harm is that
	 * determine_image_type() branches on state the first run mutated, so a
	 * `{hash}_0.jpg` upload becomes the featured image on run 1 and is then
	 * appended to the gallery on run 2 — one image, both places. See
	 * AutoAttachUploadedMediaTest::test_second_run_reroutes_featured_image_into_the_gallery.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_no_hooks() {
		$this->assertAutoloadRegistersNoHooks(
			'FAToolkit\Media\AutoAttachUploadedMedia',
			'fa-toolkit.php:78'
		);
	}
}
