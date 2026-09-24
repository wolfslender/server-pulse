<?php
/**
 * PRO: traffic / access-log analyzer.
 *
 * Parses Apache-style (WP Engine "apachestyle") access logs into bounded
 * aggregates so the admin can understand 5xx spikes, abusive clients and
 * missing assets without shipping raw logs off the server.
 *
 * The parser is streaming and hard-capped: it never loads a whole log into
 * memory and never runs unbounded regexes. Nothing here touches the database
 * except the single JSON summary option.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access log analyzer.
 */
class Server_Pulse_Traffic_Analyzer {

	/**
	 * Option holding the last report (not autoloaded).
	 */
	const OPTION = 'server_pulse_traffic_report';

	/**
	 * Hard limits that keep a single run bounded.
	 */
	const MAX_BYTES       = 314572800; // 300 MB of log text per run.
	const MAX_LINES       = 3000000;
	const MAX_SECONDS     = 25;
	const MAX_LINE_LENGTH = 8192;
	const TOP_N           = 25;
	const MAX_UNIQUE_KEYS = 20000;

	/**
	 * Accumulated metrics for the current run.
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * Whether the run stopped early because a cap was hit.
	 *
	 * @var bool
	 */
	private $capped = false;

	/**
	 * Run the analysis over a set of files.
	 *
	 * @param string[] $paths       Absolute file paths.
	 * @param string   $source      Source label (upload|wpeprivate|mixed).
	 * @param int      $time_budget Seconds budget.
	 * @return array|WP_Error Report or error.
	 */
	public static function analyze_files( array $paths, $source = 'upload', $time_budget = self::MAX_SECONDS ) {
		$paths = array_values( array_filter( array_map( 'strval', $paths ) ) );

		if ( ! $paths ) {
			return new WP_Error( 'server_pulse_traffic_no_files', __( 'No log files were found to analyze.', 'server-pulse' ) );
		}

		$analyzer = new self();
		$analyzer->run( $paths, $time_budget );

		if ( 0 === $analyzer->state['lines'] ) {
			return new WP_Error( 'server_pulse_traffic_empty', __( 'The provided files did not contain recognizable access-log lines.', 'server-pulse' ) );
		}

		$report = $analyzer->finalize( $source );
		self::save( $report );

		return $report;
	}

	/**
	 * Analyze the autodetected WP Engine private logs, if present.
	 *
	 * @param int $time_budget Seconds budget.
	 * @return array|WP_Error
	 */
	public static function analyze_wpe_logs( $time_budget = self::MAX_SECONDS ) {
		$files = self::discover_log_files();

		if ( ! $files ) {
			return new WP_Error( 'server_pulse_traffic_no_wpe', __( 'No WP Engine log files were found on this server. Upload a log export instead.', 'server-pulse' ) );
		}

		return self::analyze_files( $files, 'wpeprivate', $time_budget );
	}

	/**
	 * Reset the accumulator for a run.
	 *
	 * @return void
	 */
	private function reset_state() {
		$this->state = array(
			'started'    => microtime( true ),
			'bytes'      => 0,
			'lines'      => 0,
			'unparsed'   => 0,
			'requests'   => 0,
			'first_ts'   => null,
			'last_ts'    => null,
			'status'     => array(),
			'by_day'     => array(),
			'by_day_5xx' => array(),
			'minutes'    => array(),
			'minutes5'   => array(),
			'ips'        => array(),
			'ip5'        => array(),
			'ip_empty'   => array(),
			'uas'        => array(),
			'ua_empty'   => 0,
			'paths'      => array(),
			'path5'      => array(),
			'path4'      => array(),
			'buckets'    => array(),
		);
	}

