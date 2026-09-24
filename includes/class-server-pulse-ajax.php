<?php
/**
 * AJAX endpoints.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles dashboard AJAX requests.
 */
class Server_Pulse_Ajax {

	/**
	 * Collector.
	 *
	 * @var Server_Pulse_Collector
	 */
	private $collector;

	/**
	 * Repository.
	 *
	 * @var Server_Pulse_Repository
	 */
	private $repository;

	/**
	 * Alert engine.
	 *
	 * @var Server_Pulse_Alerts
	 */
	private $alerts;

	/**
	 * Trend analysis.
	 *
	 * @var Server_Pulse_Trends
	 */
	private $trends;

	/**
	 * Diagnostics advisor.
	 *
	 * @var Server_Pulse_Advisor
	 */
	private $advisor;

	/**
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector  $collector  Collector.
	 * @param Server_Pulse_Repository $repository Repository.
	 * @param Server_Pulse_Alerts     $alerts     Alert engine.
	 * @param Server_Pulse_Trends     $trends     Trend analysis.
	 * @param Server_Pulse_Advisor    $advisor    Diagnostics advisor.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Repository $repository, Server_Pulse_Alerts $alerts, Server_Pulse_Trends $trends, Server_Pulse_Advisor $advisor ) {
		$this->collector  = $collector;
		$this->repository = $repository;
		$this->alerts     = $alerts;
		$this->trends     = $trends;
		$this->advisor    = $advisor;

		add_action( 'wp_ajax_server_pulse_get_snapshot', array( $this, 'get_snapshot' ) );
		add_action( 'wp_ajax_server_pulse_get_history', array( $this, 'get_history' ) );
		add_action( 'wp_ajax_server_pulse_get_alerts', array( $this, 'get_alerts' ) );
		add_action( 'wp_ajax_server_pulse_get_trends', array( $this, 'get_trends' ) );
		add_action( 'wp_ajax_server_pulse_get_advisor', array( $this, 'get_advisor' ) );
		add_action( 'wp_ajax_server_pulse_run_fix', array( $this, 'run_fix' ) );
		add_action( 'wp_ajax_server_pulse_send_test_alert', array( $this, 'send_test_alert' ) );
		add_action( 'wp_ajax_server_pulse_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_server_pulse_sample_now', array( $this, 'sample_now' ) );
		add_action( 'wp_ajax_server_pulse_scan_storage', array( $this, 'scan_storage' ) );
	}

	/**
	 * Verify permissions and nonce.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'server-pulse' ) ), 403 );
		}

		check_ajax_referer( 'server_pulse_nonce', 'nonce' );
	}

	/**
	 * Throttle a costly action per user.
	 *
	 * @param string $key     Action key.
	 * @param int    $seconds Window in seconds.
	 * @return void
	 */
	private function throttle( $key, $seconds = 5 ) {
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$name = 'sp_rl_' . $key . '_' . $user;

		if ( false !== get_transient( $name ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please wait a moment before running that again.', 'server-pulse' ) ),
				429
			);
		}

		set_transient( $name, 1, max( 1, (int) $seconds ) );
	}

	/**
	 * Return the current snapshot.
	 *
	 * @return void
	 */
	public function get_snapshot() {
		$this->guard();

		$force = isset( $_POST['force'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['force'] ) );

		wp_send_json_success( $this->collector->get_snapshot( $force ) );
	}

	/**
	 * Return historical series.
	 *
	 * @return void
	 */
	public function get_history() {
		$this->guard();

		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;

		wp_send_json_success(
			array(
				'days'   => $days,
				'series' => $this->repository->get_dashboard_series( $days ),
			)
		);
	}

	/**
	 * Return recent alert history.
	 *
	 * @return void
	 */
	public function get_alerts() {
		$this->guard();

		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

		wp_send_json_success(
			array(
				'active' => $this->alerts->active_count(),
				'alerts' => $this->alerts->recent( $limit ),
			)
		);
	}

