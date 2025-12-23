<?php
/**
 * Query Monitor settings.
 *
 * @package    FA-Toolkit
 * @subpackage FAToolkit/Modules
 * @since      1.0.8
 */

namespace FAToolkit\Modules;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
}

/**
 * Query Monitor settings.
 */
class QueryMonitorSettings {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'qm/collect/php_error_levels', array( $this, silenceNoisyPlugins ) );
	}

	/**
	 * Quiets down some noisy plugins in Query Monitor.
	 * This just removed the red notice in the admin bar, the errors still show in the Query Monitor panel.
	 *
	 * @param array $levels Error levels.
	 * @return array $levels
	 */
	private function silence_noisy_plugins( array $levels ) {
		$levels['plugin']['fraudlabs-pro-for-woocommerce']         = ( E_ALL & ~E_NOTICE );
		$levels['plugin']['query-monitor']                         = ( E_ALL & ~E_NOTICE );
		$levels['plugin']['duracelltomi - google - tag - manager'] = ( E_ALL & ~E_NOTICE );
		return $levels;
	}

}
