<?php
/**
 * Autoload-time registration test for AutoAttachUploadedMedia.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that merely loading the class file registers no hooks.
 *
 * `composer.json` autoloads `src/` by classmap, so a class file is included on
 * first reference to the class — which happens inside a test, not before the
 * suite. That makes autoload-time side effects directly observable: install a
 * recorder for add_action/add_filter, then trigger the include and see what the
 * file did on its own.
 *
 * The one requirement is that the class must not already be loaded, since a
 * class file is included only once per process. `#[RunInSeparateProcess]` with
 * `#[PreserveGlobalState(false)]` guarantees a fresh process, so the ordering is
 * deterministic rather than dependent on which test ran first. This test lives
 * in its own class for that reason: `AutoAttachUploadedMediaTest::setUp()`
 * constructs the class, which would load it before the assertion could run.
 *
 * Issue #18, row 1.
 */
class AutoAttachUploadedMediaAutoloadTest extends TestCase {

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
		$class = 'FAToolkit\Media\AutoAttachUploadedMedia';

		$this->assertFalse(
			class_exists( $class, false ),
			'Precondition: the class must not be loaded yet, or the include has already happened and this test proves nothing.'
		);

		$GLOBALS['fa_autoload_registrations'] = array();

		$recorder = static function ( $hook ) {
			$GLOBALS['fa_autoload_registrations'][] = $hook;
			return true;
		};

		Functions\when( 'add_action' )->alias( $recorder );
		Functions\when( 'add_filter' )->alias( $recorder );

		// Trigger the classmap include.
		class_exists( $class );

		$this->assertSame(
			array(),
			$GLOBALS['fa_autoload_registrations'],
			'Loading the class file registered hooks by itself. The bootstrap at '
			. 'fa-toolkit.php:78 is the single intended registration point; anything '
			. 'registered here is a second, duplicate registration. See issue #18.'
		);
	}
}
