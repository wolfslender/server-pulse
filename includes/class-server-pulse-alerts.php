<?php
/**
 * Alert engine: rules, de-duplication, resolution and dispatch.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates thresholds and uptime, persists alert events and hands them to
 * the notifier while respecting cooldowns and a daily notification cap.
 */
class Server_Pulse_Alerts {

	/**
	 * Collector.
	 *
	 * @var Server_Pulse_Collector
	 */
	private $collector;

	/**
	 * Notifier.
	 *
	 * @var Server_Pulse_Notifier
	 */
	private $notifier;

	/**
	 * Maps a health metric key to its alert rule key.
	 *
	 * @var array
	 */
	const RULE_MAP = array(
		'cpu_percent'        => 'cpu',
		'memory_percent'     => 'memory',
		'disk_percent'       => 'disk',
		'php_memory_percent' => 'php_memory',
		'db_autoload'        => 'autoload',
		'cron_overdue'       => 'cron',
		'object_cache'       => 'object_cache',
	);

	/**
	 * Maps a trend metric key to its alert rule key.
	 *
	 * @var array
	 */
	const TREND_RULE_MAP = array(
		'cpu_percent'        => 'cpu_trend',
		'memory_percent'     => 'memory_trend',
		'disk_percent'       => 'disk_trend',
		'php_memory_percent' => 'php_memory_trend',
	);

	/**
	 * Rules backed by the threshold engine.
	 *
	 * @var string[]
	 */
	const THRESHOLD_RULES = array(
		'cpu',
		'memory',
		'disk',
		'php_memory',
		'autoload',
		'cron',
		'object_cache',
	);

	/**
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector $collector Collector.
	 * @param Server_Pulse_Notifier  $notifier  Notifier.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Notifier $notifier ) {
		$this->collector = $collector;
		$this->notifier  = $notifier;
	}

	/**
	 * Alerts table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;

		return $wpdb->prefix . 'sp_alerts';
	}

	/**
	 * Whether the alert engine is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$alerts = Server_Pulse_Settings::get( 'alerts', array() );

		return ! empty( $alerts['enabled'] );
	}

	/**
	 * Whether a given rule is enabled.
	 *
	 * @param string $rule Rule key.
	 * @return bool
	 */
	public function rule_enabled( $rule ) {
		$alerts = Server_Pulse_Settings::get( 'alerts', array() );
		$rules  = isset( $alerts['rules'] ) && is_array( $alerts['rules'] ) ? $alerts['rules'] : array();

		return ! isset( $rules[ $rule ] ) || ! empty( $rules[ $rule ] );
	}

	/**
	 * Evaluate threshold rules against a merged summary.
	 *
	 * @param array $summary Merged metrics.
	 * @return array Triggered rule keys.
	 */
	public function run_thresholds( array $summary ) {
		if ( ! $this->is_enabled() ) {
			return array();
		}

		$health    = Server_Pulse_Health::evaluate( $summary );
		$triggered = array();

		foreach ( $health['alerts'] as $alert ) {
			$metric = isset( $alert['metric'] ) ? $alert['metric'] : '';
			$rule   = isset( self::RULE_MAP[ $metric ] ) ? self::RULE_MAP[ $metric ] : '';

			if ( '' === $rule || ! $this->rule_enabled( $rule ) ) {
				continue;
			}

			$triggered[] = $rule;

			$this->process_triggered(
				$rule,
				$metric,
				isset( $alert['severity'] ) ? $alert['severity'] : 'warning',
				isset( $alert['value'] ) ? (float) $alert['value'] : 0,
				$this->threshold_for( $metric ),
				isset( $alert['message'] ) ? $alert['message'] : '',
				array( 'source' => 'threshold' )
			);
		}

		// Resolve any active threshold alert that is no longer firing.
		foreach ( $this->active_rules() as $rule ) {
			if ( ! in_array( $rule, $triggered, true ) && in_array( $rule, self::THRESHOLD_RULES, true ) ) {
				$this->resolve( $rule );
			}
		}

		return $triggered;
	}

