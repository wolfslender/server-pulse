<?php
/**
 * Credential encryption helper.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts provider credentials at rest.
 *
 * Uses AES-256-CBC with a key derived from WordPress salts when OpenSSL is
 * available. Falls back to a clearly marked reversible encoding otherwise.
 */
class Server_Pulse_Crypto {

	/**
	 * Marker used for encrypted payloads.
	 */
	const PREFIX = 'sp-enc::';

	/**
	 * Derive the encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'server-pulse' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );

		return hash( 'sha256', $salt . 'server-pulse-v1', true );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Idempotent: a value that is already encrypted is returned untouched. This
	 * protects against WordPress running the settings sanitize callback twice
	 * (which would otherwise double-encrypt and break authentication).
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain || 0 === strpos( $plain, self::PREFIX ) ) {
			return $plain;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return self::PREFIX . 'plain::' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = openssl_random_pseudo_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return self::PREFIX . 'plain::' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return self::PREFIX . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Automatically unwraps values that were accidentally encrypted more than
	 * once by older versions.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		$guard = 0;
		while ( 0 === strpos( $stored, self::PREFIX ) && $guard < 5 ) {
			$decoded = self::decrypt_once( $stored );

			if ( '' === $decoded || $decoded === $stored ) {
				break;
			}

			$stored = $decoded;
			$guard++;
		}

		return $stored;
	}

	/**
	 * Decrypt a single layer.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private static function decrypt_once( $stored ) {
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		$payload = substr( $stored, strlen( self::PREFIX ) );

		if ( 0 === strpos( $payload, 'plain::' ) ) {
			return (string) base64_decode( substr( $payload, 7 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$decoded   = base64_decode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

		if ( strlen( $decoded ) <= $iv_length ) {
			return '';
		}

		$iv     = substr( $decoded, 0, $iv_length );
		$cipher = substr( $decoded, $iv_length );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Whether real encryption is available.
	 *
	 * @return bool
	 */
	public static function is_secure() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}
}
