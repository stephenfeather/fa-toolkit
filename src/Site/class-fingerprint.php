<?php
/**
 * Fingerprint integration helpers for checkout and frontend tracking.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Site;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Fingerprint class for FingerprintJS integration.
 */
class Fingerprint {
	/**
	 * Constructor - registers hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'wp_head', array( $this, 'response_handler' ) );
	}

	/**
	 * Register scripts on the proper WordPress hook.
	 *
	 * @return void
	 */
	public function register_scripts() {
		wp_register_script( 'iife', 'https://fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx/iife.min.js', array(), '3.0.0', true );
	}

	/**
	 * Add fingerprintJS to our checkout page
	 *
	 * @return void
	 */
	public function add_jscript_checkout() {
		?>
		<script id="fingerprint">
		console.log("Initializing Fingerprint");
		const fpPromise = import('https://fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx')
			.then(FingerprintJS => FingerprintJS.load({
				apiKey: 'Oo4CqqyVw0pCzwTpD4Mx',
				endpoint: 'https://metrics.featherarms.com'
			}));

		fpPromise
			.then(fp => fp.get({tag: {
				PHPSESSID: '<?php printf( '%s', esc_html( session_id() ) ); ?>',
				userID: '<?php printf( '%s', esc_html( get_current_user_id() ) ); ?>'
			}}))
			.then(result => console.log(result.));
		</script>
		<?php
	}

	/**
	 * Builds the inline FingerprintJS loader script for the site header.
	 *
	 * @return void
	 */
	public function response_handler() {
		$script = '<script async id="FingerPrint">';

		$script .= 'console.log("Initializing Fingerprint");';
		$script .= 'var fpPromise = FingerprintJS.load({';
		$script .= 'apiKey: "Oo4CqqyVw0pCzwTpD4Mx", endpoint: "https://metrics.featherarms.com"';
		$script .= '});';

		$script .= 'fpPromise';
		$script .= '	.then(function (fp) { return fp.get({tag: {';
		$script .= '		PHPSESSID: ' . (int) session_id() . ',';
		$script .= '		userID: ' . (int) get_current_user_id();
		$script .= '	}}) })';
		$script .= '.then(function (result) { console.log("Result: " + result) })';
		$script .= '</script>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses_post( $script );
	}
}

// Instantiate to register hooks.
new Fingerprint();
