<?php
/**
 * Local storage scanner (fallback when no hosting API is available).
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Estimates disk usage by walking wp-content and storing the result.
 *
 * This is useful on managed hosts where the platform API is not available: it
 * gives a realistic picture of where the space goes (uploads, plugins, themes).
 */
class Server_Pulse_Storage_Scanner {

	/**
	 * Option holding the last scan result.
	 */
	const OPTION = 'server_pulse_storage_scan';

	/**
	 * Cron hook name.
	 */
	const EVENT = 'server_pulse_storage_scan_event';

	/**
	 * Maximum seconds a single scan may run.
	 */
	const MAX_SECONDS = 25;

	/**
	 * Maximum number of files to count before bailing out.
	 */
	const MAX_FILES = 300000;

	/**
	 * Directories to measure.
	 *
	 * @return array<string,string>
	 */
	public static function targets() {
		$uploads = wp_get_upload_dir();

		return array(
			'uploads'    => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
			'plugins'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins',
			'themes'     => function_exists( 'get_theme_root' ) ? get_theme_root() : WP_CONTENT_DIR . '/themes',
			'muplugins'  => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
		);
	}

	/**
	 * Run a scan and persist the result.
	 *
	 * @return array
	 */
	public static function scan() {
		$start     = microtime( true );
		$breakdown = array();
		$known     = 0;

		foreach ( self::targets() as $key => $path ) {
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$size              = self::directory_size( $path, $start );
			$breakdown[ $key ] = array(
				'path'  => $path,
				'bytes' => $size,
			);
			$known            += $size;
		}

		$content       = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$content_total = is_dir( $content ) ? self::directory_size( $content, $start ) : $known;
		$other         = max( 0, $content_total - $known );

		$breakdown['other'] = array(
			'path'  => $content,
			'bytes' => $other,
		);

		$data = array(
			'time'      => time(),
			'total'     => max( $content_total, $known ),
			'breakdown' => $breakdown,
			'duration'  => round( microtime( true ) - $start, 2 ),
		);

		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * Retrieve the cached scan result.
	 *
	 * @return array
	 */
	public static function cached() {
		$data = get_option( self::OPTION );

		if ( ! is_array( $data ) || ! isset( $data['total'] ) ) {
			return array(
				'time'      => 0,
				'total'     => 0,
				'breakdown' => array(),
				'duration'  => 0,
			);
		}

		return $data;
	}

	/**
	 * Whether the cached scan is older than the given age.
	 *
	 * @param int $max_age Maximum age in seconds.
	 * @return bool
	 */
	public static function is_stale( $max_age = DAY_IN_SECONDS ) {
		$data = self::cached();

		return empty( $data['time'] ) || ( time() - (int) $data['time'] ) > $max_age;
	}

	/**
	 * Recursively compute the size of a directory.
	 *
	 * @param string $path      Directory path.
	 * @param float  $started   Scan start timestamp.
	 * @return int Bytes.
	 */
	private static function directory_size( $path, $started = null ) {
		$started = null === $started ? microtime( true ) : $started;

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return 0;
		}

		$bytes = 0;
		$files = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$bytes += (int) $file->getSize();
				$files++;

				if ( $files >= self::MAX_FILES || ( microtime( true ) - $started ) > self::MAX_SECONDS ) {
					break;
				}
			}
		} catch ( UnexpectedValueException $exception ) {
			return $bytes;
		}

		return $bytes;
	}
}
