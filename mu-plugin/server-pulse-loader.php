<?php
/**
 * Plugin Name: Server Pulse Crash Loader
 * Description: Emergency guard installed by Server Pulse. Detects a plugin activation that killed PHP (502 / OOM / timeout) and deactivates the offending plugin before regular plugins load, so the admin stays reachable.
 * Version: 1.1.0
 * Author: Alexis Olivero
 * License: GPL-2.0-or-later
 *
 * This file is copied into wp-content/mu-plugins/ by Server Pulse. It must stay
 * self-contained and must not depend on any other plugin file, because it runs
 * before regular plugins are loaded. Removing it only disables the early guard;
 * the in-plugin sentinel keeps working.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SERVER_PULSE_SENTINEL_LOADER' ) ) {
	return;
}

define( 'SERVER_PULSE_SENTINEL_LOADER', true );

( static function () {
	if ( ! function_exists( 'get_option' ) ) {
		return;
	}

	$marker = get_option( 'server_pulse_sentinel' );

	if ( ! is_array( $marker ) || empty( $marker['started_at'] ) || empty( $marker['plugins'] ) ) {
		return;
	}

	// Only trust a marker that carries a valid HMAC signature.
	$payload = static function ( array $m ) {
		return implode(
			'|',
			array(
				(string) ( isset( $m['v'] ) ? (int) $m['v'] : 1 ),
				(string) ( isset( $m['type'] ) ? $m['type'] : '' ),
				(string) ( isset( $m['action'] ) ? $m['action'] : '' ),
				(string) ( isset( $m['primary'] ) ? $m['primary'] : '' ),
				implode( ',', array_map( 'strval', (array) ( isset( $m['plugins'] ) ? $m['plugins'] : array() ) ) ),
				(string) ( isset( $m['started_at'] ) ? (int) $m['started_at'] : 0 ),
				implode( ',', array_map( 'strval', (array) ( isset( $m['prev_active'] ) ? $m['prev_active'] : array() ) ) ),
			)
		);
	};

	$salt   = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
	$secret = (string) get_option( 'server_pulse_crypto_secret', '' );
	$keys   = array(
		hash( 'sha256', $salt . $secret . 'server-pulse-sentinel', true ),
		hash( 'sha256', $salt . 'server-pulse-sentinel', true ),
	);

	$valid = false;
	$data  = $payload( $marker );

	if ( ! empty( $marker['sig'] ) ) {
		foreach ( $keys as $key ) {
			if ( hash_equals( hash_hmac( 'sha256', $data, $key ), (string) $marker['sig'] ) ) {
				$valid = true;
				break;
			}
		}
	}

	if ( ! $valid ) {
		return;
	}

	// Ignore the request that has just armed the guard, or a concurrent one.
	// The grace window is generous so a slow (but successful) activation is
	// not mistaken for a crash.
	if ( ( time() - (int) $marker['started_at'] ) < 30 ) {
		return;
	}

	$culprits = array_values( array_map( 'strval', (array) $marker['plugins'] ) );

	/*
	 * 1. Keep the culprits out of this request, before WordPress loads them.
	 *    This is the part that rescues a site where the plugin fatals on every
	 *    load and the regular admin sentinel never gets a chance to run.
	 */
	if ( function_exists( 'add_filter' ) ) {
		add_filter(
			'option_active_plugins',
			static function ( $plugins ) use ( $culprits ) {
				return array_values( array_diff( (array) $plugins, $culprits ) );
			}
		);

		add_filter(
			'option_active_sitewide_plugins',
			static function ( $plugins ) use ( $culprits ) {
				if ( ! is_array( $plugins ) ) {
					return $plugins;
				}
				foreach ( $culprits as $culprit ) {
					unset( $plugins[ $culprit ] );
				}
				return $plugins;
			}
		);
	}

	// 2. Persist the deactivation so the fix survives the next request.
	$active = get_option( 'active_plugins', array() );
	if ( is_array( $active ) ) {
		$cleaned = array_values( array_diff( $active, $culprits ) );
		if ( $cleaned !== $active ) {
			update_option( 'active_plugins', $cleaned );
		}
	}

	// 3. Record the crash so the admin gets a clear notice.
	$crash = array(
		'id'          => function_exists( 'uniqid' ) ? uniqid( 'crash_', false ) : 'crash_' . time(),
		'type'        => 'activation',
		'action'      => isset( $marker['action'] ) ? (string) $marker['action'] : 'activate',
		'plugin'      => isset( $marker['primary'] ) ? (string) $marker['primary'] : $culprits[0],
		'plugins'     => $culprits,
		'started_at'  => (int) $marker['started_at'],
		'detected_at' => time(),
		'fatal'       => isset( $marker['fatal'] ) ? $marker['fatal'] : null,
		'breadcrumbs' => isset( $marker['breadcrumbs'] ) ? (array) $marker['breadcrumbs'] : array(),
		'memory'      => isset( $marker['memory'] ) ? (int) $marker['memory'] : 0,
		'peak'        => isset( $marker['peak'] ) ? (int) $marker['peak'] : 0,
		'uri'         => isset( $marker['uri'] ) ? (string) $marker['uri'] : '',
		'prev_active' => isset( $marker['prev_active'] ) ? (array) $marker['prev_active'] : array(),
		'rolled_back' => true,
		'source'      => 'loader',
	);

	$crashes = get_option( 'server_pulse_crashes', array() );
	if ( ! is_array( $crashes ) ) {
		$crashes = array();
	}

	array_unshift( $crashes, $crash );
	$crashes = array_slice( $crashes, 0, 20 );

	update_option( 'server_pulse_crashes', $crashes, false );
	delete_option( 'server_pulse_sentinel' );

	if ( function_exists( 'set_transient' ) && defined( 'HOUR_IN_SECONDS' ) ) {
		set_transient( 'server_pulse_crash_notice', array( 'crash_id' => $crash['id'] ), HOUR_IN_SECONDS );
	}
} )();
