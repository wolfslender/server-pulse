<?php
/**
 * Trend baselines and resource projections.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored samples into 7 day baselines and forward-looking projections so
 * the dashboard can answer "what changed" and "what is about to run out".
 */
class Server_Pulse_Trends {

	/**
	 * Repository.
	 *
	 * @var Server_Pulse_Repository
	 */
	private $repository;

	/**
	 * Look-back window in days for baselines.
	 */
	const BASELINE_DAYS = 7;

	/**
	 * Minimum samples required before a baseline is meaningful.
	 */
	const MIN_SAMPLES = 3;

	/**
	 * Percentage metrics evaluated against their own baseline.
	 *
	 * @var string[]
	 */
	const TREND_METRICS = array(
		'cpu_percent',
		'memory_percent',
		'disk_percent',
		'php_memory_percent',
	);

	/**
	 * Byte metrics used for capacity projections.
	 *
	 * @var string[]
	 */
	const CAPACITY_METRICS = array(
		'disk_used',
		'db_size',
		'bandwidth_total',
	);

	/**
	 * Constructor.
	 *
	 * @param Server_Pulse_Repository $repository Repository.
	 */
	public function __construct( Server_Pulse_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Compute baselines and projections for a fresh summary.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	public function compute( array $summary ) {
		return array(
			'window'      => self::BASELINE_DAYS,
			'baseline'    => $this->baselines( $summary ),
			'projections' => $this->projections( $summary ),
		);
	}

	/**
	 * Per metric: current value, trailing average and deviation in points.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	public function baselines( array $summary ) {
		$out = array();

		foreach ( self::TREND_METRICS as $metric ) {
			$series = $this->repository->get_series( $metric, self::BASELINE_DAYS, 300 );
			$points = count( $series );

			if ( $points < self::MIN_SAMPLES ) {
				continue;
			}

			$current = ( isset( $summary[ $metric ] ) && is_numeric( $summary[ $metric ] ) )
				? (float) $summary[ $metric ]
				: (float) $series[ $points - 1 ]['v'];

			$total = 0.0;
			foreach ( $series as $point ) {
				$total += (float) $point['v'];
			}

			$average = $total / $points;

			$out[ $metric ] = array(
				'current'   => round( $current, 1 ),
				'average'   => round( $average, 1 ),
				'deviation' => round( $current - $average, 1 ),
				'samples'   => $points,
			);
		}

		return $out;
	}

	/**
	 * Capacity projections for disk, database and bandwidth.
	 *
	 * @param array $summary Merged metrics.
	 * @return array
	 */
	public function projections( array $summary ) {
		return array(
			'disk'      => $this->disk_projection( $summary ),
			'database'  => $this->byte_projection( 'db_size', $summary ),
			'bandwidth' => $this->bandwidth_projection( $summary ),
		);
	}

	/**
	 * Days left until disk capacity is reached at the current growth rate.
	 *
	 * @param array $summary Merged metrics.
	 * @return array|null
	 */
	private function disk_projection( array $summary ) {
		$capacity = isset( $summary['disk_total'] ) ? (float) $summary['disk_total'] : 0.0;
		$used     = isset( $summary['disk_used'] ) ? (float) $summary['disk_used'] : null;

		if ( $capacity <= 0 && null === $used ) {
			return null;
		}

		$series = $this->repository->get_series( 'disk_used', self::BASELINE_DAYS, 300 );

		if ( null === $used && $series ) {
			$used = (float) $series[ count( $series ) - 1 ]['v'];
		}

		$used = max( 0.0, (float) $used );
		$rate = $this->linear_rate_per_day( $series );
		$days = null;

		if ( null !== $rate && $rate > 0 && $capacity > $used ) {
			$days = (int) round( ( $capacity - $used ) / $rate );

			if ( $days > 3650 ) {
				$days = null;
			}
		}

		$percent = ( $capacity > 0 ) ? $used / $capacity * 100 : null;

		return array(
			'capacity'     => $capacity,
			'used'         => $used,
			'percent'      => ( null === $percent ) ? null : round( $percent, 1 ),
			'rate_per_day' => ( null === $rate ) ? 0.0 : round( $rate, 0 ),
			'days_left'    => $days,
		);
	}

	/**
	 * Growth rate information for a single byte metric.
	 *
	 * @param string $metric  Metric key.
	 * @param array  $summary Merged metrics.
	 * @return array|null
	 */
	private function byte_projection( $metric, array $summary ) {
		$series = $this->repository->get_series( $metric, self::BASELINE_DAYS, 300 );

		if ( ! $series ) {
			return null;
		}

		$latest = ( isset( $summary[ $metric ] ) && is_numeric( $summary[ $metric ] ) )
			? (float) $summary[ $metric ]
			: (float) $series[ count( $series ) - 1 ]['v'];

		$rate = $this->linear_rate_per_day( $series );

		return array(
			'current'      => $latest,
			'rate_per_day' => ( null === $rate ) ? 0.0 : round( $rate, 0 ),
		);
	}

	/**
	 * Projected month-end usage against the monthly bandwidth limit.
	 *
	 * @param array $summary Merged metrics.
	 * @return array|null
	 */
	private function bandwidth_projection( array $summary ) {
		$limit = isset( $summary['bandwidth_limit'] ) ? (float) $summary['bandwidth_limit'] : 0.0;

		if ( $limit <= 0 ) {
			return null;
		}

		$series = $this->repository->get_series( 'bandwidth_total', self::BASELINE_DAYS, 300 );

		if ( ! $series ) {
			return null;
		}

		$latest = ( isset( $summary['bandwidth_total'] ) && is_numeric( $summary['bandwidth_total'] ) )
			? (float) $summary['bandwidth_total']
			: (float) $series[ count( $series ) - 1 ]['v'];

		$rate     = $this->linear_rate_per_day( $series );
		$monthly  = ( null === $rate ) ? $latest : $latest + ( $rate * 30 );
		$percent  = ( $monthly > 0 && $limit > 0 ) ? $monthly / $limit * 100 : null;

		return array(
			'current'      => $latest,
			'limit'        => $limit,
			'rate_per_day' => ( null === $rate ) ? 0.0 : round( $rate, 0 ),
			'projected'    => round( $monthly, 0 ),
			'percent'      => ( null === $percent ) ? null : round( $percent, 1 ),
		);
	}

	/**
	 * Linear growth per day using least squares over a series.
	 *
	 * @param array $series Time series as returned by the repository.
	 * @return float|null
	 */
	private function linear_rate_per_day( array $series ) {
		$count = count( $series );

		if ( $count < 2 ) {
			return null;
		}

		$sum_x  = 0.0;
		$sum_y  = 0.0;
		$sum_xx = 0.0;
		$sum_xy = 0.0;

		foreach ( $series as $point ) {
			$time = strtotime( $point['t'] . ' UTC' );
			$x    = ( false === $time ) ? 0.0 : (float) $time;
			$y    = (float) $point['v'];

			$sum_x  += $x;
			$sum_y  += $y;
			$sum_xx += ( $x * $x );
			$sum_xy += ( $x * $y );
		}

		$denominator = ( $count * $sum_xx ) - ( $sum_x * $sum_x );

		if ( (float) $denominator === 0.0 ) {
			return null;
		}

		$slope_per_second = ( ( $count * $sum_xy ) - ( $sum_x * $sum_y ) ) / $denominator;

		return $slope_per_second * 86400;
	}
}