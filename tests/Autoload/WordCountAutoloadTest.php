<?php
/**
 * Autoload-time registration test for WordCount.
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
class WordCountAutoloadTest extends TestCase {

	use AssertsNoAutoloadRegistration;

	/**
	 * Loading the class file must register neither `save_post` nor the command.
	 *
	 * Before the fix, `src/Product/class-wordcount.php` ended with a file-scope
	 * `new WordCount();`. This class registers both kinds of thing in its
	 * constructor — `save_post` unconditionally and `update_word_count` under
	 * WP-CLI — so the duplicate registration doubled both on top of the
	 * bootstrap's at `fa-toolkit.php:92`, and every post save recalculated the
	 * word count twice.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_loading_the_class_file_registers_nothing() {
		$this->assertAutoloadRegistersNothing(
			'FAToolkit\Product\WordCount',
			'fa-toolkit.php:92'
		);
	}
}
