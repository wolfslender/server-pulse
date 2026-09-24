<?php
/**
 * Installs and removes the early crash loader mu-plugin.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the self-contained mu-plugin that guards against plugins which fatal
 * on every load.
 */
class Server_Pulse_Loader {

	/**
	 * mu-plugin file name.
	 */
	const FILENAME = 'server-pulse-loader.php';

	/**
	 * mu-plugins directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
	}

	/**
	 * Destination path of the loader.
	 *
	 * @return string
	 */
	public static function path() {
		return self::dir() . '/' . self::FILENAME;
	}

	/**
	 * Bundled loader template inside the plugin.
	 *
	 * @return string
	 */
	public static function source() {
		return SERVER_PULSE_DIR . 'mu-plugin/' . self::FILENAME;
	}

	/**
	 * Whether the loader is installed.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return file_exists( self::path() );
	}

	/**
	 * Install the loader into mu-plugins.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		if ( ! file_exists( self::source() ) ) {
			return new WP_Error( 'server_pulse_loader_source', __( 'The bundled loader file is missing.', 'server-pulse' ) );
		}

		if ( ! is_dir( self::dir() ) && ! wp_mkdir_p( self::dir() ) ) {
			return new WP_Error( 'server_pulse_loader_dir', __( 'Could not create the mu-plugins directory.', 'server-pulse' ) );
		}

		if ( self::is_current() ) {
			return true;
		}

		if ( self::copy( self::source(), self::path() ) ) {
			return true;
		}

		return new WP_Error( 'server_pulse_loader_copy', __( 'Could not write the loader. Check filesystem permissions.', 'server-pulse' ) );
	}

	/**
	 * Whether the installed loader matches the bundled template.
	 *
	 * @return bool
	 */
	private static function is_current() {
		if ( ! file_exists( self::path() ) || ! file_exists( self::source() ) ) {
			return false;
		}

		return md5_file( self::path() ) === md5_file( self::source() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_md5_file
	}

	/**
	 * Remove the loader.
	 *
	 * @return true|WP_Error
	 */
	public static function remove() {
		if ( ! self::is_installed() ) {
			return true;
		}

		if ( self::delete( self::path() ) ) {
			return true;
		}

		return new WP_Error( 'server_pulse_loader_delete', __( 'Could not remove the loader. Check filesystem permissions.', 'server-pulse' ) );
	}

	/**
	 * Keep the loader in sync with a boolean setting.
	 *
	 * @param bool $enabled Desired state.
	 * @return void
	 */
	public static function sync( $enabled ) {
		if ( $enabled ) {
			self::install();
		} else {
			self::remove();
		}
	}

	/**
	 * Copy a file, preferring the WordPress filesystem API.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	private static function copy( $from, $to ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->copy( $from, $to, true, FS_CHMOD_FILE ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		return (bool) @copy( $from, $to );
	}

	/**
	 * Delete a file, preferring the WordPress filesystem API.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function delete( $path ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->delete( $path ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return (bool) @unlink( $path );
	}
}