	/**
	 * Process a set of files.
	 *
	 * @param string[] $paths       Paths.
	 * @param int      $time_budget Seconds.
	 * @return void
	 */
	private function run( array $paths, $time_budget ) {
		$this->reset_state();
		$this->capped = false;
		$time_budget  = max( 5, min( 120, (int) $time_budget ) );

		foreach ( $paths as $path ) {
			if ( $this->should_stop( $time_budget ) ) {
				$this->capped = true;
				break;
			}

			$this->each_line(
				$path,
				function ( $line ) {
					$this->process_line( $line );
				}
			);
		}
	}

	/**
	 * Whether the run has hit a hard cap.
	 *
	 * @param int $time_budget Seconds.
	 * @return bool
	 */
	private function should_stop( $time_budget ) {
		if ( $this->state['lines'] >= self::MAX_LINES ) {
			return true;
		}

		if ( $this->state['bytes'] >= self::MAX_BYTES ) {
			return true;
		}

		return ( microtime( true ) - $this->state['started'] ) > $time_budget;
	}

	/**
	 * Stream a file line by line, transparently handling gzip.
	 *
	 * @param string   $path Absolute path.
	 * @param callable $cb   Line callback.
	 * @return bool Whether the file was readable.
	 */
	private function each_line( $path, $cb ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$is_gz = ( '.gz' === substr( (string) $path, -3 ) ) || $this->is_gzip( $path );

		if ( $is_gz && function_exists( 'gzopen' ) ) {
			$handle = @gzopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				return false;
			}

			while ( ! gzeof( $handle ) ) {
				$line = gzgets( $handle, self::MAX_LINE_LENGTH );
				if ( false === $line ) {
					break;
				}
				$this->consume_line( $line, $cb );
			}

			gzclose( $handle );

			return true;
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle, self::MAX_LINE_LENGTH );
			if ( false === $line ) {
				break;
			}
			$this->consume_line( $line, $cb );
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Account for a raw chunk and pass it to the callback while under budget.
	 *
	 * @param string   $line Raw line.
	 * @param callable $cb   Callback.
	 * @return void
	 */
	private function consume_line( $line, $cb ) {
		$this->state['bytes'] += strlen( $line );

		if ( $this->state['lines'] >= self::MAX_LINES || $this->state['bytes'] > self::MAX_BYTES ) {
			$this->capped = true;
			return;
		}

		$this->state['lines']++;
		$cb( $line );
	}

	/**
	 * Parse and aggregate a single line.
	 *
	 * @param string $line Raw line.
	 * @return void
	 */
	private function process_line( $line ) {
		$fields = $this->parse_access_line( $line );

		if ( null === $fields ) {
			$this->state['unparsed']++;
			return;
		}

		$status = $fields['status'];
		$path   = self::strip_query( $fields['path'] );
		$ip     = $fields['ip'];
		$ua     = $fields['ua'];

		$this->state['requests']++;

		$class = (int) floor( $status / 100 ) . 'xx';
		$this->bump( $this->state['status'], $class );
		$this->bump( $this->state['status'], (string) $status );

		$ts = $fields['ts'];
		if ( null !== $ts ) {
			if ( null === $this->state['first_ts'] || $ts < $this->state['first_ts'] ) {
				$this->state['first_ts'] = $ts;
			}
			if ( null === $this->state['last_ts'] || $ts > $this->state['last_ts'] ) {
				$this->state['last_ts'] = $ts;
			}
		}

		if ( '' !== $fields['day'] ) {
			$this->bump( $this->state['by_day'], $fields['day'] );
			if ( $status >= 500 ) {
				$this->bump( $this->state['by_day_5xx'], $fields['day'] );
			}
		}

		if ( '' !== $fields['minute'] ) {
			$this->bump( $this->state['minutes'], $fields['minute'] );
			if ( $status >= 500 ) {
				$this->bump( $this->state['minutes5'], $fields['minute'] );
			}
		}

		$this->bump( $this->state['ips'], $ip );
		if ( $status >= 500 ) {
			$this->bump( $this->state['ip5'], $ip );
		}

		if ( '' === trim( $ua ) || '-' === $ua ) {
			$this->state['ua_empty']++;
			if ( '' !== $ip ) {
				$this->bump( $this->state['ip_empty'], $ip );
			}
		} else {
			$this->bump( $this->state['uas'], $ua );
		}

		$this->bump( $this->state['paths'], $path );
		$this->bump( $this->state['buckets'], self::bucket( $path ) );

		if ( $status >= 500 ) {
			$this->bump( $this->state['path5'], $path );
		}
		if ( 404 === $status ) {
			$this->bump( $this->state['path4'], $path );
		}
	}