	/**
	 * Return trend baselines and resource projections.
	 *
	 * @return void
	 */
	public function get_trends() {
		$this->guard();

		$snapshot = $this->collector->get_snapshot( false );
		$summary  = isset( $snapshot['summary'] ) ? $snapshot['summary'] : array();

		if ( empty( $summary ) ) {
			wp_send_json_error( array( 'message' => __( 'No metrics available yet.', 'server-pulse' ) ) );
		}

		wp_send_json_success( $this->trends->compute( $summary ) );
	}

	/**
	 * Run (or return cached) diagnostics.
	 *
	 * @return void
	 */
	public function get_advisor() {
		$this->guard();

		$force = isset( $_POST['force'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['force'] ) );

		if ( $force ) {
			$this->throttle( 'advisor', 5 );
		}

		$snapshot = $this->collector->get_snapshot( false );
		$summary  = isset( $snapshot['summary'] ) ? $snapshot['summary'] : array();

		wp_send_json_success( $this->advisor->report( $force, $summary ) );
	}

	/**
	 * Run a maintenance action suggested by the advisor.
	 *
	 * @return void
	 */
	public function run_fix() {
		$this->guard();
		$this->throttle( 'fix', 5 );

		$action = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : '';
		$result = Server_Pulse_Maintenance::run( $action );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Server_Pulse_Advisor::flush();

		wp_send_json_success( $result );
	}

	/**
	 * Send a test notification through every enabled channel.
	 *
	 * @return void
	 */
	public function send_test_alert() {
		$this->guard();
		$this->throttle( 'test_alert', 10 );

		$results = $this->alerts->send_test();
		$sent    = array();
		$failed  = array();

		foreach ( $results as $channel => $result ) {
			if ( is_wp_error( $result ) ) {
				$failed[ $channel ] = $result->get_error_message();
			} elseif ( $result ) {
				$sent[] = $channel;
			} else {
				$failed[ $channel ] = __( 'The channel refused the test.', 'server-pulse' );
			}
		}

		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'No alert channel is enabled and configured yet.', 'server-pulse' ) ) );
		}

		wp_send_json_success(
			array(
				'sent'    => $sent,
				'failed'  => $failed,
				'message' => empty( $failed )
					? __( 'Test notification sent.', 'server-pulse' )
					: __( 'Test finished with some errors.', 'server-pulse' ),
			)
		);
	}

	/**
	 * Test a provider connection.
	 *
	 * @return void
	 */
	public function test_connection() {
		$this->guard();

		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$manager     = new Server_Pulse_Provider_Manager();
		$provider    = $manager->get( $provider_id );

		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'server-pulse' ) ) );
		}

		if ( method_exists( $provider, 'diagnose' ) ) {
			$result = $provider->diagnose();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}

		if ( ! method_exists( $provider, 'test_connection' ) ) {
			wp_send_json_error( array( 'message' => __( 'This provider does not support connection tests.', 'server-pulse' ) ) );
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Store a sample immediately.
	 *
	 * @return void
	 */
	public function sample_now() {
		$this->guard();
		$this->throttle( 'sample', 5 );

		$written = $this->collector->sample_now();

		wp_send_json_success(
			array(
				'written' => $written,
				'message' => sprintf(
					/* translators: %d: rows written. */
					__( '%d metric rows stored.', 'server-pulse' ),
					$written
				),
			)
		);
	}

	/**
	 * Run the local storage scan immediately.
	 *
	 * @return void
	 */
	public function scan_storage() {
		$this->guard();
		$this->throttle( 'scan', 30 );

		if ( ! (int) Server_Pulse_Settings::get( 'enable_storage_scan', 1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Local storage scan is disabled in settings.', 'server-pulse' ) ) );
		}

		$data = Server_Pulse_Storage_Scanner::scan();

		delete_transient( Server_Pulse_Collector::CACHE_KEY );

		wp_send_json_success(
			array(
				'total'   => (int) $data['total'],
				'time'    => (int) $data['time'],
				'message' => sprintf(
					/* translators: 1: formatted size, 2: duration in seconds. */
					__( 'Storage scan complete: %1$s in %2$ss.', 'server-pulse' ),
					Server_Pulse_Util::format_bytes( $data['total'] ),
					$data['duration']
				),
			)
		);
	}
}
