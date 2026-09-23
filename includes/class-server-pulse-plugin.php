<?php
/**
 * Main plugin controller.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps every component of Server Pulse.
 */
final class Server_Pulse_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Server_Pulse_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Provider manager.
	 *
	 * @var Server_Pulse_Provider_Manager
	 */
	public $providers;

	/**
	 * Collector.
	 *
	 * @var Server_Pulse_Collector
	 */
	public $collector;

	/**
	 * Repository.
	 *
	 * @var Server_Pulse_Repository
	 */
	public $repository;

	/**
	 * Alert engine.
	 *
	 * @var Server_Pulse_Alerts
	 */
	public $alerts;

	/**
	 * Notifier.
	 *
	 * @var Server_Pulse_Notifier
	 */
	public $notifier;

	/**
	 * Trend analysis.
	 *
	 * @var Server_Pulse_Trends
	 */
	public $trends;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Server_Pulse_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->providers  = new Server_Pulse_Provider_Manager();
		$this->repository = new Server_Pulse_Repository();
		$this->collector  = new Server_Pulse_Collector( $this->providers, $this->repository );
		$this->notifier   = new Server_Pulse_Notifier();
		$this->alerts     = new Server_Pulse_Alerts( $this->collector, $this->notifier );
		$this->trends     = new Server_Pulse_Trends( $this->repository );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_rest' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );

		new Server_Pulse_Admin();
		new Server_Pulse_Ajax( $this->collector, $this->repository, $this->alerts, $this->trends );
		new Server_Pulse_Cron( $this->collector, $this->repository, $this->alerts, $this->trends );
	}

	/**
	 * Run schema upgrades when the stored version is behind.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'server_pulse_db_version' ) === SERVER_PULSE_DB_VERSION ) {
			return;
		}

		Server_Pulse_Activator::upgrade();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'server-pulse', false, dirname( SERVER_PULSE_BASENAME ) . '/languages' );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_rest() {
		$controller = new Server_Pulse_Rest_Controller( $this->collector, $this->repository );
		$controller->register_routes();
	}
}
