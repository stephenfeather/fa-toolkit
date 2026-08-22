<?php
/**
 * Assertion helper for autoload-time hook registration, issue #18.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Asserts that merely loading a class file registers no WordPress hooks.
 *
 * Background (issue #18): several class files end with a file-scope `new
 * ClassName();`. The plugin bootstrap already instantiates the same classes
 * (fa-toolkit.php:70-133), so the class is constructed twice — once when the
 * autoloader includes the file, once when the bootstrap runs. Constructors here
 * register hooks, and WordPress keys object-method callbacks on
 * spl_object_hash(), so two instances mean two surviving registrations.
 *
 * `src/` is autoloaded by CLASSMAP (composer.json), which means a class file is
 * included on FIRST REFERENCE to the class — inside a test, not before the
 * suite. So the autoload-time registrations are directly observable: install a
 * recorder for add_action/add_filter, trigger the include, and inspect what the
 * file did on its own.
 *
 * The only constraint is ordering: a class file is included once per process,
 * so the observation is lost if an earlier test already loaded the class. Every
 * caller must therefore be marked `#[RunInSeparateProcess]` and
 * `#[PreserveGlobalState(false)]`, and the helper asserts the precondition
 * rather than trusting it.
 */
trait AssertsNoAutoloadRegistration {

	/**
	 * Assert that including the given class registers nothing.
	 *
	 * @param string $class          Fully-qualified class name to autoload.
	 * @param string $bootstrap_site Where the intended single registration lives,
	 *                               e.g. 'fa-toolkit.php:96'. Used in the failure message.
	 * @return void
	 */
	protected function assertAutoloadRegistersNoHooks( $class, $bootstrap_site ) {
		$registered = $this->captureAutoloadRegistrations( $class );

		$this->assertSame(
			array(),
			$registered['hooks'],
			sprintf(
				'Loading %s registered hooks by itself. The bootstrap at %s is the single '
				. 'intended registration point; anything registered here is a second, '
				. 'duplicate registration. See issue #18.',
				$class,
				$bootstrap_site
			)
		);
	}

	/**
	 * Assert that including the given class registers neither hooks nor WP-CLI commands.
	 *
	 * Use this for classes whose constructor calls `\WP_CLI::add_command()`. The
	 * hooks-only assertion above cannot see those: `add_command` is a static call
	 * on the WP_CLI class, not a WordPress function Brain Monkey can intercept.
	 * The test-suite stub at tests/bootstrap.php records every call instead, so
	 * autoload-time command registration is observable the same way hooks are.
	 *
	 * @param string $class          Fully-qualified class name to autoload.
	 * @param string $bootstrap_site Where the intended single registration lives,
	 *                               e.g. 'fa-toolkit.php:83'. Used in the failure message.
	 * @return void
	 */
	protected function assertAutoloadRegistersNothing( $class, $bootstrap_site ) {
		$registered = $this->captureAutoloadRegistrations( $class );

		$this->assertSame(
			array(),
			$registered['hooks'],
			sprintf(
				'Loading %s registered hooks by itself. The bootstrap at %s is the single '
				. 'intended registration point; anything registered here is a second, '
				. 'duplicate registration. See issue #18.',
				$class,
				$bootstrap_site
			)
		);

		$this->assertSame(
			array(),
			$registered['commands'],
			sprintf(
				'Loading %s registered WP-CLI command(s) by itself: %s. The bootstrap at %s '
				. 'is the single intended registration point. See issue #18.',
				$class,
				implode( ', ', $registered['commands'] ),
				$bootstrap_site
			)
		);
	}

	/**
	 * Trigger the classmap include and record what the file registered on its own.
	 *
	 * @param string $class Fully-qualified class name to autoload.
	 * @return array{hooks: array<int, string>, commands: array<int, string>}
	 */
	private function captureAutoloadRegistrations( $class ) {
		$this->assertFalse(
			class_exists( $class, false ),
			sprintf(
				'Precondition failed: %s is already loaded, so the include has already '
				. 'happened and this test proves nothing. The test method must be marked '
				. '#[RunInSeparateProcess] and #[PreserveGlobalState(false)].',
				$class
			)
		);

		$GLOBALS['fa_autoload_registrations'] = array();

		$recorder = static function ( $hook ) {
			$GLOBALS['fa_autoload_registrations'][] = $hook;
			return true;
		};

		Functions\when( 'add_action' )->alias( $recorder );
		Functions\when( 'add_filter' )->alias( $recorder );

		\WP_CLI::reset_calls();

		// Trigger the classmap include.
		class_exists( $class );

		$commands = array();
		foreach ( \WP_CLI::get_calls( 'add_command' ) as $call ) {
			$commands[] = $call['args'][0];
		}

		return array(
			'hooks'    => $GLOBALS['fa_autoload_registrations'],
			'commands' => $commands,
		);
	}
}
