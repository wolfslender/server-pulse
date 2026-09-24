<?php
/**
 * Crash sentinel: detects plugin activations that kill the request.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Watches risky operations (plugin activation) out of band.
 *
 * A 502 / OOM / timeout kills the PHP worker without letting WordPress show
 * anything. Before a plugin is activated we drop a marker; on normal
 * completion a shutdown handler removes it. If the marker survives to the next
 * admin request, the previous request died and we know exactly which plugin
 * was being activated, how far it got and whether PHP caught a fatal.
 */
class Server_Pulse_Sentinel {

	/**
	 * Active-operation marker (non-autoloaded option).
	 */
	const OPTION = 'server_pulse_sentinel';

	/**
	 * Crash history (non-autoloaded option).
	 */
	const CRASHES = 'server_pulse_crashes';

	/**
	 * Transient key holding the pending admin notice.
	 */
	const NOTICE = 'server_pulse_crash_notice';

	/**
	 * Maximum crash records kept.
	 */
	const MAX_CRASHES = 20;

	/**
	 * Whether the current request armed the guard.
	 *
	 * @var bool
	 */
	private $armed = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'detect' ), 1 );
		add_action( 'admin_init', array( $this, 'arm' ), 5 );
		add_action( 'activate_plugin', array( $this, 'on_activate' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'on_activated' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_server_pulse_sentinel_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_post_server_pulse_sentinel_dismiss', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Breadcrumb: a plugin activation is about to be attempted.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 * @return void
	 */
	public function on_activate( $plugin, $network_wide = false ) {
		$this->breadcrumb( 'activate_plugin:' . $plugin );
	}

	/**
	 * Breadcrumb: a plugin activation callbacks finished.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 * @return void
	 */
	public function on_activated( $plugin, $network_wide = false ) {
		$this->breadcrumb( 'activated_plugin:' . $plugin );
	}

	/**
	 * Fatal error bitmask.
	 *
	 * @return int
	 */
	private static function fatal_types() {
		return E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
	}

	/**
	 * Whether the sentinel is enabled.
	 *
	 * @return bool
	 */
	private function enabled() {
		return (bool) Server_Pulse_Settings::get( 'sentinel_enabled', 1 );
	}

	/**
	 * Whether we are on a plugin management screen.
	 *
	 * @return bool
	 */
	private function on_plugins_screen() {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		return in_array( $pagenow, array( 'plugins.php', 'plugins-network.php' ), true );
	}

	/**
	 * Drop the marker before a plugin activation runs.
	 *
	 * @return void
	 */
	public function arm() {
		if ( ! $this->enabled() || ! $this->on_plugins_screen() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! in_array( $action, array( 'activate', 'activate-selected' ), true ) ) {
			return;
		}

		$plugins = array();

		if ( 'activate' === $action && isset( $_REQUEST['plugin'] ) ) {
			$plugins[] = sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) );
		} elseif ( 'activate-selected' === $action && isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) ) {
			$plugins = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_REQUEST['checked'] ) );
		}

		$plugins = array_values( array_filter( array_map( 'strval', $plugins ) ) );

		if ( ! $plugins ) {
			return;
		}

		// Only arm for a genuine, nonce-verified activation request.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( 'activate' === $action ) {
			if ( ! wp_verify_nonce( $nonce, 'activate-plugin_' . $plugins[0] ) ) {
				return;
			}
		} elseif ( ! wp_verify_nonce( $nonce, 'bulk-plugins' ) ) {
			return;
		}

		$marker = array(
			'v'           => self::MARKER_VERSION,
			'type'        => 'activate',
			'action'      => $action,
			'plugins'     => $plugins,
			'primary'     => $plugins[0],
			'prev_active' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'started_at'  => time(),
			'uri'         => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'memory'      => memory_get_usage( true ),
			'peak'        => memory_get_peak_usage( true ),
			'breadcrumbs' => array( 'armed' ),
			'fatal'       => null,
		);

		$marker['sig'] = self::sign_marker( $marker );

		update_option( self::OPTION, $marker, true );

		$this->armed = true;
		register_shutdown_function( array( $this, 'shutdown' ) );
	}

	/**
	 * Version of the marker format.
	 */
	const MARKER_VERSION = 1;

	/**
	 * HMAC key for signing the marker.
	 *
	 * @return string
	 */
	private static function sign_key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );

		return hash( 'sha256', $salt . Server_Pulse_Crypto::secret() . 'server-pulse-sentinel', true );
	}

	/**
	 * Legacy HMAC key used before the per-install secret was introduced.
	 *
	 * @return string
	 */
	private static function legacy_sign_key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );

		return hash( 'sha256', $salt . 'server-pulse-sentinel', true );
	}

	/**
	 * Signature over the security-relevant marker fields.
	 *
	 * @param array $marker Marker.
	 * @return string
	 */
	public static function sign_marker( array $marker ) {
		return hash_hmac( 'sha256', self::signature_payload( $marker ), self::sign_key() );
	}

	/**
	 * Canonical string of the fields covered by the signature.
	 *
	 * @param array $marker Marker.
	 * @return string
	 */
	public static function signature_payload( array $marker ) {
		return implode(
			'|',
			array(
				(string) ( isset( $marker['v'] ) ? (int) $marker['v'] : self::MARKER_VERSION ),
				(string) ( isset( $marker['type'] ) ? $marker['type'] : '' ),
				(string) ( isset( $marker['action'] ) ? $marker['action'] : '' ),
				(string) ( isset( $marker['primary'] ) ? $marker['primary'] : '' ),
				implode( ',', array_map( 'strval', (array) ( isset( $marker['plugins'] ) ? $marker['plugins'] : array() ) ) ),
				(string) ( isset( $marker['started_at'] ) ? (int) $marker['started_at'] : 0 ),
				implode( ',', array_map( 'strval', (array) ( isset( $marker['prev_active'] ) ? $marker['prev_active'] : array() ) ) ),
			)
		);
	}

	/**
	 * Whether a marker carries a valid signature.
	 *
	 * @param array $marker Marker.
	 * @return bool
	 */
	public static function verify_marker( array $marker ) {
		if ( empty( $marker['sig'] ) ) {
			return false;
		}

		$payload = self::signature_payload( $marker );
		$sig     = (string) $marker['sig'];

		return hash_equals( hash_hmac( 'sha256', $payload, self::sign_key() ), $sig )
			|| hash_equals( hash_hmac( 'sha256', $payload, self::legacy_sign_key() ), $sig );
	}

	/**
	 * Record a breadcrumb on the active marker.
	 *
	 * @param string $label Step label.
	 * @return void
	 */
	public function breadcrumb( $label ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$marker = get_option( self::OPTION );

		if ( ! is_array( $marker ) ) {
			return;
		}

		$marker['breadcrumbs'][] = sanitize_text_field( $label );
		$marker['memory']        = memory_get_usage( true );
		$marker['peak']          = max( (int) $marker['peak'], memory_get_peak_usage( true ) );

		update_option( self::OPTION, $marker, true );
	}

	/**
	 * Shutdown handler: keep the marker on a fatal, clear it otherwise.
	 *
	 * @return void
	 */
	public function shutdown() {
		$marker = get_option( self::OPTION );

		if ( ! is_array( $marker ) ) {
			return;
		}

		$error = error_get_last();

		if ( $error && ( (int) $error['type'] & self::fatal_types() ) ) {
			$marker['fatal']       = array(
				'type'    => (int) $error['type'],
				'message' => (string) $error['message'],
				'file'    => (string) $error['file'],
				'line'    => (int) $error['line'],
			);
			$marker['breadcrumbs'][] = 'php-fatal';
			$marker['peak']          = max( (int) $marker['peak'], memory_get_peak_usage( true ) );

			update_option( self::OPTION, $marker, true );
			return;
		}

		// The request finished without a fatal: the operation did not crash.
		delete_option( self::OPTION );
	}

	/**
	 * Detect a marker left behind by a request that died.
	 *
	 * @return void
	 */
	public function detect() {
		if ( ! $this->enabled() || ! $this->on_detect_screen() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$marker = get_option( self::OPTION );

		if ( ! is_array( $marker ) || empty( $marker['started_at'] ) ) {
			return;
		}

		// Refuse to act on a marker we did not sign.
		if ( ! self::verify_marker( $marker ) ) {
			delete_option( self::OPTION );
			return;
		}

		// Ignore the request that has just armed the guard. A generous grace
		// window avoids mistaking a slow but successful activation for a crash.
		if ( ( time() - (int) $marker['started_at'] ) < 30 ) {
			return;
		}

		$crash = array(
			'id'          => uniqid( 'crash_', false ),
			'type'        => 'activation',
			'action'      => isset( $marker['action'] ) ? (string) $marker['action'] : 'activate',
			'plugin'      => isset( $marker['primary'] ) ? (string) $marker['primary'] : '',
			'plugins'     => isset( $marker['plugins'] ) ? (array) $marker['plugins'] : array(),
			'started_at'  => (int) $marker['started_at'],
			'detected_at' => time(),
			'fatal'       => isset( $marker['fatal'] ) ? $marker['fatal'] : null,
			'breadcrumbs' => isset( $marker['breadcrumbs'] ) ? (array) $marker['breadcrumbs'] : array(),
			'memory'      => isset( $marker['memory'] ) ? (int) $marker['memory'] : 0,
			'peak'        => isset( $marker['peak'] ) ? (int) $marker['peak'] : 0,
			'uri'         => isset( $marker['uri'] ) ? (string) $marker['uri'] : '',
			'prev_active' => isset( $marker['prev_active'] ) ? (array) $marker['prev_active'] : array(),
			'rolled_back' => false,
			'dismissed'   => false,
		);

		$this->record( $crash );
		delete_option( self::OPTION );

		// Keep the advisor honest about the new event.
		if ( class_exists( 'Server_Pulse_Advisor' ) ) {
			Server_Pulse_Advisor::flush();
		}

		if ( (int) Server_Pulse_Settings::get( 'sentinel_auto_rollback', 0 ) ) {
			$this->rollback_crash( $crash['id'] );
		}
	}

	/**
	 * Whether the current screen should run crash detection.
	 *
	 * @return bool
	 */
	private function on_detect_screen() {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		if ( in_array( $pagenow, array( 'plugins.php', 'plugins-network.php', 'index.php' ), true ) ) {
			return true;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 0 === strpos( $page, 'server-pulse' );
	}

	/**
	 * Append a crash to the capped history.
	 *
	 * @param array $crash Crash record.
	 * @return void
	 */
	private function record( array $crash ) {
		$list = self::crash_history();

		array_unshift( $list, $crash );
		$list = array_slice( $list, 0, self::MAX_CRASHES );

		update_option( self::CRASHES, $list, false );
	}

	/**
	 * Stored crash history.
	 *
	 * @return array
	 */
	public static function crash_history() {
		$list = get_option( self::CRASHES, array() );

		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Find a crash by id.
	 *
	 * @param string $id Crash id.
	 * @return array|null
	 */
	public static function find( $id ) {
		foreach ( self::crash_history() as $crash ) {
			if ( isset( $crash['id'] ) && $crash['id'] === $id ) {
				return $crash;
			}
		}

		return null;
	}

	/**
	 * Deactivate the plugins a crash was caused by.
	 *
	 * @param string $id Crash id.
	 * @return bool
	 */
	public function rollback_crash( $id ) {
		$crash = self::find( $id );

		if ( ! $crash ) {
			return false;
		}

		$prev    = isset( $crash['prev_active'] ) ? (array) $crash['prev_active'] : array();
		$culprit = isset( $crash['plugins'] ) ? array_map( 'strval', (array) $crash['plugins'] ) : array();

		// Restore the active list to what it was before the crashed activation.
		$restore = array_values( array_diff( array_map( 'strval', $prev ), $culprit ) );

		update_option( 'active_plugins', $restore );

		$this->mark_rolled_back( $id );

		return true;
	}

	/**
	 * Flag a crash as rolled back.
	 *
	 * @param string $id Crash id.
	 * @return void
	 */
	private function mark_rolled_back( $id ) {
		$list = self::crash_history();

		foreach ( $list as &$crash ) {
			if ( isset( $crash['id'] ) && $crash['id'] === $id ) {
				$crash['rolled_back'] = true;
			}
		}

		update_option( self::CRASHES, $list, false );
	}

	/**
	 * Human explanation of a crash cause.
	 *
	 * @param array $crash Crash record.
	 * @return string
	 */
	public static function cause( array $crash ) {
		if ( ! empty( $crash['fatal']['message'] ) ) {
			return sprintf(
				/* translators: %s: PHP fatal error message. */
				__( 'PHP fatal error: %s', 'server-pulse' ),
				$crash['fatal']['message']
			);
		}

		return __( 'The PHP worker was killed before it could respond — usually out of memory (OOM), a timeout or a crash in a native extension (the nginx 502 case).', 'server-pulse' );
	}

	/**
	 * Latest crash that has not been dismissed.
	 *
	 * @return array|null
	 */
	public function latest_notice_crash() {
		foreach ( self::crash_history() as $crash ) {
			if ( empty( $crash['dismissed'] ) ) {
				return $crash;
			}
		}

		return null;
	}

	/**
	 * Memory hint for a crash.
	 *
	 * @param array $crash Crash record.
	 * @return string
	 */
	private function memory_hint( array $crash ) {
		$peak = isset( $crash['peak'] ) ? (int) $crash['peak'] : 0;

		if ( $peak <= 0 ) {
			return '';
		}

		$limit = Server_Pulse_Util::to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $peak >= ( 0.9 * $limit ) ) {
			return sprintf(
				/* translators: 1: peak memory, 2: memory limit. */
				__( 'Peak memory was %1$s of a %2$s limit — this is almost certainly an out-of-memory kill.', 'server-pulse' ),
				Server_Pulse_Util::format_bytes( $peak ),
				Server_Pulse_Util::format_bytes( $limit )
			);
		}

		return sprintf(
			/* translators: %s: peak memory. */
			__( 'Peak memory before the crash: %s.', 'server-pulse' ),
			Server_Pulse_Util::format_bytes( $peak )
		);
	}

	/**
	 * Render the admin notice for a pending crash.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only look up crashes on the screens where the notice is useful.
		if ( ! $this->on_detect_screen() ) {
			return;
		}

		$crash = $this->latest_notice_crash();

		if ( ! $crash ) {
			return;
		}

		$plugin = isset( $crash['plugin'] ) ? $crash['plugin'] : '';
		$total  = count( self::crash_history() );

		$rollback_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=server_pulse_sentinel_rollback&crash=' . rawurlencode( $crash['id'] ) ),
			'server_pulse_sentinel_rollback'
		);

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=server_pulse_sentinel_dismiss&crash=' . rawurlencode( $crash['id'] ) ),
			'server_pulse_sentinel_dismiss'
		);

		$diagnostics = admin_url( 'tools.php?page=server-pulse&tab=diagnostics' );
		$hint        = $this->memory_hint( $crash );

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Server Pulse — activation crash detected', 'server-pulse' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: plugin file. */
					esc_html__( 'Activating %s killed the request before WordPress could respond.', 'server-pulse' ),
					'<code>' . esc_html( $plugin ) . '</code>'
				);
				?>
				<br />
				<?php echo esc_html( self::cause( $crash ) ); ?>
				<?php if ( $hint ) : ?>
					<br /><?php echo esc_html( $hint ); ?>
				<?php endif; ?>
				<?php if ( $total > 1 ) : ?>
					<br />
					<?php
					printf(
						/* translators: %d: number of crashes. */
						esc_html( _n( '%d crash recorded in total.', '%d crashes recorded in total.', $total, 'server-pulse' ) ),
						(int) $total
					);
					?>
				<?php endif; ?>
			</p>
			<p>
				<?php if ( empty( $crash['rolled_back'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $rollback_url ); ?>"><?php esc_html_e( 'Deactivate it and restore plugins', 'server-pulse' ); ?></a>
				<?php else : ?>
					<span class="sp-badge is-on"><?php esc_html_e( 'Already rolled back', 'server-pulse' ); ?></span>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $diagnostics ); ?>"><?php esc_html_e( 'Open diagnostics', 'server-pulse' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'server-pulse' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the rollback admin-post action.
	 *
	 * @return void
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'server-pulse' ) );
		}

		check_admin_referer( 'server_pulse_sentinel_rollback' );

		$id = isset( $_GET['crash'] ) ? sanitize_text_field( wp_unslash( $_GET['crash'] ) ) : '';
		$this->rollback_crash( $id );

		wp_safe_redirect(
			add_query_arg(
				'sp-rolled-back',
				'1',
				wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the dismiss admin-post action.
	 *
	 * @return void
	 */
	public function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'server-pulse' ) );
		}

		check_admin_referer( 'server_pulse_sentinel_dismiss' );

		$id = isset( $_GET['crash'] ) ? sanitize_text_field( wp_unslash( $_GET['crash'] ) ) : '';
		$this->mark_dismissed( $id );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Flag a crash as dismissed.
	 *
	 * @param string $id Crash id.
	 * @return void
	 */
	private function mark_dismissed( $id ) {
		$list = self::crash_history();

		foreach ( $list as &$crash ) {
			if ( isset( $crash['id'] ) && $crash['id'] === $id ) {
				$crash['dismissed'] = true;
			}
		}

		update_option( self::CRASHES, $list, false );
	}
}