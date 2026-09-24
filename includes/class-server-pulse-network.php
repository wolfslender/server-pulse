<?php
/**
 * Outbound URL validation (anti-SSRF).
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an outbound request target is safe.
 *
 * By default only public HTTP(S) hosts are allowed, which blocks the classic
 * SSRF pivot to cloud metadata (169.254.169.254), loopback and RFC1918 ranges.
 * Installations that legitimately talk to a host on a private network can opt
 * in via the "allow private network" setting.
 */
class Server_Pulse_Network {

	/**
	 * Whether private network targets are allowed.
	 *
	 * @return bool
	 */
	public static function allow_private() {
		return (bool) Server_Pulse_Settings::get( 'allow_private_network', 0 );
	}

	/**
	 * Whether an IP is public (not private, loopback, link-local or reserved).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		return false !== filter_var(
			(string) $ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Whether a hostname resolves only to public IPs.
	 *
	 * @param string $host Hostname or IP literal.
	 * @return bool
	 */
	public static function host_is_public( $host ) {
		$host = trim( (string) $host );

		if ( '' === $host ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		$cache_key = 'sp_net_' . md5( strtolower( $host ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$ips    = self::resolve( $host );
		$public = ! empty( $ips );

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				$public = false;
				break;
			}
		}

		set_transient( $cache_key, $public ? 1 : 0, HOUR_IN_SECONDS );

		return $public;
	}

	/**
	 * Resolve a hostname to its IP addresses.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function resolve( $host ) {
		$ips = array();

		if ( function_exists( 'gethostbynamel' ) ) {
			$ipv4 = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $ipv4 ) ) {
				$ips = array_merge( $ips, $ipv4 );
			}
		}

		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return $ips;
	}

	/**
	 * HTTP args that keep an outbound request from escaping validation.
	 *
	 * Redirects are disabled so a validated public host cannot bounce the
	 * request to a private address; WordPress performs its own unsafe-URL
	 * check as a second layer. When the admin explicitly allows private
	 * networks both protections are relaxed.
	 *
	 * @param array $args Extra request args to merge.
	 * @return array
	 */
	public static function request_args( array $args = array() ) {
		if ( self::allow_private() ) {
			return array_merge( array( 'redirection' => 5 ), $args );
		}

		return array_merge(
			array(
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			),
			$args
		);
	}

	/**
	 * Validate an outbound URL.
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	public static function validate_url( $url ) {
		if ( self::allow_private() ) {
			return true;
		}

		$url = trim( (string) $url );

		if ( '' === $url ) {
			return true;
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'sp_net_scheme', __( 'Only http and https URLs are allowed.', 'server-pulse' ) );
		}

		if ( '' === $host ) {
			return new WP_Error( 'sp_net_host', __( 'The URL has no host.', 'server-pulse' ) );
		}

		if ( ! self::host_is_public( $host ) ) {
			return new WP_Error(
				'sp_net_private',
				__( 'The target resolves to a private, loopback or reserved address, which is blocked. Enable "Allow private network targets" in Settings if this is intentional.', 'server-pulse' )
			);
		}

		return true;
	}
}