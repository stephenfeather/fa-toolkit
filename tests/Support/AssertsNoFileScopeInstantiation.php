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
 * (fa-toolkit.php:70-125), the class ends up constructed twice — once when the
 * autoloader includes the file, once when the bootstrap runs. Constructors in
 * this plugin register hooks, and WordPress keys hook callbacks on
 * spl_object_hash() for object-method callbacks, so two instances mean two
 * surviving registrations and a callback that fires twice.
 *
 * This cannot be detected with Brain Monkey. The file-scope `new` fires while
 * the autoloader includes the file, which happens once per PHP process and
 * before any test's Brain Monkey window opens, so its registrations are never
 * observable from inside a test. The defect is therefore asserted against the
 * source itself.
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
