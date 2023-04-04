<?php
/**
 * Adds the MS Bing Clarity code to the header.
 *
 * @package FA-Toolkit
 * @since 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add MS Bing Clarity to the head.
 */
function add_msclarity_to_head() { ?>
<!-- Clarity tracking code for https://www.featherarms.com/ -->
	<script id="msclarity">    
	(function(c,l,a,r,i,t,y){        
		c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
		t=l.createElement(r);t.async=1;
		t.src="https://www.clarity.ms/tag/"+i+"?ref=bwt";
		y=l.getElementsByTagName(r)[0];
		y.parentNode.insertBefore(t,y);
	})(window, document, "clarity", "script", "gjacoq2fjh");
	</script>
	<?php
}

add_action( 'wp_head', 'add_msclarity_to_head' );
