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

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_rest' ) );

		new Server_Pulse_Admin();
		new Server_Pulse_Ajax( $this->collector, $this->repository );
		new Server_Pulse_Cron( $this->collector, $this->repository );
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
