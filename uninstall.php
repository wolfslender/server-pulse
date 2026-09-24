<?php
/**
 * Uninstall routine.
 *
 * @package ServerPulse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Remove every option, transient and table owned by Server Pulse for the
 * current site.
 *
 * @return void
 */
$server_pulse_clean_site = static function () {
	global $wpdb;

	$options = array(
		'server_pulse_settings',
		'server_pulse_db_version',
		'server_pulse_sentinel',
		'server_pulse_crashes',
		'server_pulse_storage_scan',
		'server_pulse_notify_log',
		'server_pulse_crypto_secret',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$transients = array(
		'server_pulse_snapshot',
		'server_pulse_wpengine_usage',
		'server_pulse_advisor',
		'server_pulse_risk_plugins',
		'server_pulse_crash_notice',
		'server_pulse_wp_totals',
		'server_pulse_uptime_fail',
	);

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}

	$tables = array(
		$wpdb->prefix . 'sp_samples',
		$wpdb->prefix . 'sp_alerts',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
};

// Remove the early loader mu-plugin.
$server_pulse_loader = WP_CONTENT_DIR . '/mu-plugins/server-pulse-loader.php';
if ( file_exists( $server_pulse_loader ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $server_pulse_loader );
}

if ( is_multisite() ) {
	$server_pulse_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $server_pulse_sites as $server_pulse_site_id ) {
		switch_to_blog( $server_pulse_site_id );
		$server_pulse_clean_site();
		restore_current_blog();
	}
} else {
	$server_pulse_clean_site();
}
