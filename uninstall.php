<?php
/**
 * Uninstall routine.
 *
 * @package ServerPulse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove options.
delete_option( 'server_pulse_settings' );
delete_option( 'server_pulse_db_version' );

// Remove transients.
delete_transient( 'server_pulse_snapshot' );
delete_transient( 'server_pulse_wpengine_usage' );

// Drop the samples table.
$server_pulse_table = $wpdb->prefix . 'sp_samples';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$server_pulse_table}" );

// Multisite cleanup.
if ( is_multisite() ) {
	$server_pulse_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $server_pulse_sites as $server_pulse_site_id ) {
		switch_to_blog( $server_pulse_site_id );

		delete_option( 'server_pulse_settings' );
		delete_option( 'server_pulse_db_version' );
		delete_transient( 'server_pulse_snapshot' );
		delete_transient( 'server_pulse_wpengine_usage' );

		$server_pulse_table = $wpdb->prefix . 'sp_samples';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$server_pulse_table}" );

		restore_current_blog();
	}
}