	/**
	 * Evaluate trend deviations and capacity projections.
	 *
	 * @param array $summary Merged metrics.
	 * @param array $trends  Output of Server_Pulse_Trends::compute().
	 * @return array Triggered rule keys.
	 */
	public function run_trends( array $summary, array $trends ) {
		if ( ! $this->is_enabled() ) {
			return array();
		}

		$alert_settings = Server_Pulse_Settings::get( 'alerts', array() );
		$triggered      = array();

		$deviation = isset( $alert_settings['trend_deviation'] ) ? (int) $alert_settings['trend_deviation'] : 15;

		$baselines = isset( $trends['baseline'] ) && is_array( $trends['baseline'] ) ? $trends['baseline'] : array();

		foreach ( $baselines as $metric => $baseline ) {
			$rule = isset( self::TREND_RULE_MAP[ $metric ] ) ? self::TREND_RULE_MAP[ $metric ] : '';

			if ( '' === $rule || ! $this->rule_enabled( $rule ) ) {
				continue;
			}

			$value = isset( $baseline['deviation'] ) ? (float) $baseline['deviation'] : 0.0;

			if ( $value < $deviation ) {
				$this->resolve( $rule );
				continue;
			}

			$severity = ( $value >= ( $deviation * 2 ) ) ? 'critical' : 'warning';
			$avg      = isset( $baseline['average'] ) ? (float) $baseline['average'] : 0.0;
			$current  = isset( $baseline['current'] ) ? (float) $baseline['current'] : 0.0;

			$message = sprintf(
				/* translators: 1: metric label, 2: current, 3: average, 4: deviation. */
				__( '%1$s is at %2$s%% against a %3$s%% 7-day average (+%4$s points).', 'server-pulse' ),
				$this->rule_label( $rule ),
				number_format_i18n( $current, 1 ),
				number_format_i18n( $avg, 1 ),
				number_format_i18n( $value, 1 )
			);

			$this->process_triggered(
				$rule,
				$metric,
				$severity,
				$current,
				max( 0.0, $avg + $deviation ),
				$message,
				array(
					'source'      => 'trend',
					'window'      => isset( $trends['window'] ) ? (int) $trends['window'] : 7,
					'deviation'   => $value,
					'average'     => $avg,
				)
			);

			$triggered[] = $rule;
		}

		$projections = isset( $trends['projections'] ) && is_array( $trends['projections'] ) ? $trends['projections'] : array();

		$disk = isset( $projections['disk'] ) ? $projections['disk'] : null;
		if ( is_array( $disk ) && isset( $disk['days_left'] ) && null !== $disk['days_left'] ) {
			$days = (int) $disk['days_left'];
			if ( $this->rule_enabled( 'disk_projection' ) ) {
				$threshold = isset( $alert_settings['disk_days_threshold'] ) ? (int) $alert_settings['disk_days_threshold'] : 7;

				if ( $days <= $threshold ) {
					$severity = ( $days <= 2 ) ? 'critical' : 'warning';

					$message = sprintf(
						/* translators: 1: days left, 2: used, 3: capacity. */
						__( 'Disk is projected to reach capacity in about %1$d days at the current growth rate (using %2$s of %3$s).', 'server-pulse' ),
						$days,
						Server_Pulse_Util::format_bytes( isset( $disk['used'] ) ? $disk['used'] : 0 ),
						Server_Pulse_Util::format_bytes( isset( $disk['capacity'] ) ? $disk['capacity'] : 0 )
					);

					$this->process_triggered(
						'disk_projection',
						'disk_used',
						$severity,
						(float) $days,
						(float) $threshold,
						$message,
						array(
							'source'   => 'projection',
							'days'     => $days,
						)
					);

					$triggered[] = 'disk_projection';
				} else {
					$this->resolve( 'disk_projection' );
				}
			}
		}

		$bandwidth = isset( $projections['bandwidth'] ) ? $projections['bandwidth'] : null;
		if ( is_array( $bandwidth ) && isset( $bandwidth['percent'] ) && null !== $bandwidth['percent'] ) {
			if ( $this->rule_enabled( 'bandwidth_projection' ) ) {
				$threshold = isset( $alert_settings['bandwidth_pct_threshold'] ) ? (int) $alert_settings['bandwidth_pct_threshold'] : 85;
				$percent   = (float) $bandwidth['percent'];

				if ( $percent >= $threshold ) {
					$severity = ( $percent >= 100 ) ? 'critical' : 'warning';

					$message = sprintf(
						/* translators: %1$d: projected percent of the monthly limit. */
						__( 'Bandwidth is on pace to reach %1$d%% of the monthly limit at the current rate.', 'server-pulse' ),
						(int) round( $percent )
					);

					$this->process_triggered(
						'bandwidth_projection',
						'bandwidth_total',
						$severity,
						$percent,
						(float) $threshold,
						$message,
						array(
							'source' => 'projection',
						)
					);

					$triggered[] = 'bandwidth_projection';
				} else {
					$this->resolve( 'bandwidth_projection' );
				}
			}
		}

		return $triggered;
	}

