<?php
/**
 * Plugin Name: Color Test Command
 * Description: Adds a custom WP-CLI command to test color output.
 * Version: 1.0.0
 * Author: Stephen Feather
 * Author URI: https://stephenfeather.com
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Test color output using the WP_CLI::colorize() function.
	 */
	class Color_Test {
		/**
		 * Run the color test command.
		 *
		 * ## EXAMPLES
		 *
		 *     wp color-test
		 *
		 * @param array $args Command arguments.
		 * @param array $assoc_args Associative command arguments.
		 */
		public function __invoke( $args, $assoc_args ) {
			WP_CLI::line( WP_CLI::colorize( '%yYellow text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%gGreen text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%bBlue text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%rRed text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%pMagenta text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%mMagenta text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%cCyan text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%wGrey text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%kBlack text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%YBright yellow text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%GBright green text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%BBright blue text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%RBright red text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%PBright magenta text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%MBright magenta text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%CBright cyan text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%WBright grey text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%KBright black text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%3Yellow background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%2Green background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%4Blue background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%1Red background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%5Magenta background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%6Cyan background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%7Grey background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%0Black background%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%FBlinking text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%UUnderlined text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%8Inverse text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%9Bright text%n' ) );
			WP_CLI::line( WP_CLI::colorize( '%_Bright text%n' ) );
		}
	}

	/**
	 * Register the color-test command.
	 */
	WP_CLI::add_command( 'color-test', 'Color_Test' );
}
