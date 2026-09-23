<?php
/**
 * Pro feature gating.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central place that decides whether Pro features are available.
 *
 * Server Pulse ships the full alerting stack in the free build. The Pro
 * channels (webhook, Slack, Discord, Telegram) and future Pro modules are
 * gated behind this class so the boundary lives in a single place. Until a
 * license server is wired up it returns false, and the
 * `server_pulse_is_pro` filter allows developers and tests to opt in.
 */
class Server_Pulse_License {

	/**
	 * Whether the current install is allowed to use Pro features.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		/**
		 * Filter whether Pro features are unlocked.
		 *
		 * @param bool $is_pro Current state.
		 */
		return (bool) apply_filters( 'server_pulse_is_pro', false );
	}

	/**
	 * Human readable plan label.
	 *
	 * @return string
	 */
	public static function plan_label() {
		return self::is_pro() ? __( 'Pro', 'server-pulse' ) : __( 'Free', 'server-pulse' );
	}
}
