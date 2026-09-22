<?php
/**
 * Scheduled sampling and retention.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the cron jobs that keep the history table populated.
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
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector  $collector  Collector.
	 * @param Server_Pulse_Repository $repository Repository.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Repository $repository ) {
		$this->collector  = $collector;
		$this->repository = $repository;

		add_action( 'server_pulse_sample_event', array( $this, 'sample' ) );
		add_action( 'server_pulse_cleanup_event', array( $this, 'cleanup' ) );
		add_action( Server_Pulse_Storage_Scanner::EVENT, array( $this, 'scan_storage' ) );
	}

	/**
	 * Collect and store a sample.
	 *
	 * @return void
	 */
	public function sample() {
		$this->collector->sample_now();

		delete_transient( Server_Pulse_Collector::CACHE_KEY );
		delete_transient( Server_Pulse_WpEngine_Provider::CACHE_KEY );
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
