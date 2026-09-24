<?php
/**
 * PRO: PHP error-log analyzer.
 *
 * Groups the recurring warnings/notices/fatals in a WP Engine error log by
 * signature (severity + normalized message + file:line) so the noisiest
 * offenders bubble to the top with an exact pointer.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Error log analyzer.
 */
class Server_Pulse_Log_Analyzer {

	/**
	 * Option holding the last report (not autoloaded).
	 */
	const OPTION = 'server_pulse_error_report';

	/**
	 * Hard limits.
	 */
	const MAX_BYTES       = 209715200; // 200 MB.
	const MAX_LINES       = 2000000;
	const MAX_SECONDS     = 20;
	const MAX_LINE_LENGTH = 8192;
	const TOP_N           = 30;

	/**
	 * Aggregated groups keyed by signature.
	 *
	 * @var array
	 */
	private $groups = array();

	/**
	 * Totals per severity.
	 *
	 * @var array
	 */
	private $severities = array();

	/**
	 * Run metadata.
	 *
	 * @var array
	 */
	private $meta = array();

	/**
	 * Analyze error-log files.
	 *
	 * @param string[] $paths       Absolute paths.
	 * @param int      $time_budget Seconds.
	 * @return array|WP_Error
	 */
	public static function analyze_files( array $paths, $time_budget = self::MAX_SECONDS ) {
		$paths = array_values( array_filter( array_map( 'strval', $paths ) ) );

		if ( ! $paths ) {
			return new WP_Error( 'server_pulse_error_no_files', __( 'No error logs were found to analyze.', 'server-pulse' ) );
		}

		$analyzer = new self();
		$analyzer->run( $paths, max( 5, min( 120, (int) $time_budget ) ) );
		$report = $analyzer->finalize();

		update_option( self::OPTION, $report, false );

		return $report;
	}

	/**
	 * Analyze the WP Engine private error logs.
	 *
	 * @param int $time_budget Seconds.
	 * @return array|WP_Error
	 */
	public static function analyze_wpe_logs( $time_budget = self::MAX_SECONDS ) {
		$files = array();

		foreach ( Server_Pulse_Traffic_Analyzer::discover_log_files() as $file ) {
			if ( false !== stripos( $file, 'error' ) ) {
				$files[] = $file;
			}
		}

		if ( ! $files ) {
			return new WP_Error( 'server_pulse_error_no_wpe', __( 'No WP Engine error logs were found on this server.', 'server-pulse' ) );
		}

		return self::analyze_files( $files, $time_budget );
	}

	/**
	 * Process the files.
	 *
	 * @param string[] $paths Paths.
	 * @param int      $budget Seconds.
	 * @return void
	 */
	private function run( array $paths, $budget ) {
		$this->groups     = array();
		$this->severities = array();
		$this->meta       = array(
			'started'  => microtime( true ),
			'lines'    => 0,
			'unparsed' => 0,
			'bytes'    => 0,
			'capped'   => false,
			'first_ts' => null,
			'last_ts'  => null,
		);

		foreach ( $paths as $path ) {
			if ( $this->should_stop( $budget ) ) {
				$this->meta['capped'] = true;
				break;
			}
			$this->each_line( $path, $budget );
		}
	}

	/**
	 * Whether a cap was reached.
	 *
	 * @param int $budget Seconds.
	 * @return bool
	 */
	private function should_stop( $budget ) {
		return $this->meta['lines'] >= self::MAX_LINES
			|| $this->meta['bytes'] >= self::MAX_BYTES
			|| ( microtime( true ) - $this->meta['started'] ) > $budget;
	}

