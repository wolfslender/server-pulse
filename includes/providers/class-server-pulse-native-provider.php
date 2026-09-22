<?php
/**
 * Native (Linux/VPS/dedicated) provider.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads real system resources from /proc, sys_getloadavg and disk_* functions.
 */
class Server_Pulse_Native_Provider extends Server_Pulse_Abstract_Provider {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'native';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Server (Native)', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description() {
		return __( 'Reads CPU load, RAM, disk, uptime and processes directly from the operating system. Best for VPS, dedicated servers and unmanaged hosting.', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return Server_Pulse_Util::has_function( 'sys_getloadavg' ) || is_readable( '/proc/meminfo' ) || is_readable( '/proc/loadavg' );
	}

	/**
	 * @inheritDoc
	 */
	public function collect() {
		$metrics = array();

		$metrics = array_merge( $metrics, $this->cpu_metrics() );
		$metrics = array_merge( $metrics, $this->memory_metrics() );
		$metrics = array_merge( $metrics, $this->disk_metrics() );
		$metrics = array_merge( $metrics, $this->uptime_metrics() );
		$metrics = array_merge( $metrics, $this->network_metrics() );

		$processes = $this->process_metrics();
		if ( null !== $processes ) {
			$metrics['processes']     = $processes;
			$metrics['process_count'] = count( $processes );
		}

		return $this->snapshot( $metrics );
	}

	/**
	 * CPU load and core count.
	 *
	 * @return array
	 */
	private function cpu_metrics() {
		$metrics = array(
			'cpu_percent' => null,
			'cpu_cores'   => null,
			'cpu_load'    => null,
		);

		$cores = $this->core_count();
		if ( $cores ) {
			$metrics['cpu_cores'] = $cores;
		}

		$load = null;
		if ( Server_Pulse_Util::has_function( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
		}

		if ( ! is_array( $load ) ) {
			$raw = Server_Pulse_Util::read_file( '/proc/loadavg' );
			if ( $raw ) {
				$parts = preg_split( '/\s+/', trim( $raw ) );
				$load  = array( (float) $parts[0], (float) $parts[1], (float) $parts[2] );
			}
		}

		if ( is_array( $load ) && isset( $load[0] ) ) {
			$metrics['cpu_load'] = array(
				'one'     => round( (float) $load[0], 2 ),
				'five'    => isset( $load[1] ) ? round( (float) $load[1], 2 ) : null,
				'fifteen' => isset( $load[2] ) ? round( (float) $load[2], 2 ) : null,
			);

			if ( $cores ) {
				$metrics['cpu_percent'] = Server_Pulse_Util::clamp_percent( ( (float) $load[0] / $cores ) * 100 );
			}
		}

		return $metrics;
	}

	/**
	 * Determine the number of CPU cores.
	 *
	 * @return int|null
	 */
	private function core_count() {
		$cpuinfo = Server_Pulse_Util::read_file( '/proc/cpuinfo' );
		if ( $cpuinfo ) {
			$count = preg_match_all( '/^processor\s*:/mi', $cpuinfo );
			if ( $count ) {
				return $count;
			}
		}

		if ( Server_Pulse_Util::has_function( 'shell_exec' ) && (int) Server_Pulse_Settings::get( 'allow_shell' ) ) {
			$output = Server_Pulse_Util::shell( 'nproc 2>/dev/null' );
			if ( $output && is_numeric( trim( $output ) ) ) {
				return (int) trim( $output );
			}
		}

		return null;
	}

	/**
	 * RAM metrics from /proc/meminfo.
	 *
	 * @return array
	 */
	private function memory_metrics() {
		$metrics = array(
			'memory_total'   => null,
			'memory_used'    => null,
			'memory_free'    => null,
			'memory_percent' => null,
		);

		$raw = Server_Pulse_Util::read_file( '/proc/meminfo' );
		if ( ! $raw ) {
			return $metrics;
		}

		$values = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			if ( preg_match( '/^(\w+):\s+(\d+)/', $line, $matches ) ) {
				$values[ $matches[1] ] = (int) $matches[2] * 1024;
			}
		}

		if ( ! isset( $values['MemTotal'] ) ) {
			return $metrics;
		}

		$total     = $values['MemTotal'];
		$available = $values['MemAvailable'] ?? ( ( $values['MemFree'] ?? 0 ) + ( $values['Buffers'] ?? 0 ) + ( $values['Cached'] ?? 0 ) );
		$used      = max( 0, $total - $available );

		$metrics['memory_total']   = $total;
		$metrics['memory_free']    = $available;
		$metrics['memory_used']    = $used;
		$metrics['memory_percent'] = $total > 0 ? Server_Pulse_Util::clamp_percent( $used / $total * 100 ) : 0;

		return $metrics;
	}

