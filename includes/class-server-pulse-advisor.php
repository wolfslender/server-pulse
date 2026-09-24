<?php
/**
 * Diagnostics advisor.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs read-only checks across the server, WordPress, the database and
 * security, and turns the results into findings with a concrete recommended
 * fix. It never enables WP_DEBUG: it inspects configuration, data and logs
 * that already exist.
 */
class Server_Pulse_Advisor {

	/**
	 * Report cache key.
	 */
	const CACHE_KEY = 'server_pulse_advisor';

	/**
	 * Cache lifetime in seconds (refreshed on every sample).
	 */
	const CACHE_TTL = 43200;

	/**
	 * Severity order for sorting (lower is more urgent).
	 *
	 * @var array
	 */
	const ORDER = array(
		'critical' => 0,
		'warning'  => 1,
		'info'     => 2,
		'good'     => 3,
	);

	/**
	 * Build (or return the cached) diagnostic report.
	 *
	 * @param bool  $force   Bypass the cache.
	 * @param array $summary Optional merged metrics summary.
	 * @return array
	 */
	public function report( $force = false, array $summary = array() ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$host     = Server_Pulse_Host_Detector::detect( true );
		$findings = array_merge(
			$this->host_checks( $host ),
			$this->sentinel_checks(),
			$this->server_checks(),
			$this->wordpress_checks(),
			$this->database_checks( $summary ),
			$this->storage_checks( $summary )
		);

		usort( $findings, array( $this, 'compare' ) );

		$report = array(
			'generated_at' => time(),
			'host'         => $host,
			'counts'       => $this->counts( $findings ),
			'findings'     => $findings,
		);

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * Clear the cached report.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'server_pulse_risk_plugins' );
	}

	/**
	 * Return the cached report without triggering any checks.
	 *
	 * @return array|null
	 */
	public static function cached_report() {
		$cached = get_transient( self::CACHE_KEY );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Installed plugins that declare requirements the environment cannot meet.
	 *
	 * Kept lightweight and cached for a day: it only reads plugin headers.
	 *
	 * @return array
	 */
	public static function at_risk_plugins() {
		$cached = get_transient( 'server_pulse_risk_plugins' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$risky = array();
		$php   = PHP_VERSION;
		$wp    = get_bloginfo( 'version' );

		foreach ( (array) get_plugins() as $file => $data ) {
			$requires_php = isset( $data['RequiresPHP'] ) ? trim( (string) $data['RequiresPHP'] ) : '';
			$requires_wp  = isset( $data['Requires'] ) ? trim( (string) $data['Requires'] ) : '';

			if ( '' !== $requires_php && version_compare( $php, $requires_php, '<' ) ) {
				$risky[] = array(
					'plugin' => $file,
					'name'   => isset( $data['Name'] ) ? $data['Name'] : $file,
					'reason' => sprintf(
						/* translators: %s: required PHP version. */
						__( 'requires PHP %s or newer', 'server-pulse' ),
						$requires_php
					),
				);
				continue;
			}

			if ( '' !== $requires_wp && version_compare( $wp, $requires_wp, '<' ) ) {
				$risky[] = array(
					'plugin' => $file,
					'name'   => isset( $data['Name'] ) ? $data['Name'] : $file,
					'reason' => sprintf(
						/* translators: %s: required WordPress version. */
						__( 'requires WordPress %s or newer', 'server-pulse' ),
						$requires_wp
					),
				);
			}
		}

		set_transient( 'server_pulse_risk_plugins', $risky, DAY_IN_SECONDS );

		return $risky;
	}

	/**
	 * Detected-host finding.
	 *
	 * @param array $host Host detection result.
	 * @return array
	 */
	private function host_checks( array $host ) {
		$type_labels = array(
			'panel'     => __( 'control panel', 'server-pulse' ),
			'managed'   => __( 'managed WordPress host', 'server-pulse' ),
			'cloud'     => __( 'cloud / VPS', 'server-pulse' ),
			'container' => __( 'container platform', 'server-pulse' ),
			'unknown'   => __( 'generic host', 'server-pulse' ),
		);

		$type = isset( $type_labels[ $host['type'] ] ) ? $type_labels[ $host['type'] ] : $host['type'];

		return array(
			$this->finding(
				'host',
				'server',
				'good',
				sprintf(
					/* translators: %s: host label. */
					__( 'Hosting environment: %s', 'server-pulse' ),
					$host['label']
				),
				sprintf(
					/* translators: 1: host label, 2: environment type. */
					__( 'Server Pulse detected %1$s (%2$s). Provider-specific metrics will be pulled automatically when an integration for it is configured.', 'server-pulse' ),
					$host['label'],
					$type
				),
				__( 'No action needed. If this host exposes an API, add its provider in Settings to unlock plan limits and account-level metrics.', 'server-pulse' ),
				$host['id'],
				array( 'signals' => $host['signals'] )
			),
		);
	}

	/**
	 * Recent activation-crash checks.
	 *
	 * @return array
	 */
	private function sentinel_checks() {
		if ( ! class_exists( 'Server_Pulse_Sentinel' ) ) {
			return array();
		}

		$crashes = Server_Pulse_Sentinel::crash_history();

		if ( ! $crashes ) {
			return array();
		}

		$latest = $crashes[0];
		$count  = count( $crashes );
		$plugin = isset( $latest['plugin'] ) ? (string) $latest['plugin'] : '';
		$when   = isset( $latest['detected_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $latest['detected_at'] ) : '';
		$rolled = ! empty( $latest['rolled_back'] );

		return array(
			$this->finding(
				'activation_crash',
				'server',
				$rolled ? 'warning' : 'critical',
				sprintf(
					/* translators: %d: number of crashes. */
					_n( '%d activation crash detected', '%d activation crashes detected', $count, 'server-pulse' ),
					$count
				),
				sprintf(
					/* translators: 1: plugin file, 2: UTC time, 3: cause. */
					__( 'The most recent crash happened while activating %1$s (%2$s UTC). %3$s', 'server-pulse' ),
					$plugin,
					$when,
					Server_Pulse_Sentinel::cause( $latest )
				),
				$rolled
					? __( 'The offending plugin was rolled back. Review why it crashed before retrying.', 'server-pulse' )
					: __( 'A crash notice on the plugins screen lets you deactivate the offending plugin and restore the previous active list.', 'server-pulse' ),
				$count
			),
		);
	}

	/**
	 * Server / PHP environment checks.
	 *
	 * @return array
	 */
	private function server_checks() {
		$findings = array();

		// PHP version.
		$php = PHP_VERSION;
		if ( version_compare( $php, '7.4', '<' ) ) {
			$findings[] = $this->finding(
				'php_version',
				'server',
				'critical',
				sprintf( /* translators: %s: PHP version. */ __( 'PHP %s is end of life', 'server-pulse' ), $php ),
				__( 'This PHP version no longer receives security fixes, which leaves the site exposed.', 'server-pulse' ),
				__( 'Upgrade to PHP 8.1 or newer. Most hosts let you change the PHP version from the panel in one click.', 'server-pulse' ),
				$php
			);
		} elseif ( version_compare( $php, '8.1', '<' ) ) {
			$findings[] = $this->finding(
				'php_version',
				'server',
				'warning',
				sprintf( /* translators: %s: PHP version. */ __( 'PHP %s is getting old', 'server-pulse' ), $php ),
				__( 'Newer PHP versions are faster and still receive security updates.', 'server-pulse' ),
				__( 'Plan an upgrade to PHP 8.1 or 8.2 after testing your plugins and theme.', 'server-pulse' ),
				$php
			);
		} else {
			$findings[] = $this->finding(
				'php_version',
				'server',
				'good',
				sprintf( /* translators: %s: PHP version. */ __( 'PHP %s is supported', 'server-pulse' ), $php ),
				__( 'The running PHP version is current and supported.', 'server-pulse' ),
				'',
				$php
			);
		}

		// Memory limit.
		$memory = ini_get( 'memory_limit' );
		$memory = is_string( $memory ) ? trim( $memory ) : '';
		$bytes  = '' !== $memory ? Server_Pulse_Util::to_bytes( $memory ) : 0;

		if ( '' === $memory || ( $bytes <= 0 && '-1' !== $memory ) ) {
			// Not reported or an unrecognized value: skip instead of mislabeling.
		} elseif ( '-1' === $memory ) {
			$findings[] = $this->finding(
				'memory_limit',
				'server',
				'info',
				__( 'PHP memory limit is unlimited', 'server-pulse' ),
				__( 'An unlimited memory limit can hide runaway plugins and take down the whole server.', 'server-pulse' ),
				__( 'Set a sane ceiling such as 256M to catch memory leaks early.', 'server-pulse' ),
				$memory
			);
		} elseif ( $bytes < 64 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'memory_limit',
				'server',
				'critical',
				sprintf( /* translators: %s: memory limit. */ __( 'PHP memory limit is very low (%s)', 'server-pulse' ), $memory ),
				__( 'Plugins and the block editor routinely need more than 64M and will fatal with "Allowed memory size exhausted".', 'server-pulse' ),
				__( 'Raise memory_limit to at least 256M in php.ini or via your host panel.', 'server-pulse' ),
				$memory
			);
		} elseif ( $bytes < 128 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'memory_limit',
				'server',
				'warning',
				sprintf( /* translators: %s: memory limit. */ __( 'PHP memory limit is low (%s)', 'server-pulse' ), $memory ),
				__( '128M is below the recommended minimum for a modern WordPress site.', 'server-pulse' ),
				__( 'Raise memory_limit to 256M.', 'server-pulse' ),
				$memory
			);
		} else {
			$findings[] = $this->finding(
				'memory_limit',
				'server',
				'good',
				sprintf( /* translators: %s: memory limit. */ __( 'PHP memory limit is %s', 'server-pulse' ), $memory ),
				__( 'The memory limit is adequate.', 'server-pulse' ),
				'',
				$memory
			);
		}

		// Max execution time.
		$execution = (int) ini_get( 'max_execution_time' );
		if ( 0 === $execution ) {
			$findings[] = $this->finding(
				'max_execution',
				'server',
				'info',
				__( 'Maximum execution time is unlimited', 'server-pulse' ),
				__( 'A request that hangs will not be stopped and can hold a PHP worker for a long time.', 'server-pulse' ),
				__( 'Consider a limit such as 60 seconds, and use WP-Cron or a queue for long tasks.', 'server-pulse' ),
				$execution
			);
		} elseif ( $execution < 30 ) {
			$findings[] = $this->finding(
				'max_execution',
				'server',
				'warning',
				sprintf( /* translators: %d: seconds. */ __( 'Maximum execution time is only %ds', 'server-pulse' ), $execution ),
				__( 'Imports, backups and large updates may time out.', 'server-pulse' ),
				__( 'Raise max_execution_time to at least 60 for admin-heavy sites.', 'server-pulse' ),
				$execution
			);
		}

		// Upload / post size.
		$upload = Server_Pulse_Util::to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post   = Server_Pulse_Util::to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $upload > 0 && $upload < 8 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'upload_max',
				'server',
				'warning',
				sprintf( /* translators: %s: size. */ __( 'Maximum upload size is small (%s)', 'server-pulse' ), Server_Pulse_Util::format_bytes( $upload ) ),
				__( 'You may be unable to upload themes, plugins or media above this size.', 'server-pulse' ),
				__( 'Raise upload_max_filesize and post_max_size to at least 32M.', 'server-pulse' ),
				$upload
			);
		}

		if ( $post > 0 && $upload > $post ) {
			$findings[] = $this->finding(
				'post_max',
				'server',
				'warning',
				__( 'post_max_size is smaller than upload_max_filesize', 'server-pulse' ),
				__( 'A single POST cannot exceed post_max_size even if the file limit is higher.', 'server-pulse' ),
				__( 'Set post_max_size equal to or larger than upload_max_filesize.', 'server-pulse' ),
				$post
			);
		}

		// OPcache.
		if ( ! $this->ini_bool( 'opcache.enable' ) ) {
			$findings[] = $this->finding(
				'opcache',
				'server',
				'warning',
				__( 'PHP OPcache is disabled', 'server-pulse' ),
				__( 'Without OPcache, PHP recompiles every file on every request, which noticeably slows the site.', 'server-pulse' ),
				__( 'Enable OPcache (opcache.enable=1) in php.ini or your host panel.', 'server-pulse' ),
				false
			);
		}

		// WP-Cron.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$findings[] = $this->finding(
				'wp_cron',
				'server',
				'info',
				__( 'WP-Cron is disabled', 'server-pulse' ),
				__( 'If no real system cron is configured, scheduled tasks (backups, alerts, updates) will never run.', 'server-pulse' ),
				__( 'Make sure a server cron hits wp-cron.php on a schedule (for example every 5 minutes).', 'server-pulse' ),
				true
			);
		}

		// HTTPS.
		if ( ! is_ssl() ) {
			$findings[] = $this->finding(
				'https',
				'server',
				'warning',
				__( 'The site is not served over HTTPS', 'server-pulse' ),
				__( 'Traffic is unencrypted and browsers flag the site as "Not secure".', 'server-pulse' ),
				__( 'Install an SSL certificate (Let\'s Encrypt is free) and force HTTPS.', 'server-pulse' ),
				false
			);
		}

		// Debug configuration.
		$debug        = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

		if ( $debug && $debug_display ) {
			$findings[] = $this->finding(
				'wp_debug',
				'security',
				'critical',
				__( 'WP_DEBUG and WP_DEBUG_DISPLAY are enabled', 'server-pulse' ),
				__( 'PHP errors and warnings are printed on the page, exposing file paths and database details to visitors.', 'server-pulse' ),
				__( 'In production set WP_DEBUG_DISPLAY to false (and WP_DEBUG_LOG to true if you need a log).', 'server-pulse' ),
				true
			);
		} elseif ( $debug ) {
			$findings[] = $this->finding(
				'wp_debug',
				'security',
				'warning',
				__( 'WP_DEBUG is enabled', 'server-pulse' ),
				__( 'Debug mode is fine while developing but should be off on a live site.', 'server-pulse' ),
				__( 'Turn WP_DEBUG off in production and rely on WP_DEBUG_LOG if needed.', 'server-pulse' ),
				true
			);
		}

		// Debug log.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$log = WP_CONTENT_DIR . '/debug.log';
			if ( file_exists( $log ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size = (int) @filesize( $log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $size > 5 * MB_IN_BYTES ) {
					$findings[] = $this->finding(
						'debug_log',
						'server',
						'warning',
						sprintf( /* translators: %s: size. */ __( 'debug.log is large (%s)', 'server-pulse' ), Server_Pulse_Util::format_bytes( $size ) ),
						__( 'A large debug.log usually means recurring errors and wastes disk space.', 'server-pulse' ),
						__( 'Review and clear wp-content/debug.log, then fix the errors that keep filling it.', 'server-pulse' ),
						$size,
						array( 'action' => 'clear_debug_log' )
					);
				} else {
					$findings[] = $this->finding(
						'debug_log',
						'server',
						'info',
						__( 'A debug.log file exists', 'server-pulse' ),
						sprintf( /* translators: %s: size. */ __( 'The current log is %s.', 'server-pulse' ), Server_Pulse_Util::format_bytes( $size ) ),
						__( 'Keep an eye on it, or clear it once you have reviewed the entries.', 'server-pulse' ),
						$size,
						array( 'action' => 'clear_debug_log' )
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * WordPress configuration and update checks.
	 *
	 * @return array
	 */
	private function wordpress_checks() {
		$findings = array();

		// Search engine visibility.
		if ( ! get_option( 'blog_public', 1 ) ) {
			$findings[] = $this->finding(
				'blog_public',
				'wordpress',
				'info',
				__( 'Search engines are discouraged', 'server-pulse' ),
				__( '"Discourage search engines" is checked, so the site is hidden from Google.', 'server-pulse' ),
				__( 'If this is a live site, turn it off in Settings → Reading.', 'server-pulse' ),
				false
			);
		}

		// File editor.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
			$findings[] = $this->finding(
				'file_edit',
				'security',
				'warning',
				__( 'The built-in plugin/theme file editor is enabled', 'server-pulse' ),
				__( 'Anyone who gains admin access can execute arbitrary PHP through the editor.', 'server-pulse' ),
				__( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'server-pulse' ),
				false
			);
		}

		// Permalinks.
		if ( ! get_option( 'permalink_structure' ) ) {
			$findings[] = $this->finding(
				'permalinks',
				'wordpress',
				'info',
				__( 'Permalinks use the default format', 'server-pulse' ),
				__( 'Plain permalinks are worse for SEO and can be harder to read.', 'server-pulse' ),
				__( 'Choose a pretty permalink structure in Settings → Permalinks.', 'server-pulse' ),
				''
			);
		}

		// Updates.
		if ( function_exists( 'wp_get_update_data' ) ) {
			$updates = wp_get_update_data();
			$counts  = isset( $updates['counts'] ) ? $updates['counts'] : array();
			$total   = 0;
			$detail  = array();

			foreach ( array( 'core' => __( 'core', 'server-pulse' ), 'plugins' => __( 'plugins', 'server-pulse' ), 'themes' => __( 'themes', 'server-pulse' ) ) as $key => $label ) {
				$n = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
				if ( $n > 0 ) {
					$total += $n;
					$detail[] = sprintf( '%d %s', $n, $label );
				}
			}

			if ( $total > 0 ) {
				$findings[] = $this->finding(
					'updates',
					'wordpress',
					'info',
					sprintf( /* translators: %d: number of updates. */ _n( '%d update available', '%d updates available', $total, 'server-pulse' ), $total ),
					__( 'Updates carry security fixes; running behind is the most common cause of a hacked site.', 'server-pulse' ),
					__( 'Apply the pending updates from Dashboard → Updates after a quick backup.', 'server-pulse' ),
					$total,
					array( 'detail' => implode( ', ', $detail ) )
				);
			}
		}

		// Object cache.
		if ( ! wp_using_ext_object_cache() ) {
			$findings[] = $this->finding(
				'object_cache',
				'performance',
				'warning',
				__( 'No persistent object cache', 'server-pulse' ),
				__( 'Every request rebuilds cached objects from the database, which limits scalability on busy sites.', 'server-pulse' ),
				__( 'Install Redis or Memcached and enable a persistent object cache drop-in.', 'server-pulse' ),
				false
			);
		}

		return $findings;
	}

	/**
	 * Database hygiene checks.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	private function database_checks( array $summary ) {
		global $wpdb;

		$findings = array();

		// Autoloaded options.
		$autoload = isset( $summary['db_autoload'] ) ? (float) $summary['db_autoload'] : 0;
		if ( $autoload >= 2 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'db_autoload',
				'database',
				'critical',
				sprintf( /* translators: %s: size. */ __( 'Autoloaded options are %s', 'server-pulse' ), Server_Pulse_Util::format_bytes( $autoload ) ),
				__( 'Autoloaded options load on every single request; a bloated autoload is one of the top causes of slow sites.', 'server-pulse' ),
				__( 'Audit and clean large autoloaded options (often left by plugins) so the total drops below 1 MB.', 'server-pulse' ),
				$autoload
			);
		} elseif ( $autoload >= 1 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'db_autoload',
				'database',
				'warning',
				sprintf( /* translators: %s: size. */ __( 'Autoloaded options are %s', 'server-pulse' ), Server_Pulse_Util::format_bytes( $autoload ) ),
				__( 'The autoload is approaching the recommended 1 MB ceiling.', 'server-pulse' ),
				__( 'Review recently added plugins; their options are often the culprit.', 'server-pulse' ),
				$autoload
			);
		}

		// Revisions.
		$revisions = isset( $summary['db_revisions'] ) ? (int) $summary['db_revisions'] : 0;
		if ( $revisions > 1000 ) {
			$findings[] = $this->finding(
				'db_revisions',
				'database',
				'warning',
				sprintf( /* translators: %d: revision count. */ __( '%d post revisions stored', 'server-pulse' ), $revisions ),
				__( 'Revisions grow the database and slow down queries for the posts they belong to.', 'server-pulse' ),
				__( 'Delete old revisions or cap WP_POST_REVISIONS in wp-config.php.', 'server-pulse' ),
				$revisions,
				array( 'action' => 'delete_revisions' )
			);
		}

		// Expired transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );
		if ( $expired > 500 ) {
			$findings[] = $this->finding(
				'db_transients',
				'database',
				'warning',
				sprintf( /* translators: %d: number of transients. */ __( '%d expired transients', 'server-pulse' ), $expired ),
				__( 'Expired transients are dead rows that are never cleaned up and bloat the options table.', 'server-pulse' ),
				__( 'Delete the expired transients; they will be regenerated on demand.', 'server-pulse' ),
				$expired,
				array( 'action' => 'purge_transients' )
			);
		}

		// Table overhead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$overhead = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(data_free),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );
		if ( $overhead > 128 * MB_IN_BYTES ) {
			$findings[] = $this->finding(
				'db_overhead',
				'database',
				'info',
				sprintf( /* translators: %s: size. */ __( 'Tables have %s of free/overhead space', 'server-pulse' ), Server_Pulse_Util::format_bytes( $overhead ) ),
				__( 'Fragmented tables waste space and can slow queries.', 'server-pulse' ),
				__( 'Optimize the database tables (phpMyAdmin → Optimize, or a maintenance plugin).', 'server-pulse' ),
				$overhead
			);
		}

		return $findings;
	}

	/**
	 * Storage checks.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	private function storage_checks( array $summary ) {
		$findings = array();

		if ( ! empty( $summary['storage_scan_total'] ) ) {
			$findings[] = $this->finding(
				'storage',
				'storage',
				'info',
				sprintf( /* translators: %s: size. */ __( 'wp-content uses %s', 'server-pulse' ), Server_Pulse_Util::format_bytes( $summary['storage_scan_total'] ) ),
				__( 'This is the local storage footprint of your WordPress content directories.', 'server-pulse' ),
				__( 'Review uploads, plugins and themes for anything you no longer need.', 'server-pulse' ),
				(int) $summary['storage_scan_total']
			);
		}

		return $findings;
	}

	/**
	 * Tally findings per severity.
	 *
	 * @param array $findings Findings.
	 * @return array
	 */
	private function counts( array $findings ) {
		$counts = array(
			'critical' => 0,
			'warning'  => 0,
			'info'     => 0,
			'good'     => 0,
		);

		foreach ( $findings as $finding ) {
			$severity = $finding['severity'];
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		$counts['actionable'] = $counts['critical'] + $counts['warning'];

		return $counts;
	}

	/**
	 * Sort findings by severity then category.
	 *
	 * @param array $a Finding.
	 * @param array $b Finding.
	 * @return int
	 */
	private function compare( $a, $b ) {
		$ra = isset( self::ORDER[ $a['severity'] ] ) ? self::ORDER[ $a['severity'] ] : 9;
		$rb = isset( self::ORDER[ $b['severity'] ] ) ? self::ORDER[ $b['severity'] ] : 9;

		if ( $ra === $rb ) {
			return strcmp( (string) $a['title'], (string) $b['title'] );
		}

		return $ra <=> $rb;
	}

	/**
	 * Build a finding array.
	 *
	 * @param string $id             Stable id.
	 * @param string $category       server|wordpress|database|security|performance|storage.
	 * @param string $severity       critical|warning|info|good.
	 * @param string $title          Short title.
	 * @param string $explanation    What it means.
	 * @param string $recommendation Suggested fix.
	 * @param mixed  $value          Raw value.
	 * @param array  $extra          Extra keys (action, detail, signals…).
	 * @return array
	 */
	private function finding( $id, $category, $severity, $title, $explanation, $recommendation, $value = null, array $extra = array() ) {
		return array_merge(
			array(
				'id'             => $id,
				'category'       => $category,
				'severity'       => $severity,
				'title'          => $title,
				'explanation'    => $explanation,
				'recommendation' => $recommendation,
				'value'          => $value,
			),
			$extra
		);
	}

	/**
	 * Read a boolean php.ini directive.
	 *
	 * @param string $key Directive.
	 * @return bool
	 */
	private function ini_bool( $key ) {
		$value = ini_get( $key );

		if ( false === $value || '' === $value ) {
			return false;
		}

		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}