	/**
	 * Stream a file, handling gzip.
	 *
	 * @param string $path   Path.
	 * @param int    $budget Seconds.
	 * @return void
	 */
	private function each_line( $path, $budget ) {
		if ( ! is_readable( $path ) ) {
			return;
		}

		$is_gz = ( '.gz' === substr( $path, -3 ) );

		if ( $is_gz && function_exists( 'gzopen' ) ) {
			$handle = @gzopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				return;
			}
			while ( ! gzeof( $handle ) && ! $this->should_stop( $budget ) ) {
				$line = gzgets( $handle, self::MAX_LINE_LENGTH );
				if ( false === $line ) {
					break;
				}
				$this->consume( $line );
			}
			gzclose( $handle );
			return;
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return;
		}
		while ( ! feof( $handle ) && ! $this->should_stop( $budget ) ) {
			$line = fgets( $handle, self::MAX_LINE_LENGTH );
			if ( false === $line ) {
				break;
			}
			$this->consume( $line );
		}
		fclose( $handle );
	}

	/**
	 * Account for a chunk and dispatch it.
	 *
	 * @param string $line Raw line.
	 * @return void
	 */
	private function consume( $line ) {
		$this->meta['bytes'] += strlen( $line );

		if ( $this->meta['lines'] >= self::MAX_LINES || $this->meta['bytes'] > self::MAX_BYTES ) {
			$this->meta['capped'] = true;
			return;
		}

		$this->meta['lines']++;
		$this->process_line( $line );
	}

	/**
	 * Parse and group one line.
	 *
	 * @param string $line Raw line.
	 * @return void
	 */
	private function process_line( $line ) {
		$ts = null;
		if ( preg_match( '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+\-]+)\]/', $line, $t ) ) {
			$parsed = strtotime( $t[1] );
			if ( false !== $parsed ) {
				$ts = $parsed;
			}
		}

		// "message repeated N times: [ ... ]" blocks inherit the last context;
		// treat them as a multiplier for the following/previous line instead.
		if ( preg_match( '/message repeated (\d+) times?: \[ ?(.*?)\] ?$/i', $line, $rep ) ) {
			$count    = max( 1, (int) $rep[1] );
			$inner    = trim( $rep[2] );
			$parsed   = $this->parse_record( $inner );
			if ( null !== $parsed ) {
				$this->add( $parsed, $count, $ts );
			}
			return;
		}

		$record = $this->parse_record( $line );
		if ( null === $record ) {
			$this->meta['unparsed']++;
			return;
		}

		$this->add( $record, 1, $ts );
	}

	/**
	 * Extract severity, message and location from a record.
	 *
	 * @param string $text Record text (without the timestamp prefix).
	 * @return array|null
	 */
	private function parse_record( $text ) {
		$pattern = '/^(?:\[[^\]]+\]\s+)?PHP\s+(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated):\s+(.*?)(?:\s+in\s+(\S+)\s+on line\s+(\d+))?\s*$/';

		if ( ! preg_match( $pattern, trim( $text ), $m ) ) {
			return null;
		}

		return array(
			'severity' => strtolower( $m[1] ),
			'message'  => (string) $m[2],
			'file'     => isset( $m[3] ) ? (string) $m[3] : '',
			'line'     => isset( $m[4] ) ? (int) $m[4] : 0,
		);
	}

	/**
	 * Add a parsed record to the groups.
	 *
	 * @param array    $record Parsed record.
	 * @param int      $count  Multiplier.
	 * @param int|null $ts     Unix timestamp.
	 * @return void
	 */
	private function add( array $record, $count, $ts ) {
		$severity = $record['severity'];
		$key      = $severity . '|' . $record['file'] . ':' . $record['line'] . '|' . self::normalize( $record['message'] );

		if ( ! isset( $this->groups[ $key ] ) ) {
			$this->groups[ $key ] = array(
				'severity' => $severity,
				'message'  => $record['message'],
				'file'     => $record['file'],
				'line'     => $record['line'],
				'count'    => 0,
				'first'    => $ts,
				'last'     => $ts,
			);
		}

		$this->groups[ $key ]['count'] += $count;

		if ( null !== $ts ) {
			if ( null === $this->groups[ $key ]['first'] || $ts < $this->groups[ $key ]['first'] ) {
				$this->groups[ $key ]['first'] = $ts;
			}
			if ( null === $this->groups[ $key ]['last'] || $ts > $this->groups[ $key ]['last'] ) {
				$this->groups[ $key ]['last'] = $ts;
			}

			if ( null === $this->meta['first_ts'] || $ts < $this->meta['first_ts'] ) {
				$this->meta['first_ts'] = $ts;
			}
			if ( null === $this->meta['last_ts'] || $ts > $this->meta['last_ts'] ) {
				$this->meta['last_ts'] = $ts;
			}
		}

		if ( ! isset( $this->severities[ $severity ] ) ) {
			$this->severities[ $severity ] = 0;
		}
		$this->severities[ $severity ] += $count;
	}

	/**
	 * Normalize a message so equal errors with different values collapse.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function normalize( $message ) {
		$message = (string) $message;
		$message = preg_replace( '/\b[0-9a-f]{6,}\b/i', '#', $message );
		$message = preg_replace( '/\d+/', '#', $message );
		$message = preg_replace( '#/[^\s\'"]+\.php#', '<file>', $message );
		$message = preg_replace( '/\s+/', ' ', $message );

		return trim( substr( $message, 0, 300 ) );
	}

	/**
	 * Build the final report.
	 *
	 * @return array
	 */
	private function finalize() {
		$groups = array_values( $this->groups );

		usort(
			$groups,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		$top = array_slice( $groups, 0, self::TOP_N );

		$total = 0;
		foreach ( $this->severities as $count ) {
			$total += (int) $count;
		}

		return array(
			'version'      => 1,
			'generated_at' => time(),
			'capped'       => (bool) $this->meta['capped'],
			'lines'        => (int) $this->meta['lines'],
			'unparsed'     => (int) $this->meta['unparsed'],
			'total'        => $total,
			'by_severity'  => array(
				'fatal error'             => isset( $this->severities['fatal error'] ) ? (int) $this->severities['fatal error'] : 0,
				'parse error'             => isset( $this->severities['parse error'] ) ? (int) $this->severities['parse error'] : 0,
				'recoverable fatal error' => isset( $this->severities['recoverable fatal error'] ) ? (int) $this->severities['recoverable fatal error'] : 0,
				'warning'                 => isset( $this->severities['warning'] ) ? (int) $this->severities['warning'] : 0,
				'notice'                  => isset( $this->severities['notice'] ) ? (int) $this->severities['notice'] : 0,
				'deprecated'              => isset( $this->severities['deprecated'] ) ? (int) $this->severities['deprecated'] : 0,
			),
			'time_from'    => $this->meta['first_ts'],
			'time_to'      => $this->meta['last_ts'],
			'groups'       => array_map(
				static function ( $group ) {
					$group['file'] = substr( (string) $group['file'], -400 );
					$group['message'] = substr( (string) $group['message'], 0, 400 );
					return $group;
				},
				$top
			),
		);
	}

	/**
	 * Stored report.
	 *
	 * @return array
	 */
	public static function report() {
		$report = get_option( self::OPTION, array() );

		return is_array( $report ) ? $report : array();
	}

	/**
	 * Remove the stored report.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
