<?php
/**
 * Shared helpers for Server Pulse.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Utility helpers used across providers and the UI.
 */
class Server_Pulse_Util {

	/**
	 * Convert a human readable size (e.g. 256M) into bytes.
	 *
	 * @param string|int $value Size string.
	 * @return int
	 */
	public static function to_bytes( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return 0;
		}

		// Accept both "1M" and "1MB" style units.
		if ( 'b' === substr( $value, -1 ) && 'b' !== substr( $value, -2, 1 ) ) {
			$value = substr( $value, 0, -1 );
		}

		$unit   = substr( $value, -1 );
		$number = (float) $value;

		switch ( $unit ) {
			case 'p':
				$number *= 1024;
				// no break.
			case 't':
				$number *= 1024;
				// no break.
			case 'g':
				$number *= 1024;
				// no break.
			case 'm':
				$number *= 1024;
				// no break.
			case 'k':
				$number *= 1024;
				// no break.
			default:
				return (int) $number;
		}
	}

	/**
	 * Format bytes into a readable string.
	 *
	 * @param int|float $bytes     Number of bytes.
	 * @param int       $precision Decimal precision.
	 * @return string
	 */
	public static function format_bytes( $bytes, $precision = 2 ) {
		$bytes = (float) $bytes;

		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$power = (int) floor( log( $bytes, 1024 ) );
		$power = min( $power, count( $units ) - 1 );

		$value = $bytes / pow( 1024, $power );

		return round( $value, $precision ) . ' ' . $units[ $power ];
	}

	/**
	 * Format a number of bytes-per-second into a readable rate.
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function format_rate( $bytes ) {
		return self::format_bytes( $bytes ) . '/s';
	}

	/**
	 * Format a percentage for display.
	 *
	 * @param int|float $value Percentage value.
	 * @return string
	 */
	public static function format_percent( $value ) {
		return number_format_i18n( round( (float) $value, 1 ), 1 ) . '%';
	}

	/**
	 * Format a duration in seconds into a human readable string.
	 *
	 * @param int $seconds Duration.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		$days    = (int) floor( $seconds / DAY_IN_SECONDS );
		$hours   = (int) floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

		if ( $days > 0 ) {
			return sprintf( '%dd %dh', $days, $hours );
		}

		if ( $hours > 0 ) {
			return sprintf( '%dh %dm', $hours, $minutes );
		}

		return sprintf( '%dm', $minutes );
	}

	/**
	 * Read a local file safely (used for /proc and /sys on Linux).
	 *
	 * @param string $path Absolute path.
	 * @return string|null
	 */
	public static function read_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local system file, not a remote URL.
		$contents = @file_get_contents( $path );

		return ( false === $contents ) ? null : $contents;
	}

	/**
	 * Determine whether a function exists and has not been disabled.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	public static function has_function( $function ) {
		if ( ! function_exists( $function ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( $function, $disabled, true );
	}

	/**
	 * Detect whether the plugin is running on WP Engine.
	 *
	 * @return bool
	 */
	public static function is_wpengine() {
		if ( defined( 'WPE_APIKEY' ) || defined( 'WPE_BILLING_ID' ) || defined( 'WPE_HELPER_PATH' ) ) {
			return true;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$host = preg_replace( '/:\d+$/', '', $host );

		$patterns = array( 'wpengine.com', 'wpenginepowered.com' );

		foreach ( $patterns as $pattern ) {
			if ( $host === $pattern || substr( $host, - ( strlen( $pattern ) + 1 ) ) === '.' . $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect a cPanel environment.
	 *
	 * @return bool
	 */
	public static function is_cpanel() {
		if ( file_exists( '/usr/local/cpanel/cpanel' ) ) {
			return true;
		}

		if ( ! empty( $_SERVER['CPANEL'] ) || getenv( 'CPANEL' ) ) {
			return true;
		}

		$home = getenv( 'HOME' );

		return ( is_string( $home ) && '' !== $home ) ? is_dir( $home . '/.cpanel' ) : false;
	}

	/**
	 * Safely run a shell command when it is permitted.
	 *
	 * @param string $command Command to execute.
	 * @return string|null
	 */
	public static function shell( $command ) {
		if ( ! self::has_function( 'shell_exec' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Read-only diagnostics, opt-in via settings.
		$output = @shell_exec( $command );

		return ( null === $output ) ? null : $output;
	}

	/**
	 * Normalize a value into a bounded float percentage (0-100).
	 *
	 * @param int|float $value Value.
	 * @return float
	 */
	public static function clamp_percent( $value ) {
		return max( 0.0, min( 100.0, round( (float) $value, 2 ) ) );
	}
}
