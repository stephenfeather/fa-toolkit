<?php
/**
 * Assertion helper for the file-scope instantiation defect tracked in issue #18.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Support;

/**
 * Asserts that a class file does not construct anything at include time.
 *
 * Background (issue #18): several class files end with a file-scope `new
 * ClassName();`. Because the plugin bootstrap also instantiates the same class
 * (fa-toolkit.php:70-133), the class ends up constructed twice — once when the
 * autoloader includes the file, once when the bootstrap runs. Constructors in
 * this plugin register hooks, and WordPress keys hook callbacks on
 * spl_object_hash() for object-method callbacks, so two instances mean two
 * surviving registrations and a callback that fires twice.
 *
 * This is a source-pattern net, and it is deliberately NOT the only check.
 *
 * An earlier version of this docblock claimed the defect was unobservable from
 * inside a test. That was wrong, and the correction matters to anyone reading
 * this later. `src/` is autoloaded by classmap (composer.json), so a class file
 * is included on FIRST REFERENCE to the class — which happens inside a test,
 * not before the suite. The autoload-time registrations are therefore fully
 * observable: install a recorder for add_action/add_filter, then trigger the
 * include and inspect what the file did on its own.
 *
 * The real constraint is narrower: a class file is included only once per
 * process, so in the default single-process arrangement the observation is lost
 * if any earlier test already loaded the class. `#[RunInSeparateProcess]` with
 * `#[PreserveGlobalState(false)]` removes that ordering dependence entirely.
 * See tests/Autoload/ for the behavioural tests that do exactly this — they are
 * the ones that prove the defect. This assertion is the durable pattern guard
 * that sits alongside them.
 *
 * TODO: known false negative — this assertion only inspects brace-depth zero,
 * so a wrapped instantiation such as `if ( true ) { new A(); }` at file scope
 * passes it while still constructing on include. The behavioural tests in
 * tests/Autoload/ do not have that hole. Recorded rather than fixed; tightening
 * the traversal is separate work.
 */
trait AssertsNoFileScopeInstantiation {

	/**
	 * Assert that the given PHP file contains no `new` outside a class or function body.
	 *
	 * Uses the tokenizer rather than a regex so that `new` appearing inside
	 * inline HTML or JavaScript (T_INLINE_HTML is a single token) cannot
	 * produce a false positive.
	 *
	 * @param string $file Absolute path to the PHP file to inspect.
	 * @return void
	 */
	protected function assertNoFileScopeInstantiation( $file ) {
		$this->assertFileExists( $file );

		$tokens = token_get_all( file_get_contents( $file ) );
		$depth  = 0;
		$found  = array();

		foreach ( $tokens as $token ) {
			if ( '{' === $token ) {
				++$depth;
				continue;
			}
			if ( '}' === $token ) {
				--$depth;
				continue;
			}
			if ( is_array( $token ) && T_CURLY_OPEN === $token[0] ) {
				++$depth;
				continue;
			}
			if ( is_array( $token ) && T_NEW === $token[0] && 0 === $depth ) {
				$found[] = $token[2];
			}
		}

		$this->assertSame(
			array(),
			$found,
			sprintf(
				'%s constructs at file scope (line%s %s). The bootstrap in fa-toolkit.php '
				. 'is the single intended registration point; a file-scope `new` makes the '
				. 'class construct a second time on autoload. See issue #18.',
				basename( $file ),
				count( $found ) === 1 ? '' : 's',
				implode( ', ', $found )
			)
		);
	}
}
