<?php
/**
 * Tests for ActionSchedulerSettings class
 *
 * @package FAToolkit\Tests\Modules
 */

namespace FAToolkit\Tests\Modules;

use FAToolkit\Modules\ActionSchedulerSettings;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * Test ActionSchedulerSettings
 *
 * Note: This class is auto-initialized, so constructor tests are skipped.
 * Tests focus on the public method logic.
 */
class ActionSchedulerSettingsTest extends TestCase {

	/**
	 * Test increase batch size multiplies by 4.
	 */
	public function test_ashp_increase_queue_batch_size() {
		$settings = new ActionSchedulerSettings();

		$result = $settings->ashp_increase_queue_batch_size( 25 );
		$this->assertSame( 100, $result );

		$result = $settings->ashp_increase_queue_batch_size( 10 );
		$this->assertSame( 40, $result );
	}

	/**
	 * Test increase concurrent batches multiplies by 2.
	 */
	public function test_ashp_increase_concurrent_batches() {
		$settings = new ActionSchedulerSettings();

		$result = $settings->ashp_increase_concurrent_batches( 5 );
		$this->assertSame( 10, $result );

		$result = $settings->ashp_increase_concurrent_batches( 3 );
		$this->assertSame( 6, $result );
	}

	/**
	 * Test increase timeout multiplies by 3.
	 */
	public function test_ashp_increase_timeout() {
		$settings = new ActionSchedulerSettings();

		$result = $settings->ashp_increase_timeout( 30 );
		$this->assertSame( 90, $result );

		$result = $settings->ashp_increase_timeout( 60 );
		$this->assertSame( 180, $result );
	}

	/**
	 * Test increase time limit returns 120.
	 */
	public function test_ashp_increase_time_limit() {
		$settings = new ActionSchedulerSettings();

		$result = $settings->ashp_increase_time_limit();
		$this->assertSame( 120, $result );
	}

	/**
	 * Test request additional runners makes POST requests.
	 */
	public function test_ashp_request_additional_runners() {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'https_local_ssl_verify', '__return_false', 100 );

		Functions\expect( 'admin_url' )
			->times( 5 )
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->times( 5 )
			->with( \Mockery::on(
				function ( $action ) {
					return strpos( $action, 'ashp_additional_runner_' ) === 0;
				}
			) )
			->andReturn( 'test-nonce' );

		Functions\expect( 'wp_remote_post' )
			->times( 5 )
			->with(
				'https://example.com/wp-admin/admin-ajax.php',
				\Mockery::type( 'array' )
			);

		$settings = new ActionSchedulerSettings();
		$settings->ashp_request_additional_runners();
	}
}