	/**
	 * Parse a combined/apache-style access-log line.
	 *
	 * @param string $line Raw line.
	 * @return array|null
	 */
	private function parse_access_line( $line ) {
		$pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"([A-Z]+)\s+(\S+)\s+HTTP\/[0-9.]+"\s+(\d{3})\s+(\d+|-)\s+"([^"]*)"\s+"([^"]*)"/';

		if ( ! preg_match( $pattern, $line, $m ) ) {
			return null;
		}

		$day    = '';
		$minute = '';
		$ts     = null;

		if ( preg_match( '/^(\d{2}\/[A-Za-z]{3}\/\d{4}):(\d{2}):(\d{2}):\d{2}/', $m[2], $t ) ) {
			$day    = $t[1];
			$minute = $t[1] . ':' . $t[2] . ':' . $t[3];
			$ts     = self::parse_ts( $m[2] );
		}

		return array(
			'ip'     => substr( $m[1], 0, 64 ),
			'ts'     => $ts,
			'day'    => $day,
			'minute' => $minute,
			'method' => $m[3],
			'path'   => substr( $m[4], 0, 2000 ),
			'status' => (int) $m[5],
			'bytes'  => ( '-' === $m[6] ) ? 0 : (int) $m[6],
			'ref'    => substr( $m[7], 0, 1000 ),
			'ua'     => substr( $m[8], 0, 500 ),
		);
	}

	/**
	 * Convert a WP Engine log timestamp to a unix timestamp.
	 *
	 * @param string $value e.g. "20/Sep/2026:00:18:06 +0000".
	 * @return int|null
	 */
	private static function parse_ts( $value ) {
		$normalized = preg_replace( '/^(\d{2})\/([A-Za-z]{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2})/', '$1 $2 $3 $4:$5:$6', (string) $value );
		$ts         = strtotime( $normalized );

		return ( false === $ts ) ? null : $ts;
	}

	/**
	 * Normalize a request path into a coarse bucket for heavy-endpoint math.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function bucket( $path ) {
		$path = self::strip_query( $path );

		if ( '' === $path ) {
			return '/';
		}

		$segments = explode( '/', trim( $path, '/' ) );
		$first    = isset( $segments[0] ) ? $segments[0] : '';
		$second   = isset( $segments[1] ) ? $segments[1] : '';

		if ( in_array( $first, array( 'wp-json', 'wp-admin', 'wp-content', 'wp-includes' ), true ) && '' !== $second ) {
			return '/' . $first . '/' . $second;
		}

		if ( '' === $first ) {
			return '/';
		}

		return '/' . $first;
	}

	/**
	 * Drop the query string from a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function strip_query( $path ) {
		$path = (string) $path;
		$pos  = strpos( $path, '?' );

		return ( false === $pos ) ? $path : substr( $path, 0, $pos );
	}

	/**
	 * Increment a map entry with a bounded number of unique keys.
	 *
	 * @param array  $map Map (by reference).
	 * @param string $key Key.
	 * @param int    $cap Maximum unique keys.
	 * @return void
	 */
	private function bump( &$map, $key, $cap = self::MAX_UNIQUE_KEYS ) {
		$key = (string) $key;

		if ( isset( $map[ $key ] ) ) {
			$map[ $key ]++;
			return;
		}

		if ( count( $map ) >= $cap ) {
			$map['__other__'] = isset( $map['__other__'] ) ? $map['__other__'] + 1 : 1;
			return;
		}

		$map[ $key ] = 1;
	}

	/**
	 * Sort a map descending and keep the top N as label/value pairs.
	 *
	 * @param array  $map   Map.
	 * @param int    $n     How many.
	 * @param string $label Label key.
	 * @return array
	 */
	private static function top( array $map, $n = self::TOP_N, $label = 'key' ) {
		arsort( $map, SORT_NUMERIC );
		$out = array();

		foreach ( $map as $key => $value ) {
			if ( count( $out ) >= $n ) {
				break;
			}
			if ( '__other__' === (string) $key ) {
				continue;
			}
			$out[] = array(
				$label   => (string) $key,
				'count'  => (int) $value,
			);
		}

		return $out;
	}

	/**
	 * Build the final report from the accumulator.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	private function finalize( $source ) {
		$s = $this->state;

		$minutes = self::merge_minutes( $s['minutes'], $s['minutes5'] );
		$ips     = self::merge_counters( $s['ips'], array( '5xx' => $s['ip5'], 'empty_ua' => $s['ip_empty'] ) );

		$top_ips = array();
		foreach ( $ips as $ip => $row ) {
			if ( '__other__' === (string) $ip ) {
				continue;
			}
			if ( count( $top_ips ) >= self::TOP_N ) {
				break;
			}
			$top_ips[] = array(
				'ip'       => (string) $ip,
				'requests' => (int) $row['count'],
				'five_xx'  => (int) $row['5xx'],
				'empty_ua' => (int) $row['empty_ua'],
			);
		}

		$missing = array();
		foreach ( self::top( $s['path4'], self::TOP_N, 'path' ) as $row ) {
			$missing[] = array(
				'path'       => $row['path'],
				'requests'   => $row['count'],
				'suggestion' => self::suggest_fix( $row['path'] ),
			);
		}

		$heavy = self::heavy_endpoints( $s['paths'], $s['buckets'] );

		return array(
			'version'       => 1,
			'generated_at'  => time(),
			'source'        => sanitize_key( $source ),
			'capped'        => (bool) $this->capped,
			'lines'         => (int) $s['lines'],
			'unparsed'      => (int) $s['unparsed'],
			'bytes'         => (int) $s['bytes'],
			'requests'      => (int) $s['requests'],
			'empty_ua'      => (int) $s['ua_empty'],
			'time_from'     => $s['first_ts'],
			'time_to'       => $s['last_ts'],
			'status'        => self::normalize_status( $s['status'] ),
			'by_day'        => self::sorted_map( $s['by_day'] ),
			'five_xx_by_day' => self::sorted_map( $s['by_day_5xx'] ),
			'top_minutes'   => $minutes,
			'top_ips'       => $top_ips,
			'top_uas'       => self::top( $s['uas'], self::TOP_N, 'ua' ),
			'top_paths'     => self::top( $s['paths'], self::TOP_N, 'path' ),
			'top_5xx_paths' => self::top( $s['path5'], self::TOP_N, 'path' ),
			'missing_assets' => $missing,
			'heavy_endpoints' => $heavy,
		);
	}

	/**
	 * Combine a count map with a 5xx map into a chronologically sorted list.
	 *
	 * @param array $counts   Total counts by minute.
	 * @param array $counts5  5xx counts by minute.
	 * @return array
	 */
	private static function merge_minutes( array $counts, array $counts5 ) {
		$rows = array();

		foreach ( $counts as $minute => $count ) {
			if ( '__other__' === $minute ) {
				continue;
			}
			$rows[] = array(
				'minute'   => (string) $minute,
				'requests' => (int) $count,
				'five_xx'  => isset( $counts5[ $minute ] ) ? (int) $counts5[ $minute ] : 0,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['requests'] <=> $a['requests'];
			}
		);

		return array_slice( $rows, 0, 20 );
	}

	/**
	 * Combine several counter maps keyed by the same label.
	 *
	 * @param array $base    Base count map.
	 * @param array $extras  Map of field name to counter map.
	 * @return array
	 */
	private static function merge_counters( array $base, array $extras ) {
		$rows = array();

		foreach ( $base as $key => $count ) {
			$row = array( 'count' => (int) $count );

			foreach ( $extras as $field => $map ) {
				$row[ $field ] = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
			}

			$rows[ $key ] = $row;
		}

		uasort(
			$rows,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return $rows;
	}

	/**
	 * Status counters normalized into a compact shape.
	 *
	 * @param array $status Raw map.
	 * @return array
	 */
	private static function normalize_status( array $status ) {
		$keys = array( '2xx', '3xx', '4xx', '5xx', '200', '301', '302', '304', '400', '401', '403', '404', '429', '444', '499', '500', '502', '503', '504' );

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $status[ $key ] ) ? (int) $status[ $key ] : 0;
		}

		return $out;
	}

	/**
	 * Ascending label => int map (for day series).
	 *
	 * @param array $map Map.
	 * @return array
	 */
	private static function sorted_map( array $map ) {
		ksort( $map );
		$out = array();
		foreach ( $map as $key => $value ) {
			$out[ (string) $key ] = (int) $value;
		}
		return $out;
	}

	/**
	 * Pick high-volume dynamic endpoints worth caching or short-circuiting.
	 *
	 * Each row carries a machine-readable `type` and a `search` hint so the UI
	 * can tell the user exactly what to look for.
	 *
	 * @param array $paths   Path counts.
	 * @param array $buckets Bucket counts.
	 * @return array
	 */
	private static function heavy_endpoints( array $paths, array $buckets ) {
		$rows = array();

		foreach ( $paths as $path => $count ) {
			if ( '__other__' === $path ) {
				continue;
			}

			$info = self::classify_endpoint( $path );

			if ( '' === $info['type'] ) {
				continue;
			}

			$rows[] = array(
				'path'     => (string) $path,
				'requests' => (int) $count,
				'type'     => $info['type'],
				'search'   => $info['search'],
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['requests'] <=> $a['requests'];
			}
		);

		return array_slice( $rows, 0, self::TOP_N );
	}

	/**
	 * Classify a dynamic path and derive a search hint for the developer.
	 *
	 * @param string $path Path.
	 * @return array { type:string, search:string }
	 */
	private static function classify_endpoint( $path ) {
		$path = (string) $path;

		if ( false !== strpos( $path, 'admin-ajax.php' ) ) {
			return array( 'type' => 'ajax', 'search' => "add_action( 'wp_ajax_" );
		}

		if ( false !== strpos( $path, 'wp-cron.php' ) ) {
			return array( 'type' => 'cron', 'search' => 'DISABLE_WP_CRON' );
		}

		if ( false !== strpos( $path, 'wp-login.php' ) ) {
			return array( 'type' => 'login', 'search' => '' );
		}

		if ( false !== strpos( $path, 'xmlrpc.php' ) ) {
			return array( 'type' => 'xmlrpc', 'search' => 'xmlrpc_enabled' );
		}

		if ( false !== strpos( $path, 'wp-json' ) ) {
			$search = 'register_rest_route';

			if ( preg_match( '~^/wp-json/([^/?#]+)/([^/?#]+)~', $path, $m ) ) {
				$search = "register_rest_route( '" . $m[1] . '/' . $m[2] . "'";
			} elseif ( preg_match( '~^/wp-json/([^/?#]+)~', $path, $m ) ) {
				$search = "register_rest_route( '" . $m[1] . "'";
			}

			return array( 'type' => 'rest', 'search' => $search );
		}

		$base = basename( self::strip_query( $path ) );

		// Page loads are not optimizable endpoints; skip them.
		if ( 'index.php' === $base ) {
			return array( 'type' => '', 'search' => '' );
		}

		if ( false !== strpos( $path, '.php' ) ) {
			return array( 'type' => 'php', 'search' => $base );
		}

		return array( 'type' => '', 'search' => '' );
	}

	/**
	 * Derive a plain-language fix hint for a 404 path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function suggest_fix( $path ) {
		$path = strtolower( (string) $path );

		if ( false !== strpos( $path, 'passkey' ) || false !== strpos( $path, 'well-known' ) ) {
			return 'edge_404';
		}

		if ( false !== strpos( $path, 'favicon' ) || false !== strpos( $path, 'apple-touch-icon' ) ) {
			return 'add_favicon';
		}

		if ( false !== strpos( $path, 'ads.txt' ) || false !== strpos( $path, 'assetlinks.json' ) ) {
			return 'add_static';
		}

		if ( preg_match( '/\.(png|jpe?g|gif|webp|svg|ico|css|js|pdf|woff2?)$/', $path ) ) {
			return 'missing_asset';
		}

		return 'scanner';
	}

	/**
	 * Persist a report.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	public static function save( array $report ) {
		update_option( self::OPTION, $report, false );
	}

	/**
	 * Retrieve the stored report.
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

	/**
	 * Whether the stored report is older than a given age.
	 *
	 * @param int $max_age Seconds.
	 * @return bool
	 */
	public static function is_stale( $max_age = WEEK_IN_SECONDS ) {
		$report = self::report();

		return empty( $report['generated_at'] ) || ( time() - (int) $report['generated_at'] ) > $max_age;
	}

	/**
	 * Candidate WP Engine private roots on this server.
	 *
	 * @return string[]
	 */
	public static function log_roots() {
		$roots = array();

		if ( defined( 'ABSPATH' ) ) {
			$roots[] = trailingslashit( ABSPATH ) . '_wpeprivate';
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = dirname( WP_CONTENT_DIR ) . '/_wpeprivate';
			$roots[] = dirname( dirname( WP_CONTENT_DIR ) ) . '/_wpeprivate';
		}

		/**
		 * Filter the directories searched for WP Engine logs.
		 *
		 * @param string[] $roots Candidate roots.
		 */
		$roots = (array) apply_filters( 'server_pulse_log_roots', $roots );

		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * Discover readable log files inside the candidate roots.
	 *
	 * Only files that resolve inside a known root are returned. The list is
	 * capped so a directory full of files cannot exhaust memory.
	 *
	 * @param int $max_files Maximum files.
	 * @return string[]
	 */
	public static function discover_log_files( $max_files = 40 ) {
		$found = array();

		foreach ( self::log_roots() as $root ) {
			$real_root = realpath( $root );

			if ( ! $real_root || ! is_dir( $real_root ) ) {
				continue;
			}

			$dirs = array( $real_root );
			$sub  = glob( $real_root . '/logs*', GLOB_ONLYDIR );
			if ( is_array( $sub ) ) {
				$dirs = array_merge( $dirs, $sub );
			}

			foreach ( $dirs as $dir ) {
				$files = array_merge(
					(array) glob( $dir . '/*.log' ),
					(array) glob( $dir . '/*.gz' )
				);

				foreach ( $files as $file ) {
					$real = realpath( $file );

					if ( ! $real || ! is_readable( $real ) ) {
						continue;
					}

					// Never escape the discovered root.
					if ( 0 !== strpos( $real, $real_root ) ) {
						continue;
					}

					$found[ $real ] = true;

					if ( count( $found ) >= $max_files ) {
						break 3;
					}
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Whether a path is a gzip file (by magic bytes).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_gzip( $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		$magic = fread( $handle, 2 );
		fclose( $handle );

		return "\x1f\x8b" === $magic;
	}
}
