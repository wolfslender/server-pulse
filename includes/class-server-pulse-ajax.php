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
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector  $collector  Collector.
	 * @param Server_Pulse_Repository $repository Repository.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Repository $repository ) {
		$this->collector  = $collector;
		$this->repository = $repository;

		add_action( 'wp_ajax_server_pulse_get_snapshot', array( $this, 'get_snapshot' ) );
		add_action( 'wp_ajax_server_pulse_get_history', array( $this, 'get_history' ) );
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
