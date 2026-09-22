<?php
/**
 * Snapshot collector.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs providers, merges their metrics and exposes a single snapshot.
 */
class Server_Pulse_Collector {

	/**
	 * Provider manager.
	 *
	 * @var Server_Pulse_Provider_Manager
	 */
	private $providers;

	/**
	 * Repository.
	 *
	 * @var Server_Pulse_Repository
	 */
	private $repository;

	/**
	 * Short-lived snapshot cache key.
	 */
	const CACHE_KEY = 'server_pulse_snapshot';

	/**
	 * Constructor.
	 *
	 * @param Server_Pulse_Provider_Manager $providers  Manager.
	 * @param Server_Pulse_Repository        $repository Repository.
	 */
	public function __construct( Server_Pulse_Provider_Manager $providers, Server_Pulse_Repository $repository ) {
		$this->providers  = $providers;
		$this->repository = $repository;
	}

	/**
	 * Retrieve a snapshot, optionally bypassing the cache.
	 *
	 * @param bool $force Force a fresh collection.
	 * @return array
	 */
	public function get_snapshot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$snapshots = $this->collect_all();
		$summary   = $this->build_summary( $snapshots );
		$health    = Server_Pulse_Health::evaluate( $summary );
		$sources   = $this->map_sources( $snapshots, $summary );

		$snapshot = array(
			'generated_at' => time(),
			'providers'    => $snapshots,
			'statuses'     => $this->providers->statuses(),
			'summary'      => $summary,
			'sources'      => $sources,
			'health'       => $health,
		);

		set_transient( self::CACHE_KEY, $snapshot, 20 );

		return $snapshot;
	}

	/**
	 * Run every available provider.
	 *
	 * @return array
	 */
	public function collect_all() {
		$snapshots = array();

		foreach ( $this->providers->available() as $provider ) {
			$started = microtime( true );

			try {
				$result = $provider->collect();
			} catch ( Throwable $exception ) {
				$result = array(
					'source'    => $provider->get_id(),
					'label'     => $provider->get_label(),
					'available' => false,
					'metrics'   => array(),
					'notes'     => array( $exception->getMessage() ),
				);
			}

			$result['duration_ms'] = round( ( microtime( true ) - $started ) * 1000, 2 );

			$snapshots[ $provider->get_id() ] = $result;
		}

		return $snapshots;
	}

	/**
	 * Merge per-provider metrics into a single prioritized map.
	 *
	 * @param array $snapshots Provider snapshots.
	 * @return array
	 */
	private function build_summary( array $snapshots ) {
		$keys    = array();
		$summary = array();

		foreach ( $snapshots as $snapshot ) {
			if ( empty( $snapshot['metrics'] ) || ! is_array( $snapshot['metrics'] ) ) {
				continue;
			}
			$keys = array_merge( $keys, array_keys( $snapshot['metrics'] ) );
		}

		$keys = array_unique( $keys );

		foreach ( $keys as $key ) {
			foreach ( $this->preferred_sources( $key ) as $source ) {
				if ( isset( $snapshots[ $source ]['metrics'][ $key ] ) && null !== $snapshots[ $source ]['metrics'][ $key ] ) {
					$summary[ $key ] = $snapshots[ $source ]['metrics'][ $key ];
					break;
				}
			}
		}

		return $summary;
	}

	/**
	 * Determine which provider supplied each summary metric.
	 *
	 * @param array $snapshots Provider snapshots.
	 * @param array $summary   Merged metrics.
	 * @return array
	 */
	private function map_sources( array $snapshots, array $summary ) {
		$sources = array();

		foreach ( array_keys( $summary ) as $key ) {
			foreach ( $this->preferred_sources( $key ) as $source ) {
				if ( isset( $snapshots[ $source ]['metrics'][ $key ] ) && null !== $snapshots[ $source ]['metrics'][ $key ] ) {
					$sources[ $key ] = $source;
					break;
				}
			}
		}

		return $sources;
	}

	/**
	 * Source priority for a given metric key.
	 *
	 * @param string $key Metric key.
	 * @return string[]
	 */
	private function preferred_sources( $key ) {
		$disk    = array( 'disk_percent', 'disk_used', 'disk_total', 'disk_free', 'disk_files', 'disk_database' );
		$traffic = array( 'visit_count', 'billable_visits', 'request_count', 'bandwidth_cdn', 'bandwidth_origin', 'bandwidth_total', 'bandwidth_used', 'bandwidth_limit' );
		$system  = array( 'cpu_percent', 'cpu_load', 'cpu_cores', 'memory_total', 'memory_used', 'memory_free', 'memory_percent', 'uptime', 'network_in', 'network_out', 'processes', 'process_count' );

		if ( in_array( $key, $disk, true ) ) {
			return array( 'wpengine', 'cpanel', 'native', 'wordpress' );
		}

		if ( in_array( $key, $traffic, true ) ) {
			return array( 'wpengine', 'native', 'cpanel' );
		}

		if ( in_array( $key, $system, true ) ) {
			return array( 'native', 'cpanel', 'wpengine' );
		}

		return array( 'wordpress', 'native', 'cpanel', 'wpengine' );
	}

	/**
	 * Persist the current merged snapshot to the history table.
	 *
	 * @return int Rows written.
	 */
	public function sample_now() {
		$snapshot = $this->get_snapshot( true );

		if ( empty( $snapshot['summary'] ) ) {
			return 0;
		}

		return $this->repository->save_snapshot( 'merged', $snapshot['generated_at'], $snapshot['summary'] );
	}
}
