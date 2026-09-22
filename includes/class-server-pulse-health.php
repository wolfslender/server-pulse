<?php
/**
 * Health scoring and alerting.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a metrics summary into a health score and a list of alerts.
 */
class Server_Pulse_Health {

	/**
	 * Evaluate a summary.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	public static function evaluate( array $summary ) {
		$thresholds = Server_Pulse_Settings::get( 'thresholds', array() );
		$alerts     = array();
		$score      = 100.0;

		$checks = array(
			'disk_percent'       => array(
				'threshold' => isset( $thresholds['disk'] ) ? (float) $thresholds['disk'] : 85,
				'weight'    => 2.5,
				'label'     => __( 'Disk usage', 'server-pulse' ),
			),
			'memory_percent'     => array(
				'threshold' => isset( $thresholds['memory'] ) ? (float) $thresholds['memory'] : 80,
				'weight'    => 2.0,
				'label'     => __( 'Memory usage', 'server-pulse' ),
			),
			'cpu_percent'        => array(
				'threshold' => isset( $thresholds['cpu'] ) ? (float) $thresholds['cpu'] : 80,
				'weight'    => 2.0,
				'label'     => __( 'CPU load', 'server-pulse' ),
			),
			'php_memory_percent' => array(
				'threshold' => isset( $thresholds['php_memory'] ) ? (float) $thresholds['php_memory'] : 80,
				'weight'    => 1.0,
				'label'     => __( 'PHP memory usage', 'server-pulse' ),
			),
		);

		foreach ( $checks as $key => $check ) {
			if ( ! isset( $summary[ $key ] ) || null === $summary[ $key ] ) {
				continue;
			}

			$value = (float) $summary[ $key ];
			$over  = $value - $check['threshold'];

			if ( $over <= 0 ) {
				continue;
			}

			$penalty  = min( $check['weight'] * ( $over / 10 ), $check['weight'] * 2 );
			$score   -= $penalty;
			$severity = ( $value >= 95 ) ? 'critical' : 'warning';

			$alerts[] = array(
				'severity' => $severity,
				'metric'   => $key,
				'value'    => $value,
				'message'  => sprintf(
					/* translators: 1: metric label, 2: percentage, 3: threshold. */
					__( '%1$s is at %2$s%% (threshold %3$s%%).', 'server-pulse' ),
					$check['label'],
					number_format_i18n( $value, 1 ),
					number_format_i18n( $check['threshold'], 0 )
				),
			);
		}

		$autoload = isset( $summary['db_autoload'] ) ? (float) $summary['db_autoload'] / MB_IN_BYTES : null;
		$limit    = isset( $thresholds['autoload'] ) ? (float) $thresholds['autoload'] : 2;
		if ( null !== $autoload && $autoload > $limit ) {
			$score   -= min( 1.5 * ( $autoload / $limit ), 3 );
			$alerts[] = array(
				'severity' => $autoload > ( $limit * 2 ) ? 'critical' : 'warning',
				'metric'   => 'db_autoload',
				'value'    => $autoload,
				'message'  => sprintf(
					/* translators: 1: current MB, 2: threshold MB. */
					__( 'Autoloaded options are %1$s MB (recommended under %2$s MB).', 'server-pulse' ),
					number_format_i18n( $autoload, 2 ),
					number_format_i18n( $limit, 0 )
				),
			);
		}

		if ( isset( $summary['cron_overdue'] ) && (int) $summary['cron_overdue'] > 0 ) {
			$overdue  = (int) $summary['cron_overdue'];
			$score   -= min( $overdue * 0.5, 3 );
			$alerts[] = array(
				'severity' => $overdue > 5 ? 'critical' : 'warning',
				'metric'   => 'cron_overdue',
				'value'    => $overdue,
				'message'  => sprintf(
					/* translators: %d: number of overdue cron events. */
					_n( '%d cron event is overdue.', '%d cron events are overdue.', $overdue, 'server-pulse' ),
					$overdue
				),
			);
		}

		if ( isset( $summary['object_cache'] ) && ! $summary['object_cache'] ) {
			$alerts[] = array(
				'severity' => 'info',
				'metric'   => 'object_cache',
				'value'    => 0,
				'message'  => __( 'No persistent object cache detected. Consider Redis or Memcached for busy sites.', 'server-pulse' ),
			);
		}

		$score = max( 0, min( 100, round( $score, 1 ) ) );

		return array(
			'score'  => $score,
			'grade'  => self::grade( $score ),
			'alerts' => $alerts,
		);
	}

	/**
	 * Map a numeric score to a grade label.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	public static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 75 ) {
			return 'good';
		}
		if ( $score >= 55 ) {
			return 'warning';
		}

		return 'critical';
	}
}
