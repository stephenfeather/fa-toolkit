<?php
/**
 * Tests for GoogleTagManager class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\GoogleTagManager;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test case for GoogleTagManager.
 */
class GoogleTagManagerTest extends TestCase {

	/**
	 * Test that add_to_head outputs the correct GTM script tag.
	 *
	 * @return void
	 */
	public function test_add_to_head_outputs_gtm_script() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_head();
		$output = ob_get_clean();

		// Verify GTM script is present.
		$this->assertStringContainsString( '<!-- Google Tag Manager -->', $output );
		$this->assertStringContainsString( '<script defer id=\'GTM\'>', $output );
		$this->assertStringContainsString( 'www.googletagmanager.com/gtm.js', $output );
		$this->assertStringContainsString( 'GTM-NQJ5QVD', $output );
		$this->assertStringContainsString( '<!-- End Google Tag Manager -->', $output );
	}

	/**
	 * Test that add_to_head includes the GTM container ID.
	 *
	 * @return void
	 */
	public function test_add_to_head_includes_container_id() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_head();
		$output = ob_get_clean();

		// Verify the specific GTM container ID is in the output.
		$this->assertStringContainsString( 'GTM-NQJ5QVD', $output );
	}

	/**
	 * Test that add_to_head script is deferred.
	 *
	 * @return void
	 */
	public function test_add_to_head_script_is_deferred() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_head();
		$output = ob_get_clean();

		// Verify defer attribute is present.
		$this->assertStringContainsString( 'defer', $output );
	}

	/**
	 * Test that add_to_body outputs the correct GTM noscript iframe.
	 *
	 * @return void
	 */
	public function test_add_to_body_outputs_noscript_iframe() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_body();
		$output = ob_get_clean();

		// Verify noscript iframe is present.
		$this->assertStringContainsString( '<!-- Google Tag Manager (noscript) -->', $output );
		$this->assertStringContainsString( '<noscript>', $output );
		$this->assertStringContainsString( '<iframe', $output );
		$this->assertStringContainsString( 'www.googletagmanager.com/ns.html', $output );
		$this->assertStringContainsString( 'GTM-NQJ5QVD', $output );
		$this->assertStringContainsString( '</noscript>', $output );
		$this->assertStringContainsString( '<!-- End Google Tag Manager (noscript) -->', $output );
	}

	/**
	 * Test that add_to_body iframe has correct attributes.
	 *
	 * @return void
	 */
	public function test_add_to_body_iframe_attributes() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_body();
		$output = ob_get_clean();

		// Verify iframe attributes for hidden display.
		$this->assertStringContainsString( 'height="0"', $output );
		$this->assertStringContainsString( 'width="0"', $output );
		$this->assertStringContainsString( 'display:none', $output );
		$this->assertStringContainsString( 'visibility:hidden', $output );
	}

	/**
	 * Test that add_to_body includes the GTM container ID.
	 *
	 * @return void
	 */
	public function test_add_to_body_includes_container_id() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_body();
		$output = ob_get_clean();

		// Verify the specific GTM container ID is in the iframe src.
		$this->assertStringContainsString( 'id=GTM-NQJ5QVD', $output );
	}
}
