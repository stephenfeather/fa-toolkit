<?php
/**
 * Autoload-time registration test for PWBulkEditorSettings.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Autoload;

use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoAutoloadRegistration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Proves that merely loading the class file registers no filters.
 *
 * Mechanism and the reason process isolation is required are documented on
 * AssertsNoAutoloadRegistration.
 *
 * Issue #18, row 2.
 */
class PWBulkEditorSettingsAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must not register any of the eight PWBE filters.
	 *
	 * Before the fix, `src/Modules/class-pwbulkeditorsettings.php` ended with a
	 * file-scope `new PWBulkEditorSettings();`. Including the file ran the
	 * constructor, which registered all eight filters — a second registration
	 * on top of the bootstrap's at `fa-toolkit.php:96`.
	 *
	 * The consequence is not merely duplicated work: applied twice,
	 * pwbe_common_joins_category_count() appends its `LEFT JOIN ... AS
	 * category_counts` a second time, and MySQL rejects the duplicate alias
	 * with ERROR 1066 "Not unique table/alias".
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_no_hooks() {
		$this->assertAutoloadRegistersNoHooks(
			'FAToolkit\Modules\PWBulkEditorSettings',
			'fa-toolkit.php:96'
		);
	}
}
