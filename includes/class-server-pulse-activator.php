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
	 * Run schema upgrades on an existing install.
	 *
	 * @return void
	 */
	public static function upgrade() {
		self::create_tables();

		if ( ! wp_next_scheduled( 'server_pulse_alert_event' ) ) {
			wp_schedule_event( time() + 300, 'server_pulse_five_minutes', 'server_pulse_alert_event' );
		}

		update_option( 'server_pulse_db_version', SERVER_PULSE_DB_VERSION );
	}

	/**
	 * Create custom tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$samples         = $wpdb->prefix . 'sp_samples';
		$alerts          = $wpdb->prefix . 'sp_alerts';

		$samples_sql = "CREATE TABLE {$samples} (
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

		$alerts_sql = "CREATE TABLE {$alerts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			resolved_at datetime NULL DEFAULT NULL,
			last_notified datetime NULL DEFAULT NULL,
			rule_key varchar(60) NOT NULL DEFAULT '',
			metric varchar(60) NOT NULL DEFAULT '',
			severity varchar(20) NOT NULL DEFAULT 'warning',
			value decimal(20,4) NOT NULL DEFAULT 0,
			threshold decimal(20,4) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			notify_count int(11) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY rule_status (rule_key, status),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $samples_sql );
		dbDelta( $alerts_sql );
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

		if ( ! wp_next_scheduled( 'server_pulse_alert_event' ) ) {
			wp_schedule_event( time() + 300, 'server_pulse_five_minutes', 'server_pulse_alert_event' );
		}

		if ( ! wp_next_scheduled( Server_Pulse_Storage_Scanner::EVENT ) ) {
			wp_schedule_event( time() + 600, 'daily', Server_Pulse_Storage_Scanner::EVENT );
		}
	}
}
