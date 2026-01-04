<?php
/**
 * Google Tag Manager integration.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Site;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // Exit if accessed directly.
}

/**
 * GoogleTagManager class for Google Tag Manager integration.
 */
class GoogleTagManager {
	/**
	 * Constructor - registers hooks for GTM scripts.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'add_to_head' ) );
		add_action( 'wp_body_open', array( $this, 'add_to_body' ) );
	}

	/**
	 * Outputs the Google Tag Manager script in the document head.
	 *
	 * @return void
	 */
	public function add_to_head() {
		?>
		<!-- Google Tag Manager -->
		<script defer id='GTM'>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
		new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
		j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
		'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
		})(window,document,'script','dataLayer','GTM-NQJ5QVD');</script>
		<!-- End Google Tag Manager -->
		<?php
	}

	/**
	 * Outputs the Google Tag Manager noscript iframe inside the opening body tag.
	 *
	 * @return void
	 */
	public function add_to_body() {
		?>
		<!-- Google Tag Manager (noscript) -->
		<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NQJ5QVD"
		height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
		<!-- End Google Tag Manager (noscript) -->
		<?php
	}
}

// Instantiate to register hooks.
new GoogleTagManager();
