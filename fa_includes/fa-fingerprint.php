<?php
/**
 * Fingerprint integration helpers for checkout and frontend tracking.
 *
 * @package FA_Toolkit
 */

/**
 * Add fingerprintJS to our checkout page
 *
 * @return void
 */
function fingerprint_add_jscript_checkout() {   ?>
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

wp_register_script( 'iife', 'https://fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx/iife.min.js', array(), '3.0.0', true );

add_action( 'wp_head', 'fingerprint_response_handler' );

/**
 * Builds the inline FingerprintJS loader script for the site header.
 *
 * @return string
 */
function fingerprint_response_handler() {

	$script = '<script async id="FingerPrint">';

	$script .= 'console.log("Initializing Fingerprint");';
	$script .= 'var fpPromise = FingerprintJS.load({';
	$script .= 'apiKey: "Oo4CqqyVw0pCzwTpD4Mx", endpoint: "https://metrics.featherarms.com"';
	$script .= '});';

	$script .= 'fpPromise';
	$script .= '	.then(function (fp) { return fp.get({tag: {';
	$script .= '		PHPSESSID: ' . session_id() . ',';
	$script .= '		userID: ' . get_current_user_id();
	$script .= '	}}) })';
	$script .= '.then(function (result) { console.log("Result: " + result) })';
	$script .= '</script>';

	return $script;
}
