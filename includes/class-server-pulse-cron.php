<?php
/**
 * Scheduled sampling, alerting and retention.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the cron jobs that keep the history table populated and the alert
 * engine running.
 */
class Server_Pulse_Cron {

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
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector  $collector  Collector.
	 * @param Server_Pulse_Repository $repository Repository.
	 * @param Server_Pulse_Alerts     $alerts     Alert engine.
	 * @param Server_Pulse_Trends     $trends     Trend analysis.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Repository $repository, Server_Pulse_Alerts $alerts, Server_Pulse_Trends $trends ) {
		$this->collector  = $collector;
		$this->repository = $repository;
		$this->alerts     = $alerts;
		$this->trends     = $trends;

		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( 'server_pulse_sample_event', array( $this, 'sample' ) );
		add_action( 'server_pulse_cleanup_event', array( $this, 'cleanup' ) );
		add_action( 'server_pulse_alert_event', array( $this, 'check_alerts' ) );
		add_action( Server_Pulse_Storage_Scanner::EVENT, array( $this, 'scan_storage' ) );
	}

	/**
	 * Register the 5 minute interval used by the uptime checker.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['server_pulse_five_minutes'] ) ) {
			$schedules['server_pulse_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Server Pulse)', 'server-pulse' ),
			);
		}

		return $schedules;
	}

	/**
	 * Collect and store a sample, then evaluate threshold alerts.
	 *
	 * @return void
	 */
	public function sample() {
		$this->collector->sample_now();

		$snapshot = $this->collector->get_snapshot();

		if ( ! empty( $snapshot['summary'] ) ) {
			$this->alerts->run_thresholds( $snapshot['summary'] );
			$this->alerts->run_trends( $snapshot['summary'], $this->trends->compute( $snapshot['summary'] ) );
		}

		delete_transient( Server_Pulse_Collector::CACHE_KEY );
		delete_transient( Server_Pulse_WpEngine_Provider::CACHE_KEY );
	}

	/**
	 * Run the frequent uptime check.
	 *
	 * @return void
	 */
	public function check_alerts() {
		$this->alerts->run_uptime();
	}

	/**
	 * Purge expired samples.
	 *
	 * @return void
	 */
	public function cleanup() {
		$this->repository->purge_old( (int) Server_Pulse_Settings::get( 'retention_days', 30 ) );
	}

	/**
	 * Run the local storage scan.
	 *
	 * @return void
	 */
	public function scan_storage() {
		if ( ! (int) Server_Pulse_Settings::get( 'enable_storage_scan', 1 ) ) {
			return;
		}

		Server_Pulse_Storage_Scanner::scan();
	}
}
