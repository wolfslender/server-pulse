<?php
/**
 * Plugin Name:       Server Pulse
 * Plugin URI:        https://github.com/wolfslender/server-pulse
 * Description:       Real-time server and WordPress health monitoring for any host. Native, cPanel, WP Engine and WordPress data providers with history, health score and alerts.
 * Version:           3.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Alexis Olivero
 * Author URI:        https://oliverodev.pages.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       server-pulse
 * Domain Path:       /languages
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

define( 'SERVER_PULSE_VERSION', '3.0.1' );
define( 'SERVER_PULSE_DB_VERSION', '1' );
define( 'SERVER_PULSE_FILE', __FILE__ );
define( 'SERVER_PULSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVER_PULSE_URL', plugin_dir_url( __FILE__ ) );
define( 'SERVER_PULSE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lightweight PSR-ish autoloader for the plugin classes.
 *
 * `Server_Pulse_WPEngine_Provider` -> `includes/providers/class-server-pulse-wpengine-provider.php`
 * `Server_Pulse_Admin`             -> `includes/class-server-pulse-admin.php`
 *
 * @param string $class Class name.
 * @return void
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Server_Pulse' ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', $class ) );

		$candidates = array( 'class-' . $relative );

		if ( '-interface' === substr( $relative, -10 ) ) {
			$candidates[] = 'interface-' . substr( $relative, 0, -10 );
		}

		$directories = array(
			SERVER_PULSE_DIR . 'includes/',
			SERVER_PULSE_DIR . 'includes/providers/',
		);

		foreach ( $candidates as $filename ) {
			foreach ( $directories as $directory ) {
				$path = $directory . $filename . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	}
);

require_once SERVER_PULSE_DIR . 'includes/class-server-pulse-util.php';

register_activation_hook( __FILE__, array( 'Server_Pulse_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Server_Pulse_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * @return Server_Pulse_Plugin
 */
function server_pulse() {
	return Server_Pulse_Plugin::instance();
}

add_action( 'plugins_loaded', 'server_pulse', 5 );