	/**
	 * Perform an uptime check and raise or resolve the site_down alert.
	 *
	 * @return bool Whether the site responded.
	 */
	public function run_uptime() {
		if ( ! $this->is_enabled() || ! $this->rule_enabled( 'site_down' ) ) {
			return true;
		}

		$alerts = Server_Pulse_Settings::get( 'alerts', array() );
		if ( empty( $alerts['uptime_enabled'] ) ) {
			return true;
		}

		$url      = home_url( '/' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'sslverify'   => true,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'User-Agent'    => 'ServerPulse/' . SERVER_PULSE_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$code = 0;
			$note = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$note = '';
		}

		$down = ( 0 === $code ) || ( $code >= 500 );

		if ( ! $down ) {
			$this->resolve( 'site_down' );

			return true;
		}

		$message = ( 0 === $code )
			? sprintf(
				/* translators: %s: error detail. */
				__( 'The site did not respond to a health check: %s', 'server-pulse' ),
				$note
			)
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The site responded with HTTP %d on the health check.', 'server-pulse' ),
				$code
			);

		$this->process_triggered(
			'site_down',
			'site_down',
			'critical',
			(float) $code,
			200,
			$message,
			array(
				'source' => 'uptime',
				'url'    => $url,
			)
		);

		return false;
	}

	/**
	 * Register a triggered rule, notify when appropriate.
	 *
	 * @param string $rule      Rule key.
	 * @param string $metric    Metric key.
	 * @param string $severity  Severity.
	 * @param float  $value     Current value.
	 * @param float  $threshold Threshold.
	 * @param string $message   Human message.
	 * @param array  $context   Extra context.
	 * @return void
	 */
	private function process_triggered( $rule, $metric, $severity, $value, $threshold, $message, array $context = array() ) {
		$existing = $this->active_for_rule( $rule );
		$now      = current_time( 'mysql', true );

		if ( ! $existing ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$this->table(),
				array(
					'created_at'  => $now,
					'updated_at'  => $now,
					'rule_key'    => sanitize_key( $rule ),
					'metric'      => sanitize_key( $metric ),
					'severity'    => sanitize_key( $severity ),
					'value'       => $value,
					'threshold'   => $threshold,
					'status'      => 'active',
					'message'     => $message,
					'context'     => wp_json_encode( $context ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s' )
			);

			$event = $this->build_event( $rule, $metric, $severity, $value, $threshold, $message, 'alert' );
			$this->notify( (int) $wpdb->insert_id, $event );

			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array(
				'updated_at' => $now,
				'severity'   => sanitize_key( $severity ),
				'value'      => $value,
				'threshold'  => $threshold,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%f', '%f', '%s', '%s' ),
			array( '%d' )
		);

		// Send a reminder only after the cooldown has elapsed.
		$alert_settings = Server_Pulse_Settings::get( 'alerts', array() );
		$cooldown       = isset( $alert_settings['cooldown_hours'] ) ? (int) $alert_settings['cooldown_hours'] : 6;
		$last           = ! empty( $existing['last_notified'] ) ? strtotime( $existing['last_notified'] . ' UTC' ) : 0;

		if ( ( time() - $last ) < ( $cooldown * HOUR_IN_SECONDS ) ) {
			return;
		}

		$event = $this->build_event( $rule, $metric, $severity, $value, $threshold, $message, 'alert' );
		$this->notify( (int) $existing['id'], $event );
	}

	/**
	 * Resolve an active rule, optionally notifying recovery.
	 *
	 * @param string $rule Rule key.
	 * @return void
	 */
	public function resolve( $rule ) {
		$existing = $this->active_for_rule( $rule );

		if ( ! $existing ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array(
				'status'      => 'resolved',
				'resolved_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$alerts = Server_Pulse_Settings::get( 'alerts', array() );

		if ( empty( $alerts['notify_recovery'] ) ) {
			return;
		}

		$event = $this->build_event(
			$rule,
			isset( $existing['metric'] ) ? $existing['metric'] : $rule,
			'info',
			isset( $existing['value'] ) ? (float) $existing['value'] : 0,
			isset( $existing['threshold'] ) ? (float) $existing['threshold'] : 0,
			sprintf(
				/* translators: %s: rule label. */
				__( 'Recovered: %s is back to normal.', 'server-pulse' ),
				$this->rule_label( $rule )
			),
			'recovery'
		);

		$this->notify( (int) $existing['id'], $event );
	}

	/**
	 * Dispatch an event through the notifier and record the outcome.
	 *
	 * @param int   $alert_id Alert row id.
	 * @param array $event    Event payload.
	 * @return array Channel results.
	 */
	private function notify( $alert_id, array $event ) {
		if ( ! $this->within_daily_cap( $event['severity'] ) ) {
			return array();
		}

		$results = $this->notifier->dispatch( $event );
		$sent    = ! empty( array_filter( $results ) );

		if ( ! $sent ) {
			return $results;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET notify_count = notify_count + 1, last_notified = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$alert_id
			)
		);

		return $results;
	}

	/**
	 * Whether the daily notification cap still allows a send.
	 *
	 * Critical alerts always bypass the cap.
	 *
	 * @param string $severity Severity.
	 * @return bool
	 */
	private function within_daily_cap( $severity ) {
		if ( 'critical' === $severity ) {
			return true;
		}

		$alerts = Server_Pulse_Settings::get( 'alerts', array() );
		$cap    = isset( $alerts['daily_cap'] ) ? (int) $alerts['daily_cap'] : 5;

		if ( $cap <= 0 ) {
			return true;
		}

		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(notify_count), 0) FROM {$this->table()} WHERE last_notified >= %s",
				$since
			)
		);

		return $count < $cap;
	}

	/**
	 * Find the current active alert row for a rule.
	 *
	 * @param string $rule Rule key.
	 * @return array|null
	 */
	public function active_for_rule( $rule ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE rule_key = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
				sanitize_key( $rule )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Rule keys with an active alert.
	 *
	 * @return string[]
	 */
	public function active_rules() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT rule_key FROM {$this->table()} WHERE status = 'active'" );

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Recent alert history.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function recent( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'format_row' ), (array) $rows );
	}

	/**
	 * Number of currently active alerts.
	 *
	 * @return int
	 */
	public function active_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'active'" );
	}

	/**
	 * Send a test through every enabled channel.
	 *
	 * @return array
	 */
	public function send_test() {
		$event = $this->build_event(
			'test',
			'test',
			'info',
			0,
			0,
			__( 'This is a test notification from Server Pulse. If you can read this, your alert channel works.', 'server-pulse' ),
			'test'
		);

		return $this->notifier->dispatch( $event );
	}

	/**
	 * Build the event payload passed to the notifier.
	 *
	 * @param string $rule      Rule key.
	 * @param string $metric    Metric key.
	 * @param string $severity  Severity.
	 * @param float  $value     Value.
	 * @param float  $threshold Threshold.
	 * @param string $message   Message.
	 * @param string $type      alert|recovery|test.
	 * @return array
	 */
	private function build_event( $rule, $metric, $severity, $value, $threshold, $message, $type ) {
		$label = $this->rule_label( $rule );

		if ( 'recovery' === $type ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: rule label. */
				__( '[%1$s] Recovered: %2$s', 'server-pulse' ),
				$this->site_name(),
				$label
			);
		} elseif ( 'test' === $type ) {
			$subject = sprintf(
				/* translators: %s: site name. */
				__( '[%1$s] Server Pulse test alert', 'server-pulse' ),
				$this->site_name()
			);
		} else {
			$subject = sprintf(
				/* translators: 1: severity, 2: site name, 3: rule label. */
				__( '[%1$s] %2$s: %3$s', 'server-pulse' ),
				strtoupper( $severity ),
				$this->site_name(),
				$label
			);
		}

		return array(
			'type'        => $type,
			'rule'        => $rule,
			'metric'      => $metric,
			'severity'    => $severity,
			'label'       => $label,
			'value'       => $value,
			'threshold'   => $threshold,
			'message'     => $message,
			'subject'     => $subject,
			'site_name'   => $this->site_name(),
			'site_url'    => home_url( '/' ),
			'admin_url'   => admin_url( 'admin.php?page=server-pulse' ),
			'occurred_at' => time(),
		);
	}

	/**
	 * Format a database row for the UI.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function format_row( array $row ) {
		return array(
			'id'          => (int) $row['id'],
			'rule'        => $row['rule_key'],
			'label'       => $this->rule_label( $row['rule_key'] ),
			'severity'    => $row['severity'],
			'value'       => (float) $row['value'],
			'threshold'   => (float) $row['threshold'],
			'message'     => $row['message'],
			'status'      => $row['status'],
			'created_at'  => $row['created_at'],
			'resolved_at' => $row['resolved_at'],
			'notified'    => (int) $row['notify_count'],
		);
	}

	/**
	 * Human readable label for a rule.
	 *
	 * @param string $rule Rule key.
	 * @return string
	 */
	public function rule_label( $rule ) {
		$labels = array(
			'cpu'          => __( 'CPU usage', 'server-pulse' ),
			'memory'       => __( 'Memory usage', 'server-pulse' ),
			'disk'         => __( 'Disk usage', 'server-pulse' ),
			'php_memory'   => __( 'PHP memory usage', 'server-pulse' ),
			'autoload'     => __( 'Autoloaded options', 'server-pulse' ),
			'cron'         => __( 'Overdue cron events', 'server-pulse' ),
			'object_cache' => __( 'Object cache', 'server-pulse' ),
			'site_down'    => __( 'Site availability', 'server-pulse' ),
			'cpu_trend'          => __( 'CPU trend', 'server-pulse' ),
			'memory_trend'       => __( 'Memory trend', 'server-pulse' ),
			'disk_trend'         => __( 'Disk trend', 'server-pulse' ),
			'php_memory_trend'   => __( 'PHP memory trend', 'server-pulse' ),
			'disk_projection'    => __( 'Disk capacity projection', 'server-pulse' ),
			'bandwidth_projection' => __( 'Bandwidth projection', 'server-pulse' ),
			'test'         => __( 'Test notification', 'server-pulse' ),
		);

		return isset( $labels[ $rule ] ) ? $labels[ $rule ] : ucwords( str_replace( '_', ' ', (string) $rule ) );
	}

	/**
	 * Threshold configured for a health metric.
	 *
	 * @param string $metric Metric key.
	 * @return float
	 */
	private function threshold_for( $metric ) {
		$thresholds = Server_Pulse_Settings::get( 'thresholds', array() );
		$map        = array(
			'cpu_percent'        => 'cpu',
			'memory_percent'     => 'memory',
			'disk_percent'       => 'disk',
			'php_memory_percent' => 'php_memory',
			'db_autoload'        => 'autoload',
		);

		if ( isset( $map[ $metric ], $thresholds[ $map[ $metric ] ] ) ) {
			return (float) $thresholds[ $map[ $metric ] ];
		}

		return 0.0;
	}

	/**
	 * Site name used in subjects.
	 *
	 * @return string
	 */
	private function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
