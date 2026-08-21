<?php
/**
 * Autoload-time registration test for PWBulkEditorSettings.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that merely loading the class file registers no filters.
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
 * deterministic rather than dependent on which test ran first.
 *
 * Issue #18, row 2.
 */
class PWBulkEditorSettingsAutoloadTest extends TestCase {

	/**
	 * Loading the class file must not register any of the eight PWBE filters.
	 *
	 * Before the fix, `src/Modules/class-pwbulkeditorsettings.php` ended with a
	 * file-scope `new PWBulkEditorSettings();`. Including the file ran the
	 * constructor, which registered all eight filters — a second registration
	 * on top of the bootstrap's at `fa-toolkit.php:96`.
	 *
	 * The consequence is not merely duplicated work: applied twice,
	 * `pwbe_common_joins_category_count()` appends its `LEFT JOIN ... AS
	 * category_counts` a second time, and MySQL rejects the duplicate alias
	 * with ERROR 1066 "Not unique table/alias".
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_no_hooks() {
		$class = 'FAToolkit\Modules\PWBulkEditorSettings';

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
			'Loading the class file registered filters by itself. The bootstrap at '
			. 'fa-toolkit.php:96 is the single intended registration point; anything '
			. 'registered here is a second, duplicate registration. See issue #18.'
		);
	}
}
