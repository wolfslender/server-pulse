<?php
/**
 * Provider registry.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and exposes all data providers.
 */
class Server_Pulse_Provider_Manager {

	/**
	 * Registered providers keyed by id.
	 *
	 * @var Server_Pulse_Provider_Interface[]
	 */
	private $providers = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register( new Server_Pulse_Native_Provider() );
		$this->register( new Server_Pulse_Cpanel_Provider() );
		$this->register( new Server_Pulse_WpEngine_Provider() );
		$this->register( new Server_Pulse_WordPress_Provider() );
	}

	/**
	 * Register a provider.
	 *
	 * @param Server_Pulse_Provider_Interface $provider Provider.
	 * @return void
	 */
	public function register( Server_Pulse_Provider_Interface $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * All providers.
	 *
	 * @return Server_Pulse_Provider_Interface[]
	 */
	public function all() {
		return $this->providers;
	}

	/**
	 * Retrieve a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return Server_Pulse_Provider_Interface|null
	 */
	public function get( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Providers that are enabled and configured.
	 *
	 * @return Server_Pulse_Provider_Interface[]
	 */
	public function available() {
		return array_filter(
			$this->providers,
			static function ( $provider ) {
				return $provider->is_available();
			}
		);
	}

	/**
	 * Describe provider status for the UI.
	 *
	 * @return array
	 */
	public function statuses() {
		$statuses = array();

		foreach ( $this->providers as $id => $provider ) {
			$statuses[] = array(
				'id'          => $id,
				'label'       => $provider->get_label(),
				'description' => $provider->get_description(),
				'available'   => $provider->is_available(),
				'detected'    => $this->is_detected( $id ),
			);
		}

		return $statuses;
	}

	/**
	 * Whether the current host looks like the provider's platform.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	private function is_detected( $id ) {
		switch ( $id ) {
			case 'wpengine':
			case 'cpanel':
				$detected = Server_Pulse_Host_Detector::detect();

				foreach ( (array) ( isset( $detected['matches'] ) ? $detected['matches'] : array() ) as $match ) {
					if ( isset( $match['id'] ) && $id === $match['id'] ) {
						return true;
					}
				}

				return false;
			case 'native':
				return (bool) Server_Pulse_Util::has_function( 'sys_getloadavg' ) || is_readable( '/proc/meminfo' );
			case 'wordpress':
				return true;
		}

		return false;
	}
}
