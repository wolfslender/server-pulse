<?php
/**
 * Activation routines.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation: database schema, defaults and cron.
 */
class Server_Pulse_Activator {

	/**
	 * Run activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::set_defaults();
		self::schedule_cron();

		update_option( 'server_pulse_db_version', SERVER_PULSE_DB_VERSION );
	}

	/**
	 * Create custom tables.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$samples         = $wpdb->prefix . 'sp_samples';

		$sql = "CREATE TABLE {$samples} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			captured_at datetime NOT NULL,
			source varchar(40) NOT NULL DEFAULT '',
			metric_key varchar(60) NOT NULL DEFAULT '',
			metric_value decimal(20,4) NOT NULL DEFAULT 0,
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY captured_at (captured_at),
			KEY metric_lookup (metric_key, captured_at),
			KEY source (source)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Store default settings.
	 *
	 * @return void
	 */
	private static function set_defaults() {
		$existing = get_option( 'server_pulse_settings' );

		if ( false !== $existing && is_array( $existing ) ) {
			return;
		}

		add_option( 'server_pulse_settings', Server_Pulse_Settings::defaults() );
	}

	/**
	 * Schedule the cron events.
	 *
	 * @return void
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'server_pulse_sample_event' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'server_pulse_sample_event' );
		}

		if ( ! wp_next_scheduled( 'server_pulse_cleanup_event' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'server_pulse_cleanup_event' );
		}

		if ( ! wp_next_scheduled( Server_Pulse_Storage_Scanner::EVENT ) ) {
			wp_schedule_event( time() + 600, 'daily', Server_Pulse_Storage_Scanner::EVENT );
		}
	}
}
