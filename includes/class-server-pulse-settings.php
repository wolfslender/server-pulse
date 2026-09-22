<?php
/**
 * Settings storage and sanitization.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin settings.
 */
class Server_Pulse_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'server_pulse_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'sample_interval'    => 'hourly',
			'retention_days'     => 30,
			'dashboard_refresh'  => 15,
			'allow_shell'        => 0,
			'enable_native'      => 1,
			'enable_wordpress'   => 1,
			'enable_cpanel'      => 0,
			'enable_wpengine'    => 0,
			'enable_storage_scan' => 1,
			'cpanel_host'        => '',
			'cpanel_user'        => '',
			'cpanel_token'       => '',
			'cpanel_port'        => 2083,
			'cpanel_ssl'         => 1,
			'wpengine_api_user'  => '',
			'wpengine_api_pass'  => '',
			'wpengine_account_id' => '',
			'thresholds'         => array(
				'cpu'        => 80,
				'memory'     => 80,
				'disk'       => 85,
				'php_memory' => 80,
				'autoload'   => 2,
			),
		);
	}

	/**
	 * Retrieve all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$cache = wp_parse_args( $stored, self::defaults() );

			if ( ! is_array( self::$cache['thresholds'] ) ) {
				self::$cache['thresholds'] = self::defaults()['thresholds'];
			} else {
				self::$cache['thresholds'] = wp_parse_args( self::$cache['thresholds'], self::defaults()['thresholds'] );
			}
		}

		return self::$cache;
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist settings.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function update( $settings ) {
		$sanitized = self::sanitize( $settings );

		update_option( self::OPTION, $sanitized );
		self::$cache = null;

		return $sanitized;
	}

	/**
	 * Sanitize incoming settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$output  = $current;

		$intervals = array( 'hourly', 'twicedaily', 'daily' );
		if ( isset( $input['sample_interval'] ) && in_array( $input['sample_interval'], $intervals, true ) ) {
			$output['sample_interval'] = $input['sample_interval'];
		}

		$output['retention_days']    = isset( $input['retention_days'] ) ? max( 1, min( 365, absint( $input['retention_days'] ) ) ) : $current['retention_days'];
		$output['dashboard_refresh'] = isset( $input['dashboard_refresh'] ) ? max( 5, min( 300, absint( $input['dashboard_refresh'] ) ) ) : $current['dashboard_refresh'];

		foreach ( array( 'allow_shell', 'enable_native', 'enable_wordpress', 'enable_cpanel', 'enable_wpengine', 'enable_storage_scan', 'cpanel_ssl' ) as $flag ) {
			$output[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		$output['cpanel_host']  = isset( $input['cpanel_host'] ) ? sanitize_text_field( $input['cpanel_host'] ) : $current['cpanel_host'];
		$output['cpanel_user']  = isset( $input['cpanel_user'] ) ? sanitize_text_field( $input['cpanel_user'] ) : $current['cpanel_user'];
		$output['cpanel_port']  = isset( $input['cpanel_port'] ) ? absint( $input['cpanel_port'] ) : $current['cpanel_port'];

		if ( ! empty( $input['cpanel_token'] ) && $input['cpanel_token'] !== $current['cpanel_token'] ) {
			$output['cpanel_token'] = Server_Pulse_Crypto::encrypt( sanitize_text_field( $input['cpanel_token'] ) );
		}

		$output['wpengine_api_user']   = isset( $input['wpengine_api_user'] ) ? sanitize_text_field( $input['wpengine_api_user'] ) : $current['wpengine_api_user'];
		$output['wpengine_account_id'] = isset( $input['wpengine_account_id'] ) ? sanitize_text_field( $input['wpengine_account_id'] ) : $current['wpengine_account_id'];

		if ( ! empty( $input['wpengine_api_pass'] ) ) {
			$output['wpengine_api_pass'] = Server_Pulse_Crypto::encrypt( sanitize_text_field( $input['wpengine_api_pass'] ) );
		}

		$thresholds = isset( $input['thresholds'] ) && is_array( $input['thresholds'] ) ? $input['thresholds'] : array();
		foreach ( array( 'cpu', 'memory', 'disk', 'php_memory' ) as $metric ) {
			if ( isset( $thresholds[ $metric ] ) ) {
				$output['thresholds'][ $metric ] = max( 1, min( 100, absint( $thresholds[ $metric ] ) ) );
			}
		}
		if ( isset( $thresholds['autoload'] ) ) {
			$output['thresholds']['autoload'] = max( 0.1, min( 50, (float) $thresholds['autoload'] ) );
		}

		return $output;
	}

	/**
	 * Decrypt and return the cPanel token.
	 *
	 * @return string
	 */
	public static function cpanel_token() {
		return Server_Pulse_Crypto::decrypt( self::get( 'cpanel_token', '' ) );
	}

	/**
	 * Decrypt and return the WP Engine API password.
	 *
	 * @return string
	 */
	public static function wpengine_api_pass() {
		return Server_Pulse_Crypto::decrypt( self::get( 'wpengine_api_pass', '' ) );
	}
}