	/**
	 * Disk metrics for the WordPress root.
	 *
	 * @return array
	 */
	private function disk_metrics() {
		$metrics = array(
			'disk_total'   => null,
			'disk_used'    => null,
			'disk_free'    => null,
			'disk_percent' => null,
		);

		$path  = defined( 'ABSPATH' ) ? ABSPATH : __DIR__;
		$free  = null;
		$total = null;

		if ( Server_Pulse_Util::has_function( 'disk_free_space' ) ) {
			$free = @disk_free_space( $path );
		}
		if ( Server_Pulse_Util::has_function( 'disk_total_space' ) ) {
			$total = @disk_total_space( $path );
		}

		if ( false === $free || false === $total || null === $free || null === $total ) {
			$free  = null;
			$total = null;

			if ( (int) Server_Pulse_Settings::get( 'allow_shell' ) ) {
				$output = Server_Pulse_Util::shell( 'df -kP ' . escapeshellarg( $path ) . ' 2>/dev/null' );
				if ( $output && preg_match( '/\n\S+\s+(\d+)\s+(\d+)\s+(\d+)/', $output, $matches ) ) {
					$total = (int) $matches[1] * 1024;
					$free  = (int) $matches[3] * 1024;
				}
			}
		}

		if ( null !== $total && null !== $free ) {
			$used = max( 0, $total - $free );

			$metrics['disk_total']   = $total;
			$metrics['disk_free']    = $free;
			$metrics['disk_used']    = $used;
			$metrics['disk_percent'] = $total > 0 ? Server_Pulse_Util::clamp_percent( $used / $total * 100 ) : 0;
		}

		return $metrics;
	}

	/**
	 * System uptime.
	 *
	 * @return array
	 */
	private function uptime_metrics() {
		$metrics = array( 'uptime' => null );

		$raw = Server_Pulse_Util::read_file( '/proc/uptime' );
		if ( $raw ) {
			$parts = explode( ' ', trim( $raw ) );
			if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
				$metrics['uptime'] = (int) $parts[0];
			}
		}

		return $metrics;
	}

	/**
	 * Aggregate network bytes from /proc/net/dev.
	 *
	 * @return array
	 */
	private function network_metrics() {
		$metrics = array(
			'network_in'  => null,
			'network_out' => null,
		);

		$raw = Server_Pulse_Util::read_file( '/proc/net/dev' );
		if ( ! $raw ) {
			return $metrics;
		}

		$in  = 0;
		$out = 0;

		foreach ( explode( "\n", $raw ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}

			$parts = preg_split( '/\s+/', trim( substr( $line, strpos( $line, ':' ) + 1 ) ) );
			if ( count( $parts ) < 10 ) {
				continue;
			}

			$in  += (int) $parts[0];
			$out += (int) $parts[8];
		}

		$metrics['network_in']  = $in;
		$metrics['network_out'] = $out;

		return $metrics;
	}

	/**
	 * Top processes (requires shell permission).
	 *
	 * @return array|null
	 */
	private function process_metrics() {
		if ( ! (int) Server_Pulse_Settings::get( 'allow_shell' ) ) {
			return null;
		}

		$output = Server_Pulse_Util::shell( "ps aux --sort=-%cpu 2>/dev/null | head -n 11" );
		if ( ! $output ) {
			return null;
		}

		$processes = array();
		$lines     = array_filter( explode( "\n", trim( $output ) ) );

		foreach ( $lines as $index => $line ) {
			if ( 0 === $index ) {
				continue;
			}

			$columns = preg_split( '/\s+/', trim( $line ), 11 );
			if ( count( $columns ) < 11 ) {
				continue;
			}

			$processes[] = array(
				'user'    => sanitize_text_field( $columns[0] ),
				'pid'     => absint( $columns[1] ),
				'cpu'     => round( (float) $columns[2], 1 ),
				'memory'  => round( (float) $columns[3], 1 ),
				'command' => sanitize_text_field( $columns[10] ),
			);
		}

		return $processes;
	}
}
