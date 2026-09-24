<?php
/**
 * cPanel / WHM provider.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads account resource usage from a cPanel server through the UAPI.
 */
class Server_Pulse_Cpanel_Provider extends Server_Pulse_Abstract_Provider {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'cpanel';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'cPanel', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description() {
		return __( 'Account disk and bandwidth usage from the cPanel UAPI. Requires a cPanel host, username and API token. CPU/RAM require CloudLinux LVE or shell access.', 'server-pulse' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return (bool) Server_Pulse_Settings::get( 'enable_cpanel' ) && '' !== Server_Pulse_Settings::cpanel_token();
	}

	/**
	 * @inheritDoc
	 */
	public function collect() {
		$usage = $this->request( 'ResourceUsage', 'get_usages' );

		if ( is_wp_error( $usage ) ) {
			return $this->unavailable( $usage->get_error_message() );
		}

		$metrics = array();
		$notes   = array();

		$data = isset( $usage['data'] ) ? $usage['data'] : array();

		if ( is_array( $data ) ) {
			foreach ( $data as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}

				$id    = strtolower( (string) $entry['id'] );
				$limit = isset( $entry['limit'] ) && is_numeric( $entry['limit'] ) ? $this->mb_to_bytes( (float) $entry['limit'] ) : null;
				$used  = isset( $entry['usage'] ) && is_numeric( $entry['usage'] ) ? $this->mb_to_bytes( (float) $entry['usage'] ) : null;

				if ( false !== strpos( $id, 'disk' ) && null !== $used ) {
					$metrics['disk_used']  = $used;
					$metrics['disk_total'] = $limit;
				} elseif ( false !== strpos( $id, 'bandwidth' ) && null !== $used ) {
					$metrics['bandwidth_used']  = $used;
					$metrics['bandwidth_limit'] = $limit;
				} elseif ( false !== strpos( $id, 'mysql' ) && null !== $used ) {
					$metrics['db_size'] = $used;
				} elseif ( false !== strpos( $id, 'email' ) && null !== $used ) {
					$metrics['email_accounts'] = isset( $entry['usage'] ) ? (int) $entry['usage'] : null;
				}
			}
		}

		if ( isset( $metrics['disk_used'], $metrics['disk_total'] ) && $metrics['disk_total'] ) {
			$metrics['disk_percent'] = Server_Pulse_Util::clamp_percent( $metrics['disk_used'] / $metrics['disk_total'] * 100 );
		}

		$lve = $this->lve_metrics();
		if ( $lve ) {
			$metrics = array_merge( $metrics, $lve );
		} else {
			$notes[] = __( 'CPU and RAM are not exposed by the cPanel UAPI. Enable CloudLinux LVE or shell access to see them.', 'server-pulse' );
		}

		if ( empty( $metrics ) ) {
			$notes[] = __( 'No resource data was returned by cPanel.', 'server-pulse' );
		}

		return $this->snapshot( $metrics, $notes );
	}

	/**
	 * Attempt to read CloudLinux LVE metrics for this account.
	 *
	 * @return array
	 */
	private function lve_metrics() {
		$metrics = array();

		$raw = Server_Pulse_Util::read_file( '/proc/lve/list' );
		if ( $raw && preg_match( '/^0\s+(\d+)\s+(\d+)/m', $raw, $matches ) ) {
			$metrics['cpu_percent']  = Server_Pulse_Util::clamp_percent( (int) $matches[1] );
			$metrics['memory_percent'] = Server_Pulse_Util::clamp_percent( (int) $matches[2] );
		}

		return $metrics;
	}

	/**
	 * Convert a cPanel UAPI megabyte value to bytes.
	 *
	 * The UAPI ResourceUsage endpoints report disk, bandwidth and database
	 * sizes in megabytes; the rest of the plugin stores bytes.
	 *
	 * @param float $megabytes Value in MB.
	 * @return int
	 */
	private function mb_to_bytes( $megabytes ) {
		return (int) round( $megabytes * MB_IN_BYTES );
	}

	/**
	 * Perform a cPanel UAPI call.
	 *
	 * @param string $module   UAPI module.
	 * @param string $function Function name.
	 * @return array|WP_Error
	 */
	private function request( $module, $function ) {
		$host = Server_Pulse_Settings::get( 'cpanel_host', '' );
		if ( '' === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$host = preg_replace( '/:\d+$/', '', $host );
		}

		$user = Server_Pulse_Settings::get( 'cpanel_user', '' );
		$token = Server_Pulse_Settings::cpanel_token();

		if ( '' === $host || '' === $user || '' === $token ) {
			return new WP_Error( 'server_pulse_cpanel_config', __( 'cPanel host, user and token are required.', 'server-pulse' ) );
		}

		$ssl    = (int) Server_Pulse_Settings::get( 'cpanel_ssl' );
		$scheme = $ssl ? 'https' : 'http';
		$port   = absint( Server_Pulse_Settings::get( 'cpanel_port', 0 ) );

		if ( $port <= 0 ) {
			$port = $ssl ? 2083 : 2082;
		} elseif ( ! $ssl && 2083 === $port ) {
			// The stored value is the SSL default but SSL is turned off.
			$port = 2082;
		}

		$url = sprintf( '%s://%s:%d/execute/%s/%s', $scheme, $host, $port, rawurlencode( $module ), rawurlencode( $function ) );

		$safe = Server_Pulse_Network::validate_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$response = wp_remote_get(
			$url,
			Server_Pulse_Network::request_args(
				array(
					'timeout'   => 15,
					'sslverify' => (bool) Server_Pulse_Settings::get( 'cpanel_ssl_verify', 1 ),
					'headers'   => array(
						'Authorization' => 'cpanel ' . $user . ':' . $token,
						'Accept'        => 'application/json',
					),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'server_pulse_cpanel_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'cPanel returned HTTP %d.', 'server-pulse' ),
					$code
				)
			);
		}

		if ( isset( $body['status'] ) && 1 !== (int) $body['status'] ) {
			$message = isset( $body['errors'][0] ) ? $body['errors'][0] : __( 'cPanel reported an error.', 'server-pulse' );

			return new WP_Error( 'server_pulse_cpanel_api', $message );
		}

		return $body;
	}

	/**
	 * Test the cPanel credentials.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$usage = $this->request( 'StatsBar', 'get_stats' );

		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		return array( 'ok' => true );
	}
}
