<?php
/**
 * Sample persistence.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves historical metric samples.
 */
class Server_Pulse_Repository {

	/**
	 * Metrics persisted as a time series.
	 *
	 * @var string[]
	 */
	const TIMESERIES_KEYS = array(
		'cpu_percent',
		'memory_percent',
		'disk_percent',
		'php_memory_percent',
		'visit_count',
		'bandwidth_total',
		'db_size',
		'db_autoload',
		'cron_overdue',
		'storage_scan_total',
	);

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;

		return $wpdb->prefix . 'sp_samples';
	}

	/**
	 * Persist a snapshot's time series metrics.
	 *
	 * @param string $source   Provider id.
	 * @param int    $timestamp Capture time.
	 * @param array  $metrics  Metric map.
	 * @return int Number of rows written.
	 */
	public function save_snapshot( $source, $timestamp, array $metrics ) {
		global $wpdb;

		$captured_at = gmdate( 'Y-m-d H:i:s', $timestamp );
		$rows        = 0;

		foreach ( self::TIMESERIES_KEYS as $key ) {
			if ( ! isset( $metrics[ $key ] ) || null === $metrics[ $key ] || ! is_numeric( $metrics[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$this->table(),
				array(
					'captured_at'  => $captured_at,
					'source'       => sanitize_key( $source ),
					'metric_key'   => $key,
					'metric_value' => (float) $metrics[ $key ],
				),
				array( '%s', '%s', '%s', '%f' )
			);

			$rows++;
		}

		return $rows;
	}

	/**
	 * Retrieve a time series for a metric.
	 *
	 * @param string $metric_key Metric key.
	 * @param int    $days       Look-back window in days.
	 * @param int    $limit      Maximum points.
	 * @return array
	 */
	public function get_series( $metric_key, $days = 7, $limit = 300 ) {
		global $wpdb;

		$days  = max( 1, min( 365, absint( $days ) ) );
		$limit = max( 1, min( 2000, absint( $limit ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT captured_at, metric_value FROM {$this->table()} WHERE metric_key = %s AND captured_at >= %s ORDER BY captured_at DESC LIMIT %d",
				$metric_key,
				$since,
				$limit
			),
			ARRAY_A
		);

		// The query returns newest first for efficient limit; reverse for charting.
		$results = array_reverse( (array) $results );

		$series = array();

		foreach ( $results as $row ) {
			$series[] = array(
				't' => $row['captured_at'],
				'v' => (float) $row['metric_value'],
			);
		}

		return $series;
	}

	/**
	 * Retrieve multiple time series at once.
	 *
	 * @param int $days Look-back window.
	 * @return array
	 */
	public function get_dashboard_series( $days = 7 ) {
		$keys   = array( 'cpu_percent', 'memory_percent', 'disk_percent', 'visit_count', 'bandwidth_total' );
		$series = array();

		foreach ( $keys as $key ) {
			$series[ $key ] = $this->get_series( $key, $days );
		}

		return $series;
	}

	/**
	 * Timestamp of the most recent sample.
	 *
	 * @return int|null
	 */
	public function last_sample_time() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( "SELECT MAX(captured_at) FROM {$this->table()}" );

		return $value ? strtotime( $value . ' UTC' ) : null;
	}

	/**
	 * Total number of stored samples.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
	 * Delete samples older than the retention window.
	 *
	 * @param int $days Retention days.
	 * @return int Rows deleted.
	 */
	public function purge_old( $days ) {
		global $wpdb;

		$days  = max( 1, absint( $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE captured_at < %s", $since )
		);
	}
}
