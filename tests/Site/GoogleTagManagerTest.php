<?php
/**
 * Tests for GoogleTagManager class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\GoogleTagManager;
use FAToolkit\Tests\TestCase;
use FAToolkit\Tests\Support\AssertsNoFileScopeInstantiation;
use Brain\Monkey\Functions;

/**
 * Test case for GoogleTagManager.
 */
class GoogleTagManagerTest extends TestCase {

	use AssertsNoFileScopeInstantiation;

	/**
	 * The class file must not construct itself at include time.
	 *
	 * fa-toolkit.php:111 already instantiates this class. A second, file-scope
	 * construction registers the constructor's two hooks twice — WordPress keys
	 * object-method callbacks on spl_object_hash(), so both registrations
	 * survive and both callbacks fire on every page.
	 *
	 * The consequence is a duplicated GTM container: see
	 * test_container_is_emitted_once_per_callback_invocation.
	 *
	 * Issue #18, row 3.
	 *
	 * @return void
	 */
	public function test_class_file_does_not_instantiate_at_file_scope() {
		$this->assertNoFileScopeInstantiation(
			dirname( __DIR__, 2 ) . '/src/Site/class-googletagmanager.php'
		);
	}

	/**
	 * Each invocation emits one container snippet, so two invocations emit two.
	 *
	 * This documents WHY double registration matters here. `add_to_head` and
	 * `add_to_body` are output methods with no guard against running more than
	 * once, so a doubled `wp_head` / `wp_body_open` registration loads the
	 * GTM-NQJ5QVD container twice on every page.
	 *
	 * As with the joins filter, the methods are not at fault — emitting output
	 * when called is their job. Single registration is the invariant, and
	 * test_class_file_does_not_instantiate_at_file_scope guards it.
	 *
	 * Issue #18, row 3.
	 *
	 * @return void
	 */
	public function test_container_is_emitted_once_per_callback_invocation() {
		$gtm = new GoogleTagManager();

		ob_start();
		$gtm->add_to_head();
		$once = ob_get_clean();

		ob_start();
		$gtm->add_to_head();
		$gtm->add_to_head();
		$twice = ob_get_clean();

		$this->assertSame( 1, substr_count( $once, 'GTM-NQJ5QVD' ) );
		$this->assertSame(
			2,
			substr_count( $twice, 'GTM-NQJ5QVD' ),
			'Two invocations must emit the container twice — that duplication is the analytics defect.'
		);
	}

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
