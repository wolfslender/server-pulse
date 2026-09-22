<?php
/**
 * WP Engine provider (official Hosting Platform API).
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls storage, traffic, visits and plan limits from the WP Engine API.
 *
 * WP Engine is a managed platform: CPU and RAM of the shared cluster are not
 * exposed to the account. This provider surfaces the real account-level data
 * that WP Engine does expose (disk, visits, bandwidth and limits).
 */
class Server_Pulse_WpEngine_Provider extends Server_Pulse_Abstract_Provider {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.wpengineapi.com/v1';

	/**
	 * Cache key.
	 */
	const CACHE_KEY = 'server_pulse_wpengine_usage';

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 1800;

	/**
	 * Bytes in a gigabyte.
	 */
	const GB = 1073741824;

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'wpengine';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'WP Engine', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description() {
		return __( 'Account storage, visits, bandwidth and plan limits from the official WP Engine Hosting Platform API. Requires API credentials.', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return (bool) Server_Pulse_Settings::get( 'enable_wpengine' ) && $this->has_credentials();
	}

	/**
	 * Whether credentials are configured.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== Server_Pulse_Settings::get( 'wpengine_api_user', '' ) && '' !== Server_Pulse_Settings::wpengine_api_pass();
	}

	/**
	 * @inheritDoc
	 */
	public function collect() {
		$usage = $this->request_usage();

		if ( is_wp_error( $usage ) ) {
			return $this->unavailable( $usage->get_error_message() );
		}

		$summary = isset( $usage['summary'] ) ? $usage['summary'] : array();
		$limits  = isset( $usage['limits'] ) ? $usage['limits'] : array();

		$files    = $this->latest_value( $summary, 'storage_file_bytes' );
		$database = $this->latest_value( $summary, 'storage_database_bytes' );
		$used     = ( $files + $database ) > 0 ? ( $files + $database ) : null;
		$total    = $this->storage_limit_bytes( $limits );

		$metrics = array(
			'disk_files'       => $files ?: null,
			'disk_database'    => $database ?: null,
			'disk_used'        => $used,
			'disk_total'       => $total,
			'disk_percent'     => ( $used && $total ) ? Server_Pulse_Util::clamp_percent( $used / $total * 100 ) : null,
			'visit_count'      => $this->latest_value( $summary, 'visit_count' ) ?: null,
			'billable_visits'  => $this->latest_value( $summary, 'billable_visits' ) ?: null,
			'request_count'    => $this->latest_value( $summary, 'request_origin_count' ) ?: null,
			'bandwidth_cdn'    => $this->latest_value( $summary, 'network_cdn_bytes' ) ?: null,
			'bandwidth_origin' => $this->latest_value( $summary, 'network_origin_bytes' ) ?: null,
			'bandwidth_total'  => $this->latest_value( $summary, 'network_total_bytes' ) ?: null,
			'visitor_limit'    => isset( $limits['visitors'] ) ? absint( $limits['visitors'] ) : null,
			'bandwidth_limit'  => isset( $limits['bandwidth'] ) ? absint( $limits['bandwidth'] ) * self::GB : null,
		);

		$notes = array(
			__( 'WP Engine reports usage daily, not in real time. Values refresh up to every 30 minutes.', 'server-pulse' ),
		);

		if ( ! $used ) {
			$notes[] = __( 'The API returned no storage metrics yet. WP Engine refreshes storage data periodically.', 'server-pulse' );
		}

		if ( ! $total ) {
			$notes[] = __( 'No plan storage limit returned by the API, so disk percentage cannot be calculated.', 'server-pulse' );
		}

		return $this->snapshot( $metrics, $notes );
	}

	/**
	 * Fetch and cache usage summary + limits.
	 *
	 * @return array|WP_Error
	 */
	private function request_usage() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$account_id = $this->resolve_account_id();
		if ( is_wp_error( $account_id ) ) {
			return $account_id;
		}

		$summary = $this->request( sprintf( '/accounts/%s/usage/summary', rawurlencode( $account_id ) ) );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		// Limits live at /accounts/{id}/limits (not under /usage).
		$limits = $this->request( sprintf( '/accounts/%s/limits', rawurlencode( $account_id ) ) );
		if ( is_wp_error( $limits ) ) {
			$limits = array();
		}

		$payload = array(
			'account_id' => $account_id,
			'summary'    => is_array( $summary ) ? $summary : array(),
			'limits'     => is_array( $limits ) ? $limits : array(),
		);

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );

