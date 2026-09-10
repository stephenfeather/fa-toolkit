<?php
/**
 * Tests for Color_Test class
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Utilities;

use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoFileScopeInstantiation;
use FAToolkit\Utilities\Color_Test;

/**
 * Test case for Color_Test WP-CLI command.
 */
class ColorTestTest extends TestCase {

	use AssertsNoFileScopeInstantiation;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset calls before each test. The class is autoloaded by classmap like
		// every other src/ class; no require_once or WP_CLI define is needed.
		\WP_CLI::reset_calls();
	}

	/**
	 * The class file must not construct itself at include time.
	 *
	 * Issues #18 and #24.
	 *
	 * @return void
	 */
	public function test_class_file_does_not_instantiate_at_file_scope() {
		$this->assertNoFileScopeInstantiation(
			dirname( __DIR__, 2 ) . '/src/Utilities/class-color-test.php'
		);
	}

	/**
	 * Constructing the class registers the `color-test` command on this instance.
	 *
	 * The bootstrap at fa-toolkit.php:131 constructs the class once under WP-CLI;
	 * that construction must be the registration, and the callable must be the
	 * instance the bootstrap made, not a throwaway. Issue #24.
	 *
	 * @return void
	 */
	public function test_constructor_registers_color_test_command() {
		$color_test = new Color_Test();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'color-test', $calls[0]['args'][0] );
		$this->assertSame( $color_test, $calls[0]['args'][1] );
	}

	/**
	 * Test that __invoke method outputs all 31 color codes.
	 *
	 * @return void
	 */
	public function test_invoke_outputs_all_color_codes() {
		// Create instance and invoke.
		$color_test = new \FAToolkit\Utilities\Color_Test();
		$color_test->__invoke( array(), array() );

		// Verify colorize was called 31 times.
		$colorize_calls = \WP_CLI::get_calls( 'colorize' );
		$this->assertCount( 31, $colorize_calls );

		// Verify line was called 31 times.
		$line_calls = \WP_CLI::get_calls( 'line' );
		$this->assertCount( 31, $line_calls );
	}

	/**
	 * Test that __invoke method calls colorize with correct color codes.
	 *
	 * @return void
	 */
	public function test_invoke_calls_colorize_with_correct_codes() {
		$expected_codes = array(
			'%yYellow text%n',
			'%gGreen text%n',
			'%bBlue text%n',
			'%rRed text%n',
			'%pMagenta text%n',
			'%mMagenta text%n',
			'%cCyan text%n',
			'%wGrey text%n',
			'%kBlack text%n',
			'%YBright yellow text%n',
			'%GBright green text%n',
			'%BBright blue text%n',
			'%RBright red text%n',
			'%PBright magenta text%n',
			'%MBright magenta text%n',
			'%CBright cyan text%n',
			'%WBright grey text%n',
			'%KBright black text%n',
			'%3Yellow background%n',
			'%2Green background%n',
			'%4Blue background%n',
			'%1Red background%n',
			'%5Magenta background%n',
			'%6Cyan background%n',
			'%7Grey background%n',
			'%0Black background%n',
			'%FBlinking text%n',
			'%UUnderlined text%n',
			'%8Inverse text%n',
			'%9Bright text%n',
			'%_Bright text%n',
		);

		$color_test = new \FAToolkit\Utilities\Color_Test();
		$color_test->__invoke( array(), array() );

		$colorize_calls = \WP_CLI::get_calls( 'colorize' );

		// Verify we got 31 calls.
		$this->assertCount( 31, $colorize_calls );

		// Verify each expected code was used in order.
		for ( $i = 0; $i < 31; $i++ ) {
			$this->assertArrayHasKey( $i, $colorize_calls );
			$this->assertArrayHasKey( 'args', $colorize_calls[ $i ] );
			$this->assertArrayHasKey( 0, $colorize_calls[ $i ]['args'] );
			$this->assertEquals( $expected_codes[ $i ], $colorize_calls[ $i ]['args'][0] );
		}
	}

	/**
	 * Test that __invoke accepts args and assoc_args parameters.
	 *
	 * @return void
	 */
	public function test_invoke_accepts_parameters() {
		$color_test = new \FAToolkit\Utilities\Color_Test();

		// Should accept both arrays without error.
		$color_test->__invoke( array( 'arg1', 'arg2' ), array( 'key' => 'value' ) );

		// Verify it was called.
		$calls = \WP_CLI::get_calls();
		$this->assertNotEmpty( $calls );
	}
}
