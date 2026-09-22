<?php
/**
 * Provider contract.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every data provider must implement this interface.
 */
interface Server_Pulse_Provider_Interface {

	/**
	 * Unique machine identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Short description shown in the settings screen.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Whether this provider can run in the current environment.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Collect a normalized snapshot.
	 *
	 * Return an array shaped as:
	 * array(
	 *   'source'  => 'native',
	 *   'metrics' => array( 'cpu_percent' => 12.3, ... ),
	 *   'notes'   => array( 'Optional human readable notes.' ),
	 * )
	 *
	 * @return array
	 */
	public function collect();
}