		return $payload;
	}

	/**
	 * Resolve the account id from settings or the API.
	 *
	 * @return string|WP_Error
	 */
	private function resolve_account_id() {
		$account_id = Server_Pulse_Settings::get( 'wpengine_account_id', '' );

		if ( ! empty( $account_id ) ) {
			return $account_id;
		}

		$accounts = $this->request( '/accounts' );
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		if ( isset( $accounts['results'][0]['id'] ) ) {
			return (string) $accounts['results'][0]['id'];
		}

		return new WP_Error( 'server_pulse_wpe_account', __( 'Could not determine the WP Engine account ID.', 'server-pulse' ) );
	}

	/**
	 * Perform an authenticated GET request.
	 *
	 * @param string $path API path.
	 * @return array|WP_Error
	 */
	private function request( $path ) {
		$user = Server_Pulse_Settings::get( 'wpengine_api_user', '' );
		$pass = Server_Pulse_Settings::wpengine_api_pass();

		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'server_pulse_wpe_credentials', __( 'WP Engine API credentials are missing.', 'server-pulse' ) );
		}

		$result = $this->request_raw( $path, $user, $pass );

		if ( null !== $result['error'] ) {
			return new WP_Error( 'server_pulse_wpe_http', $result['error'] );
		}

		return $result['body'];
	}

	/**
	 * Low-level request returning the HTTP status and decoded body.
	 *
	 * @param string      $path API path.
	 * @param string|null $user API user (defaults to settings).
	 * @param string|null $pass API password (defaults to settings).
	 * @return array { code:int, body:array, error:string|null }
	 */
	private function request_raw( $path, $user = null, $pass = null ) {
		$user = null === $user ? Server_Pulse_Settings::get( 'wpengine_api_user', '' ) : $user;
		$pass = null === $pass ? Server_Pulse_Settings::wpengine_api_pass() : $pass;

		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'body'  => array(),
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['message'] ) ? $body['message'] : sprintf(
				/* translators: %d: HTTP status code. */
				__( 'WP Engine API returned HTTP %d.', 'server-pulse' ),
				$code
			);

			return array(
				'code'  => $code,
				'body'  => $body,
				'error' => $message,
			);
		}

		return array(
			'code'  => $code,
			'body'  => $body,
			'error' => null,
		);
	}

	/**
	 * Extract a `latest.value` from a metric rollup.
	 *
	 * @param array  $summary Summary payload.
	 * @param string $key     Metric key.
	 * @return int
	 */
	private function latest_value( $summary, $key ) {
		if ( isset( $summary[ $key ]['latest']['value'] ) ) {
			return (int) $summary[ $key ]['latest']['value'];
		}

		return 0;
	}

	/**
	 * Convert the plan storage limit (GB) into bytes.
	 *
	 * @param array $limits Limits payload.
	 * @return int|null
	 */
	private function storage_limit_bytes( $limits ) {
		if ( ! is_array( $limits ) ) {
			return null;
		}

		if ( isset( $limits['storage'] ) && is_numeric( $limits['storage'] ) ) {
			return (int) $limits['storage'] * self::GB;
		}

		// Defensive fallback for alternative response shapes.
		$fallback = $this->search_numeric_key( $limits, 'storage' );

		return ( null === $fallback ) ? null : (int) $fallback * self::GB;
	}

	/**
	 * Recursively search for the first numeric value under a key fragment.
	 *
	 * @param array  $array          Array to search.
	 * @param string $key_fragment   Fragment to look for.
	 * @return int|float|null
	 */
	private function search_numeric_key( $array, $key_fragment ) {
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$found = $this->search_numeric_key( $value, $key_fragment );
				if ( null !== $found ) {
					return $found;
				}
				continue;
			}

			if ( is_string( $key ) && false !== strpos( $key, $key_fragment ) && is_numeric( $value ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Run a full diagnostic of the WP Engine integration.
	 *
	 * @return array
	 */
	public function diagnose() {
		delete_transient( self::CACHE_KEY );

		$steps    = array();
		$user     = Server_Pulse_Settings::get( 'wpengine_api_user', '' );
		$pass     = Server_Pulse_Settings::wpengine_api_pass();
		$enabled  = (bool) Server_Pulse_Settings::get( 'enable_wpengine' );

		$steps[] = array(
			'label'  => __( 'Provider enabled', 'server-pulse' ),
			'status' => $enabled ? 'ok' : 'error',
			'detail' => $enabled ? __( 'yes', 'server-pulse' ) : __( 'Turn on "Enable WP Engine provider" and save.', 'server-pulse' ),
		);

		$steps[] = array(
			'label'  => __( 'API User ID', 'server-pulse' ),
			'status' => '' !== $user ? 'ok' : 'error',
			'detail' => '' !== $user ? $user : __( 'missing', 'server-pulse' ),
		);

		$steps[] = array(
			'label'  => __( 'API Password', 'server-pulse' ),
			'status' => '' !== $pass ? 'ok' : 'error',
			'detail' => '' !== $pass ? sprintf( 'saved (%d chars)', strlen( $pass ) ) : __( 'missing or could not be decrypted', 'server-pulse' ),
		);

		if ( '' === $user || '' === $pass ) {
			return $this->diagnose_result( $steps );
		}

		$accounts = $this->request_raw( '/accounts', $user, $pass );

		if ( null !== $accounts['error'] ) {
			$steps[] = array(
				'label'  => 'GET /accounts',
				'status' => 'error',
				'detail' => $accounts['error'] . ( $accounts['code'] ? ' (HTTP ' . $accounts['code'] . ')' : '' ),
			);

			return $this->diagnose_result( $steps );
		}

		$count    = isset( $accounts['body']['count'] ) ? (int) $accounts['body']['count'] : 0;
		$results  = isset( $accounts['body']['results'] ) ? $accounts['body']['results'] : array();
		$first_id = isset( $results[0]['id'] ) ? (string) $results[0]['id'] : '';

		$steps[] = array(
			'label'  => 'GET /accounts',
			'status' => $first_id ? 'ok' : 'error',
			'detail' => $first_id
				? sprintf( '%d account(s). First: %s', $count, $first_id )
				: __( 'Credentials are valid but no accounts are returned. In my.wpengine.com → Users → API Access, turn API access ON for the account and regenerate credentials (account Owner required).', 'server-pulse' ),
		);

		if ( ! $first_id ) {
			return $this->diagnose_result( $steps );
		}

		$account_id = Server_Pulse_Settings::get( 'wpengine_account_id', '' );
		$account_id = $account_id ? $account_id : $first_id;

		$steps[] = array(
			'label'  => __( 'Account ID in use', 'server-pulse' ),
			'status' => 'ok',
			'detail' => $account_id,
		);

		$summary = $this->request_raw( '/accounts/' . rawurlencode( $account_id ) . '/usage/summary', $user, $pass );
		if ( null !== $summary['error'] ) {
			$steps[] = array(
				'label'  => 'GET /usage/summary',
				'status' => 'error',
				'detail' => $summary['error'] . ( $summary['code'] ? ' (HTTP ' . $summary['code'] . ')' : '' ),
			);
		} else {
			$metric_keys = array_keys( $summary['body'] );
			$has_storage = isset( $summary['body']['storage_file_bytes']['latest']['value'] );
			$steps[]     = array(
				'label'  => 'GET /usage/summary',
				'status' => 'ok',
				'detail' => sprintf(
					'%d metric(s): %s',
					count( $metric_keys ),
					implode( ', ', array_slice( $metric_keys, 0, 12 ) )
				),
			);
			$steps[] = array(
				'label'  => __( 'Storage metric present', 'server-pulse' ),
				'status' => $has_storage ? 'ok' : 'warning',
				'detail' => $has_storage
					? Server_Pulse_Util::format_bytes( (int) $summary['body']['storage_file_bytes']['latest']['value'] ) . ' files'
					: __( 'storage_file_bytes.latest.value not found in the response.', 'server-pulse' ),
			);
		}

		$limits = $this->request_raw( '/accounts/' . rawurlencode( $account_id ) . '/limits', $user, $pass );
		if ( null !== $limits['error'] ) {
			$steps[] = array(
				'label'  => 'GET /limits',
				'status' => 'warning',
				'detail' => $limits['error'] . ( $limits['code'] ? ' (HTTP ' . $limits['code'] . ')' : '' ),
			);
		} else {
			$steps[] = array(
				'label'  => 'GET /limits',
				'status' => 'ok',
				'detail' => wp_json_encode( $limits['body'] ),
			);
		}

		return $this->diagnose_result( $steps );
	}

	/**
	 * Build the diagnostic result payload.
	 *
	 * @param array $steps Steps.
	 * @return array
	 */
	private function diagnose_result( $steps ) {
		$has_error = false;

		foreach ( $steps as $step ) {
			if ( 'error' === $step['status'] ) {
				$has_error = true;
				break;
			}
		}

		return array(
			'provider' => 'wpengine',
			'ok'       => ! $has_error,
			'steps'    => $steps,
		);
	}

	/**
	 * Backwards compatible test entry point.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$result = $this->diagnose();

		if ( empty( $result['ok'] ) ) {
			$messages = array();

			foreach ( $result['steps'] as $step ) {
				if ( 'error' === $step['status'] ) {
					$messages[] = $step['label'] . ': ' . $step['detail'];
				}
			}

			return new WP_Error( 'server_pulse_wpe_diagnose', implode( ' | ', $messages ) );
		}

		return $result;
	}
}
