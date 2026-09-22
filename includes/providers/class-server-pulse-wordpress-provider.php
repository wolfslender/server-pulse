<?php
/**
 * WordPress-level provider.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports WordPress and database health metrics. Works on every host.
 */
class Server_Pulse_WordPress_Provider extends Server_Pulse_Abstract_Provider {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'wordpress';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WordPress & Database', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description() {
		return __( 'Database size, autoloaded options, revisions, transients, cron health, object cache and content counts. Always available.', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function collect() {
		$metrics = array_merge(
			$this->database_metrics(),
			$this->content_metrics(),
			$this->environment_metrics(),
			$this->storage_metrics()
		);

		return $this->snapshot( $metrics );
	}

	/**
	 * Local storage scan metrics (wp-content breakdown).
	 *
	 * @return array
	 */
	private function storage_metrics() {
		if ( ! (int) Server_Pulse_Settings::get( 'enable_storage_scan', 1 ) ) {
			return array();
		}

		$scan = Server_Pulse_Storage_Scanner::cached();

		if ( empty( $scan['total'] ) ) {
			return array();
		}

		$metrics = array(
			'storage_scan_total' => (int) $scan['total'],
			'storage_scan_time'  => (int) $scan['time'],
		);

		foreach ( (array) $scan['breakdown'] as $key => $info ) {
			$metrics[ 'storage_scan_' . $key ] = isset( $info['bytes'] ) ? (int) $info['bytes'] : 0;
		}

		return $metrics;
	}

	/**
	 * Database size and bloat metrics.
	 *
	 * @return array
	 */
	private function database_metrics() {
		global $wpdb;

		$metrics = array(
			'db_size'       => null,
			'db_autoload'   => null,
			'db_tables'     => null,
			'db_revisions'  => null,
			'db_transients' => null,
			'cron_overdue'  => null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );
		$metrics['db_size'] = ( null === $size ) ? null : (int) $size;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metrics['db_tables'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto')" );
		$metrics['db_autoload'] = ( null === $autoload ) ? null : (int) $autoload;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revisions = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(post_content)),0) AS size FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		if ( $revisions ) {
			$metrics['db_revisions']      = (int) $revisions->total;
			$metrics['db_revisions_size'] = (int) $revisions->size;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metrics['db_transients'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'"
		);

		$metrics['cron_overdue'] = $this->count_overdue_cron();

		return $metrics;
	}

	/**
	 * Count cron events past their scheduled time.
	 *
	 * @return int
	 */
	private function count_overdue_cron() {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$now     = time();
		$overdue = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( $timestamp < $now - 300 ) {
				foreach ( $hooks as $hook ) {
					$overdue += count( $hook );
				}
			}
		}

		return $overdue;
	}

	/**
	 * Content and user counts.
	 *
	 * @return array
	 */
	private function content_metrics() {
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );
		$users = count_users();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );

		return array(
			'posts'          => $posts ? (int) $posts->publish : 0,
			'pages'          => $pages ? (int) $pages->publish : 0,
			'comments'       => (int) wp_count_comments()->approved,
			'users'          => isset( $users['total_users'] ) ? (int) $users['total_users'] : 0,
			'plugins_active' => count( $active_plugins ),
			'plugins_total'  => count( get_plugins() ),
		);
	}

	/**
	 * Environment details.
	 *
	 * @return array
	 */
	private function environment_metrics() {
		global $wpdb;

		$theme = wp_get_theme();

		return array(
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'mysql_version'     => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : null,
			'theme'             => $theme ? $theme->get( 'Name' ) : null,
			'object_cache'      => wp_using_ext_object_cache(),
			'site_url'          => home_url(),
			'is_multisite'      => is_multisite(),
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'max_execution'     => (int) ini_get( 'max_execution_time' ),
			'upload_max'        => Server_Pulse_Util::to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			'post_max'          => Server_Pulse_Util::to_bytes( (string) ini_get( 'post_max_size' ) ),
			'opcache'           => (bool) ini_get( 'opcache.enable' ),
		);
	}
}
