<?php
/**
 * Custom Action Scheduler Settings
 *
 * Adapted from the Action Scheduler High Volume Plugin
 * (ref: https://github.com/prospress/action-scheduler-high-volume)
 *
 * @package FAToolkit
 * @since 1.0.7
 */

namespace FAToolkit\Modules;

/**
 * Action Scheduler Settings
 */
class ActionSchedulerSettings {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'action_scheduler_queue_runner_batch_size', array( $this, 'ashp_increase_queue_batch_size' ), 10, 2 );
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', array( $this, 'ashp_increase_concurrent_batches' ) );
		add_filter( 'action_scheduler_timeout_period', array( $this, 'ashp_increase_timeout' ) );
		add_filter( 'action_scheduler_failure_period', array( $this, 'ashp_increase_timeout' ) );
		add_action( 'action_scheduler_run_queue', array( $this, 'ashp_request_additional_runners' ), 0 );
		add_action( 'wp_ajax_nopriv_ashp_create_additional_runners', array( $this, 'ashp_create_additional_runners' ), 0 );
		add_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'ashp_increase_time_limit' ) );
	}
	/**
	 * Increase the batch size
	 *
	 * @param  int $batch_size The current batch size.
	 * @return int             The new batch size
	 */
	public function ashp_increase_queue_batch_size( $batch_size ) {
		return $batch_size * 4;
	}

	/**
	 * Increase the number of concurrent batches
	 *
	 * @param  int $concurrent_batches The current number of concurrent batches.
	 * @return int                     The new number of concurrent batches
	 */
	public function ashp_increase_concurrent_batches( $concurrent_batches ) {
		return $concurrent_batches * 2;
	}

	/**
	 * Increase the timeout period
	 *
	 * @param  int $timeout The current timeout period.
	 * @return int          The new timeout period
	 */
	public function ashp_increase_timeout( $timeout ) {
		return $timeout * 3;
	}

	/**
	 * Request additional runners
	 */
	public function ashp_request_additional_runners() {

		// allow self-signed SSL certificates.
		add_filter( 'https_local_ssl_verify', '__return_false', 100 );

		for ( $i = 0; $i < 5; $i++ ) {
			$response = wp_remote_post(
				admin_url( 'admin-ajax.php' ),
				array(
					'method'      => 'POST',
					'timeout'     => 45,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => false,
					'headers'     => array(),
					'body'        => array(
						'action'     => 'ashp_create_additional_runners',
						'instance'   => $i,
						'ashp_nonce' => wp_create_nonce( 'ashp_additional_runner_' . $i ),
					),
					'cookies'     => array(),
				)
			);
		}
	}

	/**
	 * Create additional runners
	 */
	public function ashp_create_additional_runners() {
		if ( isset( $_POST['ashp_nonce'] ) &&
			isset( $_POST['instance'] ) &&
			wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['ashp_nonce'] ) ),
				'ashp_additional_runner_' . sanitize_text_field( wp_unslash( $_POST['instance'] ) )
			) ) {
			ActionScheduler_QueueRunner::instance()->run();
		} else {
			wp_die();
		}
	}

	/**
	 * Increase the time limit
	 *
	 * @return int The new time limit
	 */
	public function ashp_increase_time_limit() {
		return 120;
	}

}
new ActionSchedulerSettings();
