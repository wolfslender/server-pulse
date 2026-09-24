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
 * Prefers AES-256-GCM (authenticated encryption). Falls back to an
 * HMAC-authenticated AES-256-CBC envelope on old OpenSSL builds, and to an
 * HMAC-authenticated keystream when OpenSSL is unavailable at all. Every
 * stored value is wrapped in a tagged payload so old formats keep decrypting
 * after upgrades.
 */
class Server_Pulse_Crypto {

	/**
	 * Marker used for encrypted payloads.
	 */
	const PREFIX = 'sp-enc::';

	/**
	 * Option holding the per-install random secret.
	 */
	const SECRET_OPTION = 'server_pulse_crypto_secret';

	/**
	 * Derive the current encryption key from salts and the per-install secret.
	 *
	 * @return string
	 */
	private static function key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );

		return hash( 'sha256', $salt . self::secret() . 'server-pulse-v1', true );
	}

	/**
	 * Derive the legacy key used before the per-install secret existed.
	 *
	 * @return string
	 */
	private static function legacy_key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'server-pulse' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );

		return hash( 'sha256', $salt . 'server-pulse-v1', true );
	}

	/**
	 * Candidate keys used when decrypting, newest first.
	 *
	 * @return string[]
	 */
	private static function keys() {
		return array( self::key(), self::legacy_key() );
	}

	/**
	 * Per-install random secret, generated on first use.
	 *
	 * Using a stored secret means the encryption key is never a publicly
	 * computable function of the salts alone.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = bin2hex( self::random_bytes( 32 ) );

			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Derive a purpose-separated subkey.
	 *
	 * @param string $purpose Purpose label.
	 * @param string $key     Master key.
	 * @return string
	 */
	private static function subkey( $purpose, $key ) {
		return hash_hmac( 'sha256', $purpose, $key, true );
	}

	/**
	 * Cryptographically secure random bytes.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random_bytes( $length ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $length );
			} catch ( Exception $exception ) {
				// Fall through to the OpenSSL generator.
			}
		}

		if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$strong = false;
			$bytes  = openssl_random_pseudo_bytes( $length, $strong );

			if ( false !== $bytes && $strong ) {
				return $bytes;
			}
		}

		throw new RuntimeException( 'No cryptographically secure random source is available.' );
	}

	/**
	 * Byte-wise XOR of two equal-length strings.
	 *
	 * @param string $a Left operand.
	 * @param string $b Right operand.
	 * @return string
	 */
	private static function xor_strings( $a, $b ) {
		$length = min( strlen( $a ), strlen( $b ) );
		$out    = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $a[ $i ] ^ $b[ $i ];
		}

		return $out;
	}

	/**
	 * Build a keystream for the OpenSSL-less fallback cipher.
	 *
	 * @param string $nonce Nonce.
	 * @param string $key   Encryption subkey.
	 * @param int    $length Plaintext length.
	 * @return string
	 */
	private static function keystream( $nonce, $key, $length ) {
		$stream  = '';
		$counter = 0;

		while ( strlen( $stream ) < $length ) {
			$stream .= hash_hmac( 'sha256', $nonce . pack( 'N', $counter ), $key, true );
			$counter++;
		}

		return substr( $stream, 0, $length );
	}

	/**
	 * Whether a stored value already carries an encrypted payload.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		$value = (string) $value;

		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return false;
		}

		$payload = substr( $value, strlen( self::PREFIX ) );

		foreach ( array( 'gcm::', 'cbc::', 'xor::', 'plain::' ) as $tag ) {
			if ( 0 === strpos( $payload, $tag ) ) {
				return true;
			}
		}

		return false;
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

		if ( '' === $plain || self::is_encrypted( $plain ) ) {
			return $plain;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return self::encrypt_xor( $plain );
		}

		$key = self::key();

		// Preferred: authenticated encryption.
		if ( in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv_length = openssl_cipher_iv_length( 'aes-256-gcm' );
			$iv        = self::random_bytes( $iv_length );
			$tag       = '';
			$cipher    = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( false !== $cipher ) {
				return self::PREFIX . 'gcm::' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		// Fallback: HMAC-authenticated CBC.
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = self::random_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return self::encrypt_xor( $plain );
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, self::subkey( 'mac', $key ), true );

		return self::PREFIX . 'cbc::' . base64_encode( $iv . $cipher . $mac ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Authenticated keystream fallback used when OpenSSL is unavailable.
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	private static function encrypt_xor( $plain ) {
		$key     = self::key();
		$nonce   = self::random_bytes( 16 );
		$cipher  = self::xor_strings( $plain, self::keystream( $nonce, self::subkey( 'enc', $key ), strlen( $plain ) ) );
		$mac     = hash_hmac( 'sha256', $nonce . $cipher, self::subkey( 'mac', $key ), true );

		return self::PREFIX . 'xor::' . base64_encode( $nonce . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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

		if ( 0 === strpos( $payload, 'xor::' ) ) {
			return self::decrypt_xor( substr( $payload, 5 ) );
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		if ( 0 === strpos( $payload, 'gcm::' ) ) {
			foreach ( self::keys() as $key ) {
				$plain = self::decrypt_gcm( substr( $payload, 5 ), $key );

				if ( '' !== $plain ) {
					return $plain;
				}
			}

			return '';
		}

		if ( 0 === strpos( $payload, 'cbc::' ) ) {
			foreach ( self::keys() as $key ) {
				$plain = self::decrypt_cbc_auth( substr( $payload, 5 ), $key );

				if ( '' !== $plain ) {
					return $plain;
				}
			}

			return '';
		}

		// Legacy unauthenticated CBC payload.
		foreach ( self::keys() as $key ) {
			$plain = self::decrypt_cbc_legacy( $payload, $key );

			if ( '' !== $plain ) {
				return $plain;
			}
		}

		return '';
	}

	/**
	 * Decrypt an authenticated keystream payload (nonce | mac | ciphertext).
	 *
	 * @param string $payload Base64 payload.
	 * @return string
	 */
	private static function decrypt_xor( $payload ) {
		$decoded = base64_decode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( strlen( $decoded ) <= 48 ) {
			return '';
		}

		$nonce  = substr( $decoded, 0, 16 );
		$mac    = substr( $decoded, 16, 32 );
		$cipher = substr( $decoded, 48 );

		foreach ( self::keys() as $key ) {
			$expected = hash_hmac( 'sha256', $nonce . $cipher, self::subkey( 'mac', $key ), true );

			if ( hash_equals( $expected, $mac ) ) {
				return self::xor_strings( $cipher, self::keystream( $nonce, self::subkey( 'enc', $key ), strlen( $cipher ) ) );
			}
		}

		return '';
	}

	/**
	 * Decrypt an AES-256-GCM payload (iv | tag | ciphertext).
	 *
	 * @param string $payload Base64 payload.
	 * @param string $key     Encryption key.
	 * @return string
	 */
	private static function decrypt_gcm( $payload, $key ) {
		$decoded    = base64_decode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_length  = openssl_cipher_iv_length( 'aes-256-gcm' );
		$tag_length = 16;

		if ( strlen( $decoded ) <= ( $iv_length + $tag_length ) ) {
			return '';
		}

		$iv     = substr( $decoded, 0, $iv_length );
		$tag    = substr( $decoded, $iv_length, $tag_length );
		$cipher = substr( $decoded, $iv_length + $tag_length );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Decrypt an authenticated AES-256-CBC payload (iv | ciphertext | mac).
	 *
	 * @param string $payload Base64 payload.
	 * @param string $key     Encryption key.
	 * @return string
	 */
	private static function decrypt_cbc_auth( $payload, $key ) {
		$decoded   = base64_decode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

		if ( strlen( $decoded ) <= ( $iv_length + 32 ) ) {
			return '';
		}

		$iv     = substr( $decoded, 0, $iv_length );
		$mac    = substr( $decoded, -32 );
		$cipher = substr( $decoded, $iv_length, -32 );
		$expect = hash_hmac( 'sha256', $iv . $cipher, self::subkey( 'mac', $key ), true );

		if ( ! hash_equals( $expect, $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Decrypt a legacy unauthenticated AES-256-CBC payload (iv | ciphertext).
	 *
	 * @param string $payload Base64 payload.
	 * @param string $key     Encryption key.
	 * @return string
	 */
	private static function decrypt_cbc_legacy( $payload, $key ) {
		$decoded   = base64_decode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

		if ( strlen( $decoded ) <= $iv_length ) {
			return '';
		}

		$iv     = substr( $decoded, 0, $iv_length );
		$cipher = substr( $decoded, $iv_length );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Whether real authenticated encryption is available.
	 *
	 * @return bool
	 */
	public static function is_secure() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}
}
