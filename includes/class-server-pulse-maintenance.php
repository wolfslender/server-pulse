<?php
/**
 * Safe maintenance actions suggested by the advisor.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reversible, well-understood cleanups the user can trigger from the
 * diagnostics screen. Every method is capability-checked by the caller.
 */
class Server_Pulse_Maintenance {

	/**
	 * Maximum rows touched per run, to keep requests bounded.
	 */
	const BATCH = 5000;

	/**
	 * Delete expired transients (both site and network rows).
	 *
	 * @return int Number of transients deleted.
	 */
	public static function purge_expired_transients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time(),
				self::BATCH
			),
			ARRAY_A
		);

		$deleted = 0;

		foreach ( (array) $rows as $row ) {
			$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';

			if ( 0 === strpos( $name, '_site_transient_timeout_' ) ) {
				$key   = substr( $name, strlen( '_site_transient_timeout_' ) );
				$names = array( $name, '_site_transient_' . $key );
			} elseif ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				$key   = substr( $name, strlen( '_transient_timeout_' ) );
				$names = array( $name, '_transient_' . $key );
			} else {
				continue;
			}

			foreach ( $names as $option_name ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $option_name ), array( '%s' ) );
			}

			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Delete old post revisions, keeping the most recent ones per post.
	 *
	 * Respects the WP_POST_REVISIONS constant (false disables revisions
	 * entirely and deletes them all; a number caps how many are kept).
	 *
	 * @return int Revisions deleted.
	 */
	public static function delete_revisions() {
		global $wpdb;

		$keep = 5;

		if ( defined( 'WP_POST_REVISIONS' ) ) {
			if ( false === WP_POST_REVISIONS ) {
				$keep = 0;
			} elseif ( true === WP_POST_REVISIONS ) {
				$keep = 5;
			} else {
				$keep = max( 0, (int) WP_POST_REVISIONS );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'revision' AND ( SELECT COUNT(*) FROM {$wpdb->posts} r WHERE r.post_parent = p.post_parent AND r.post_type = 'revision' AND ( r.post_date > p.post_date OR ( r.post_date = p.post_date AND r.ID > p.ID ) ) ) >= %d LIMIT %d",
				$keep,
				self::BATCH
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$placeholders})", $ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $ids ) );

		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
		}

		return $deleted;
	}

	/**
	 * Truncate wp-content/debug.log when it exists and is writable.
	 *
	 * @return bool
	 */
	public static function clear_debug_log() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return false;
		}

		$log = WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $log ) || ! is_writable( $log ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = @file_put_contents( $log, '' );

		return false !== $result;
	}

	/**
	 * Run a named maintenance action.
	 *
	 * @param string $action Action id.
	 * @return array|WP_Error { deleted:int, message:string } on success.
	 */
	public static function run( $action ) {
		switch ( $action ) {
			case 'purge_transients':
				$deleted = self::purge_expired_transients();
				return array(
					'deleted' => $deleted,
					'message' => sprintf(
						/* translators: %d: rows deleted. */
						_n( '%d expired transient removed.', '%d expired transients removed.', $deleted, 'server-pulse' ),
						$deleted
					),
				);

			case 'delete_revisions':
				$deleted = self::delete_revisions();
				return array(
					'deleted' => $deleted,
					'message' => sprintf(
						/* translators: %d: revisions deleted. */
						_n( '%d revision deleted.', '%d revisions deleted.', $deleted, 'server-pulse' ),
						$deleted
					),
				);

			case 'clear_debug_log':
				$ok = self::clear_debug_log();
				return $ok
					? array(
						'deleted' => 0,
						'message' => __( 'The debug log was cleared.', 'server-pulse' ),
					)
					: new WP_Error( 'server_pulse_debug_log', __( 'The debug log could not be cleared (missing or not writable).', 'server-pulse' ) );
		}

		return new WP_Error( 'server_pulse_unknown_fix', __( 'Unknown maintenance action.', 'server-pulse' ) );
	}
}