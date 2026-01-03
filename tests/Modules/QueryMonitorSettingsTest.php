<?php
/**
 * Tests for QueryMonitorSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\QueryMonitorSettings;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test QueryMonitorSettings
 *
 * Note: This class is NOT auto-initialized in the source file.
 * Note: The silence_noisy_plugins method is private but called by filter hook.
 */
class QueryMonitorSettingsTest extends TestCase {

	/**
	 * Test silence_noisy_plugins modifies error levels for specific plugins.
	 */
	public function test_silence_noisy_plugins_modifies_error_levels() {
		$settings = new QueryMonitorSettings();

		$levels = array(
			'plugin' => array(
				'some-other-plugin' => E_ALL,
			),
		);

		$result = $this->invokePrivateMethod( $settings, 'silence_noisy_plugins', array( $levels ) );

		// Should preserve existing plugins.
		$this->assertArrayHasKey( 'some-other-plugin', $result['plugin'] );
		$this->assertSame( E_ALL, $result['plugin']['some-other-plugin'] );

		// Should add fraudlabs-pro-for-woocommerce with E_ALL & ~E_NOTICE.
		$this->assertArrayHasKey( 'fraudlabs-pro-for-woocommerce', $result['plugin'] );
		$this->assertSame( ( E_ALL & ~E_NOTICE ), $result['plugin']['fraudlabs-pro-for-woocommerce'] );

		// Should add query-monitor with E_ALL & ~E_NOTICE.
		$this->assertArrayHasKey( 'query-monitor', $result['plugin'] );
		$this->assertSame( ( E_ALL & ~E_NOTICE ), $result['plugin']['query-monitor'] );

		// Should add duracelltomi - google - tag - manager with E_ALL & ~E_NOTICE.
		$this->assertArrayHasKey( 'duracelltomi - google - tag - manager', $result['plugin'] );
		$this->assertSame( ( E_ALL & ~E_NOTICE ), $result['plugin']['duracelltomi - google - tag - manager'] );
	}

	/**
	 * Helper method to invoke private methods via reflection.
	 *
	 * @param object $object     The object instance.
	 * @param string $method_name The private method name.
	 * @param array  $parameters The method parameters.
	 *
	 * @return mixed The method result.
	 */
	private function invokePrivateMethod( $object, $method_name, array $parameters = array() ) {
		$reflection = new \ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}
