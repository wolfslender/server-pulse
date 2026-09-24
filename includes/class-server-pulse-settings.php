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
			'sentinel_enabled'   => 1,
			'sentinel_auto_rollback' => 0,
			'sentinel_loader'    => 0,
			'pro_dev_mode'       => 0,
			'cpanel_host'        => '',
			'cpanel_user'        => '',
			'cpanel_token'       => '',
			'cpanel_port'        => 2083,
			'cpanel_ssl'         => 1,
			'cpanel_ssl_verify'  => 1,
			'allow_private_network' => 0,
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
			'alerts'             => array(
				'enabled'                => 1,
				'email_enabled'          => 1,
				'email_recipients'       => '',
				'cooldown_hours'         => 6,
				'daily_cap'              => 5,
				'notify_recovery'        => 1,
				'uptime_enabled'         => 1,
				'trend_deviation'        => 15,
				'disk_days_threshold'    => 7,
				'bandwidth_pct_threshold' => 85,
				'rules'                  => array(
					'cpu'                 => 1,
					'memory'              => 1,
					'disk'                => 1,
					'php_memory'          => 1,
					'autoload'            => 1,
					'cron'                => 1,
					'object_cache'        => 0,
					'site_down'           => 1,
					'cpu_trend'           => 1,
					'memory_trend'        => 1,
					'disk_trend'          => 1,
					'php_memory_trend'    => 1,
					'disk_projection'     => 1,
					'bandwidth_projection' => 1,
				),
				'webhook_url'            => '',
				'slack_webhook'          => '',
				'discord_webhook'        => '',
				'telegram_token'         => '',
				'telegram_chat'          => '',
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

			if ( ! is_array( self::$cache['alerts'] ) ) {
				self::$cache['alerts'] = self::defaults()['alerts'];
			} else {
				self::$cache['alerts'] = wp_parse_args( self::$cache['alerts'], self::defaults()['alerts'] );

				if ( ! is_array( self::$cache['alerts']['rules'] ) ) {
					self::$cache['alerts']['rules'] = self::defaults()['alerts']['rules'];
				} else {
					self::$cache['alerts']['rules'] = wp_parse_args( self::$cache['alerts']['rules'], self::defaults()['alerts']['rules'] );
				}
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

		foreach ( array( 'allow_shell', 'enable_native', 'enable_wordpress', 'enable_cpanel', 'enable_wpengine', 'enable_storage_scan', 'cpanel_ssl', 'cpanel_ssl_verify', 'allow_private_network', 'sentinel_enabled', 'sentinel_auto_rollback', 'sentinel_loader', 'pro_dev_mode' ) as $flag ) {
			$output[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		$output['cpanel_host']  = isset( $input['cpanel_host'] ) ? sanitize_text_field( $input['cpanel_host'] ) : $current['cpanel_host'];
		$output['cpanel_user']  = isset( $input['cpanel_user'] ) ? sanitize_text_field( $input['cpanel_user'] ) : $current['cpanel_user'];
		$output['cpanel_port']  = isset( $input['cpanel_port'] ) ? max( 1, min( 65535, absint( $input['cpanel_port'] ) ) ) : $current['cpanel_port'];

		if ( ! empty( $input['cpanel_token'] ) && $input['cpanel_token'] !== $current['cpanel_token'] ) {
			$output['cpanel_token'] = Server_Pulse_Crypto::encrypt( sanitize_text_field( $input['cpanel_token'] ) );
		}

		$output['wpengine_api_user']   = isset( $input['wpengine_api_user'] ) ? sanitize_text_field( $input['wpengine_api_user'] ) : $current['wpengine_api_user'];
		$output['wpengine_account_id'] = isset( $input['wpengine_account_id'] ) ? sanitize_text_field( $input['wpengine_account_id'] ) : $current['wpengine_account_id'];

		if ( ! empty( $input['wpengine_api_pass'] ) ) {
			if ( 0 !== strpos( (string) $input['wpengine_api_pass'], Server_Pulse_Crypto::PREFIX ) ) {
				$output['wpengine_api_pass'] = Server_Pulse_Crypto::encrypt( sanitize_text_field( $input['wpengine_api_pass'] ) );
			} else {
				$output['wpengine_api_pass'] = $input['wpengine_api_pass'];
			}
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

		$output['alerts'] = self::sanitize_alerts( isset( $input['alerts'] ) ? $input['alerts'] : array(), $current['alerts'], isset( $input['alerts'] ) );

		// Credentials or the account may have changed: drop cached provider data.
		delete_transient( 'server_pulse_wpengine_usage' );
		delete_transient( Server_Pulse_Collector::CACHE_KEY );

		self::$cache = null;

		return $output;
	}

	/**
	 * Sanitize the alerts configuration.
	 *
	 * @param array $input   Raw alerts input.
	 * @param array $current Current alerts config.
	 * @param bool  $present Whether the alerts key was submitted at all.
	 * @return array
	 */
	private static function sanitize_alerts( $input, $current, $present = true ) {
		$output = is_array( $current ) ? $current : self::defaults()['alerts'];

		// A partial update that does not include alerts must not wipe them.
		if ( ! $present ) {
			return $output;
		}

		$input  = is_array( $input ) ? $input : array();

		foreach ( array( 'enabled', 'email_enabled', 'notify_recovery', 'uptime_enabled' ) as $flag ) {
			$output[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		$output['email_recipients'] = isset( $input['email_recipients'] )
			? sanitize_text_field( $input['email_recipients'] )
			: $output['email_recipients'];

		$output['cooldown_hours'] = isset( $input['cooldown_hours'] ) ? max( 1, min( 168, absint( $input['cooldown_hours'] ) ) ) : $output['cooldown_hours'];
		$output['daily_cap']      = isset( $input['daily_cap'] ) ? max( 0, min( 100, absint( $input['daily_cap'] ) ) ) : $output['daily_cap'];

		$output['trend_deviation'] = isset( $input['trend_deviation'] ) ? max( 5, min( 60, absint( $input['trend_deviation'] ) ) ) : $output['trend_deviation'];
		$output['disk_days_threshold'] = isset( $input['disk_days_threshold'] ) ? max( 7, min( 90, absint( $input['disk_days_threshold'] ) ) ) : $output['disk_days_threshold'];
		$output['bandwidth_pct_threshold'] = isset( $input['bandwidth_pct_threshold'] ) ? max( 60, min( 100, absint( $input['bandwidth_pct_threshold'] ) ) ) : $output['bandwidth_pct_threshold'];

		$rules = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();
		foreach ( array( 'cpu', 'memory', 'disk', 'php_memory', 'autoload', 'cron', 'object_cache', 'site_down', 'cpu_trend', 'memory_trend', 'disk_trend', 'php_memory_trend', 'disk_projection', 'bandwidth_projection' ) as $rule ) {
			$output['rules'][ $rule ] = ! empty( $rules[ $rule ] ) ? 1 : 0;
		}

		// Secret channel endpoints: encrypt only when a new value is submitted.
		$secrets = array(
			'webhook_url'     => 'esc_url_raw',
			'slack_webhook'   => 'esc_url_raw',
			'discord_webhook' => 'esc_url_raw',
			'telegram_token'  => 'sanitize_text_field',
		);

		foreach ( $secrets as $key => $sanitizer ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			// Already encrypted (e.g. WordPress ran this sanitizer twice).
			if ( 0 === strpos( (string) $input[ $key ], Server_Pulse_Crypto::PREFIX ) ) {
				$output[ $key ] = $input[ $key ];
				continue;
			}

			$value          = call_user_func( $sanitizer, $input[ $key ] );
			$output[ $key ] = Server_Pulse_Crypto::encrypt( $value );
		}

		$output['telegram_chat'] = isset( $input['telegram_chat'] )
			? sanitize_text_field( $input['telegram_chat'] )
			: $output['telegram_chat'];

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

	/**
	 * Decrypt and return an alert channel secret.
	 *
	 * @param string $key Alert secret key.
	 * @return string
	 */
	public static function alert_secret( $key ) {
		$alerts = self::get( 'alerts', array() );

		return Server_Pulse_Crypto::decrypt( isset( $alerts[ $key ] ) ? $alerts[ $key ] : '' );
	}
}
