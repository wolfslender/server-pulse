<?php
/**
 * Deactivation routines.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clears scheduled events on deactivation.
 */
class Server_Pulse_Deactivator {

	/**
	 * Run deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'server_pulse_sample_event' );
		wp_clear_scheduled_hook( 'server_pulse_cleanup_event' );
		wp_clear_scheduled_hook( 'server_pulse_alert_event' );
		wp_clear_scheduled_hook( Server_Pulse_Storage_Scanner::EVENT );
	}
}
