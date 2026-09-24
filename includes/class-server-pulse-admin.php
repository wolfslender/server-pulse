<?php
/**
 * Admin UI controller.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers menus, assets and settings.
 */
class Server_Pulse_Admin {

	/**
	 * The single Server Pulse page hook (all tabs share one screen).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Valid tabs keyed by slug.
	 *
	 * @var string[]
	 */
	private static $tabs = array( 'dashboard', 'settings', 'alerts', 'diagnostics', 'traffic' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . SERVER_PULSE_BASENAME, array( $this, 'action_links' ) );
		add_action( 'update_option_' . Server_Pulse_Settings::OPTION, array( $this, 'reschedule_cron' ), 10, 0 );
		add_action( 'update_option_' . Server_Pulse_Settings::OPTION, array( $this, 'sync_loader' ), 10, 0 );
		add_action( 'admin_post_server_pulse_export_traffic', array( $this, 'export_traffic' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'tools.php',
			__( 'Server Pulse', 'server-pulse' ),
			__( 'Server Pulse', 'server-pulse' ),
			'manage_options',
			'server-pulse',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Current tab slug.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, self::$tabs, true ) ? $tab : 'dashboard';
	}

	/**
	 * Render the single Server Pulse screen, routed by the tab parameter.
	 *
	 * @return void
	 */
	public function render_page() {
		switch ( $this->current_tab() ) {
			case 'settings':
				$this->render_settings();
				break;
			case 'alerts':
				$this->render_alerts();
				break;
			case 'diagnostics':
				$this->render_advisor();
				break;
			case 'traffic':
				$this->render_traffic();
				break;
			default:
				$this->render_dashboard();
		}
	}

	/**
	 * Print the tab navigation shared by every screen.
	 *
	 * @param string $active Active tab slug.
	 * @return void
	 */
	public static function render_tabs( $active ) {
		$labels = array(
			'dashboard'   => __( 'Dashboard', 'server-pulse' ),
			'settings'    => __( 'Settings', 'server-pulse' ),
			'alerts'      => __( 'Alerts', 'server-pulse' ),
			'diagnostics' => __( 'Diagnostics', 'server-pulse' ),
			'traffic'     => __( 'Traffic & logs', 'server-pulse' ),
		);

		echo '<nav class="nav-tab-wrapper sp-tabs">';

		foreach ( $labels as $slug => $label ) {
			$url = admin_url( 'tools.php?page=server-pulse&tab=' . $slug );

			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$active === $slug ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'server_pulse_settings_group',
			Server_Pulse_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Server_Pulse_Settings', 'sanitize' ),
				'default'           => Server_Pulse_Settings::defaults(),
			)
		);
	}

	/**
	 * Add a settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'tools.php?page=server-pulse&tab=settings' );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'server-pulse' ) )
		);

		return $links;
	}

	/**
	 * Enqueue assets on plugin screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$is_widget = ( 'index.php' === $hook );
		$is_page   = ( $hook === $this->page_hook );

		if ( ! $is_page && ! $is_widget ) {
			return;
		}

		wp_enqueue_style(
			'server-pulse-admin',
			SERVER_PULSE_URL . 'assets/css/admin.css',
			array(),
			SERVER_PULSE_VERSION
		);

		if ( $is_widget ) {
			return;
		}

		if ( 'diagnostics' === $this->current_tab() ) {
			wp_enqueue_script(
				'server-pulse-advisor',
				SERVER_PULSE_URL . 'assets/js/advisor.js',
				array(),
				SERVER_PULSE_VERSION,
				true
			);

			wp_localize_script(
				'server-pulse-advisor',
				'serverPulseAdvisor',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'server_pulse_nonce' ),
					'i18n'    => array(
						'running'    => __( 'Running diagnostics…', 'server-pulse' ),
						'fixing'     => __( 'Applying fix…', 'server-pulse' ),
						'error'      => __( 'Request failed.', 'server-pulse' ),
						'confirm'    => __( 'Run this fix now?', 'server-pulse' ),
						'empty'      => __( 'No findings yet.', 'server-pulse' ),
						'emptyFiltered' => __( 'No findings match the active filters.', 'server-pulse' ),
						'clearFilters'  => __( 'Clear filters', 'server-pulse' ),
						'applyFix'   => __( 'Apply fix', 'server-pulse' ),
						'all'        => __( 'All', 'server-pulse' ),
						'categories' => array(
							'server'      => __( 'Server & PHP', 'server-pulse' ),
							'database'    => __( 'Database', 'server-pulse' ),
							'wordpress'   => __( 'WordPress', 'server-pulse' ),
							'security'    => __( 'Security', 'server-pulse' ),
							'performance' => __( 'Performance', 'server-pulse' ),
							'storage'     => __( 'Storage', 'server-pulse' ),
						),
						'severities' => array(
							'critical' => __( 'Critical', 'server-pulse' ),
							'warning'  => __( 'Warning', 'server-pulse' ),
							'info'     => __( 'Info', 'server-pulse' ),
							'good'     => __( 'OK', 'server-pulse' ),
						),
					),
				)
			);

			return;
		}

		if ( in_array( $this->current_tab(), array( 'settings', 'alerts' ), true ) ) {
			wp_enqueue_script(
				'server-pulse-settings',
				SERVER_PULSE_URL . 'assets/js/settings.js',
				array(),
				SERVER_PULSE_VERSION,
				true
			);

			wp_localize_script(
				'server-pulse-settings',
				'serverPulseSettings',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'server_pulse_nonce' ),
					'i18n'    => array(
						'testing'    => __( 'Testing…', 'server-pulse' ),
						'ok'         => __( 'Connection successful.', 'server-pulse' ),
						'failed'     => __( 'Connection failed.', 'server-pulse' ),
						'error'      => __( 'Request failed.', 'server-pulse' ),
						'sending'    => __( 'Sending…', 'server-pulse' ),
						'testSent'   => __( 'Test notification sent.', 'server-pulse' ),
						'testFailed' => __( 'Test finished with errors.', 'server-pulse' ),
					),
				)
			);

			return;
		}

		if ( 'traffic' === $this->current_tab() ) {
			wp_enqueue_script(
				'server-pulse-traffic',
				SERVER_PULSE_URL . 'assets/js/traffic.js',
				array(),
				SERVER_PULSE_VERSION,
				true
			);

			wp_localize_script(
				'server-pulse-traffic',
				'serverPulseTraffic',
				array(
					'ajaxurl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'server_pulse_nonce' ),
					'isPro'     => Server_Pulse_License::is_pro(),
					'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=server_pulse_export_traffic' ), 'server_pulse_export_traffic' ),
					'i18n'      => array(
						'running'    => __( 'Analyzing logs… this can take a few seconds.', 'server-pulse' ),
						'error'      => __( 'Request failed.', 'server-pulse' ),
						'noFile'     => __( 'Choose a log file first.', 'server-pulse' ),
						'confirmClear' => __( 'Delete the stored reports?', 'server-pulse' ),
						'cleared'    => __( 'Stored reports cleared.', 'server-pulse' ),
						'copied'     => __( 'Copied to clipboard.', 'server-pulse' ),
						'emptyState' => __( 'No report yet. Analyze the WP Engine logs or upload a log export.', 'server-pulse' ),
						'requests'   => __( 'Requests', 'server-pulse' ),
						'emptyUa'    => __( 'Empty UA', 'server-pulse' ),
						'thIp'       => __( 'IP', 'server-pulse' ),
						'thReq'      => __( 'Requests', 'server-pulse' ),
						'th5xx'      => __( '5xx', 'server-pulse' ),
						'thEmptyUa'  => __( 'Empty UA', 'server-pulse' ),
						'thMinute'   => __( 'Minute', 'server-pulse' ),
						'thPath'     => __( 'Path', 'server-pulse' ),
						'thCount'    => __( 'Count', 'server-pulse' ),
						'thSuggestion' => __( 'Suggestion', 'server-pulse' ),
						'thSeverity' => __( 'Severity', 'server-pulse' ),
						'thLocation' => __( 'Location', 'server-pulse' ),
						'thMessage'  => __( 'Message', 'server-pulse' ),
						'missingAssets' => __( 'Top missing assets (404)', 'server-pulse' ),
						'heavyEndpoints' => __( 'Heavy endpoints', 'server-pulse' ),
						'thWhatToDo' => __( 'What to do', 'server-pulse' ),
						'thSearchFor' => __( 'Search for', 'server-pulse' ),
						'howToLook'  => __( 'Where to look: Plugins → Plugin File Editor, or over SSH run grep -rn "snippet" wp-content. The snippet below is what registers or handles the endpoint.', 'server-pulse' ),
						'heavyHelp'  => array(
							'rest'    => __( 'Custom REST route registered by a theme or plugin. If it runs on every page view, cache it (transient/object cache) and add edge caching with the right Vary header.', 'server-pulse' ),
							'ajax'    => __( 'admin-ajax.php request. Find which action is being called (the plugin below) and disable the feature you do not need, or cache the response.', 'server-pulse' ),
							'cron'    => __( 'WP-Cron is being triggered by web requests. Add define( \'DISABLE_WP_CRON\', true ); to wp-config.php and rely on the host cron.', 'server-pulse' ),
							'login'   => __( 'Login page hit by bots. Rate limit or block it at the edge and enable two-factor authentication.', 'server-pulse' ),
							'xmlrpc'  => __( 'XML-RPC endpoint. Disable it if unused (add_filter( \'xmlrpc_enabled\', \'__return_false\' )) or block it at the edge.', 'server-pulse' ),
							'php'     => __( 'A PHP endpoint served by a plugin or theme. Review what handles this path.', 'server-pulse' ),
							'generic' => __( 'Cache or short-circuit this dynamic endpoint.', 'server-pulse' ),
						),
						'busiestMinutes' => __( 'Busiest minutes', 'server-pulse' ),
						'phpErrors'  => __( 'Top PHP errors', 'server-pulse' ),
						'byDay'      => __( '5xx per day', 'server-pulse' ),
						'day'        => __( 'Day', 'server-pulse' ),
						'actions'    => __( 'Recommended actions', 'server-pulse' ),
						'generated'  => __( 'Generated', 'server-pulse' ),
						'capped'     => __( 'Analysis stopped early at the size/time limit; results are partial.', 'server-pulse' ),
					),
				)
			);

			return;
		}

		wp_enqueue_script(
			'server-pulse-charts',
			SERVER_PULSE_URL . 'assets/js/charts.js',
			array(),
			SERVER_PULSE_VERSION,
			true
		);

		wp_enqueue_script(
			'server-pulse-dashboard',
			SERVER_PULSE_URL . 'assets/js/dashboard.js',
			array( 'server-pulse-charts' ),
			SERVER_PULSE_VERSION,
			true
		);

		$sp_alerts = Server_Pulse_Settings::get( 'alerts', array() );

		wp_localize_script(
			'server-pulse-dashboard',
			'serverPulse',
			array(
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'server_pulse_nonce' ),
				'refresh'  => (int) Server_Pulse_Settings::get( 'dashboard_refresh', 15 ),
				'statuses' => ( new Server_Pulse_Provider_Manager() )->statuses(),
				'trends'   => array(
					'deviation' => isset( $sp_alerts['trend_deviation'] ) ? (int) $sp_alerts['trend_deviation'] : 15,
				),
				'i18n'     => array(
					'loading'      => __( 'Collecting live metrics…', 'server-pulse' ),
					'error'        => __( 'Could not fetch metrics.', 'server-pulse' ),
					'notAvailable' => __( 'Not available on this host', 'server-pulse' ),
					'scanning'     => __( 'Scanning storage… this can take a few seconds.', 'server-pulse' ),
					'diskDaysLeft' => __( '~%d days of disk left at the current pace', 'server-pulse' ),
					'diskStable'   => __( 'Disk usage is steady', 'server-pulse' ),
					'diskGrowth'   => __( 'Disk is growing %s/day', 'server-pulse' ),
					'dbGrowth'     => __( 'Database is growing %s/day', 'server-pulse' ),
					'bandwidthProjected' => __( 'Bandwidth projected at ~%d%% of the monthly limit', 'server-pulse' ),
					'cores'        => __( 'cores', 'server-pulse' ),
					'load'         => __( 'load', 'server-pulse' ),
					'files'        => __( 'files', 'server-pulse' ),
					'db'           => __( 'DB', 'server-pulse' ),
					'free'         => __( 'free', 'server-pulse' ),
					'scannedWpContent' => __( 'Scanned wp-content', 'server-pulse' ),
					'cdn'          => __( 'CDN', 'server-pulse' ),
					'noData'       => __( 'No data.', 'server-pulse' ),
					'noProcessData' => __( 'No process data available on this host.', 'server-pulse' ),
					'noAlerts'     => __( 'No alerts. Everything looks healthy.', 'server-pulse' ),
					'noAlertsRecorded' => __( 'No alerts recorded yet.', 'server-pulse' ),
					'enabled'      => __( 'Enabled', 'server-pulse' ),
					'disabled'     => __( 'Disabled', 'server-pulse' ),
					'on'           => __( 'On', 'server-pulse' ),
					'off'          => __( 'Off', 'server-pulse' ),
					'statusActive' => __( 'Active', 'server-pulse' ),
					'statusResolved' => __( 'Resolved', 'server-pulse' ),
					'avg'          => __( 'avg %s', 'server-pulse' ),
					'deviation'    => __( 'Deviation %s pts', 'server-pulse' ),
					'detectedNotConfigured' => __( 'detected on this host but not configured or enabled.', 'server-pulse' ),
					'openSettings' => __( 'Open settings', 'server-pulse' ),
					'severities'   => array(
						'critical' => __( 'Critical', 'server-pulse' ),
						'warning'  => __( 'Warning', 'server-pulse' ),
						'info'     => __( 'Info', 'server-pulse' ),
						'good'     => __( 'OK', 'server-pulse' ),
					),
					'labels'       => array(
						'posts'            => __( 'Posts', 'server-pulse' ),
						'pages'            => __( 'Pages', 'server-pulse' ),
						'comments'         => __( 'Comments', 'server-pulse' ),
						'users'            => __( 'Users', 'server-pulse' ),
						'dbSize'           => __( 'Database size', 'server-pulse' ),
						'dbTables'         => __( 'Database tables', 'server-pulse' ),
						'dbAutoload'       => __( 'Autoloaded options', 'server-pulse' ),
						'revisions'        => __( 'Revisions', 'server-pulse' ),
						'transients'       => __( 'Transients', 'server-pulse' ),
						'cronOverdue'      => __( 'Overdue cron events', 'server-pulse' ),
						'objectCache'      => __( 'Object cache', 'server-pulse' ),
						'storageScan'      => __( 'Storage scan (wp-content)', 'server-pulse' ),
						'uploads'          => __( 'Uploads', 'server-pulse' ),
						'plugins'          => __( 'Plugins', 'server-pulse' ),
						'themes'           => __( 'Themes', 'server-pulse' ),
						'wordpress'        => __( 'WordPress', 'server-pulse' ),
						'php'              => __( 'PHP', 'server-pulse' ),
						'mysql'            => __( 'MySQL / MariaDB', 'server-pulse' ),
						'theme'            => __( 'Theme', 'server-pulse' ),
						'activePlugins'    => __( 'Active plugins', 'server-pulse' ),
						'maxExecution'     => __( 'Max execution time', 'server-pulse' ),
						'uploadMax'        => __( 'Upload max filesize', 'server-pulse' ),
						'postMax'          => __( 'Post max size', 'server-pulse' ),
						'opcache'          => __( 'OPcache', 'server-pulse' ),
						'wpDebug'          => __( 'WP_DEBUG', 'server-pulse' ),
						'wpCron'           => __( 'WP-Cron', 'server-pulse' ),
						'uptime'           => __( 'Uptime', 'server-pulse' ),
					),
				),
			)
		);
	}

	/**
	 * Render the dashboard view.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'server-pulse' ) );
		}

		include SERVER_PULSE_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the settings view.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'server-pulse' ) );
		}

		$settings = Server_Pulse_Settings::all();
		$statuses = ( new Server_Pulse_Provider_Manager() )->statuses();

		include SERVER_PULSE_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the alerts view.
	 *
	 * @return void
	 */
	public function render_alerts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'server-pulse' ) );
		}

		$alerts   = server_pulse()->alerts;
		$settings = Server_Pulse_Settings::all();
		$history  = $alerts->recent( 50 );

		include SERVER_PULSE_DIR . 'admin/views/alerts.php';
	}

	/**
	 * Render the diagnostics advisor view.
	 *
	 * @return void
	 */
	public function render_advisor() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'server-pulse' ) );
		}

		$snapshot = server_pulse()->collector->get_snapshot( false );
		$summary  = isset( $snapshot['summary'] ) ? $snapshot['summary'] : array();
		$report   = server_pulse()->advisor->report( false, $summary );

		include SERVER_PULSE_DIR . 'admin/views/advisor.php';
	}

	/**
	 * Render the Pro traffic & logs view.
	 *
	 * @return void
	 */
	public function render_traffic() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'server-pulse' ) );
		}

		$is_pro = Server_Pulse_License::is_pro();

		$traffic = $is_pro ? Server_Pulse_Traffic_Analyzer::report() : array();
		$errors  = $is_pro ? Server_Pulse_Log_Analyzer::report() : array();
		$recs    = $is_pro ? Server_Pulse_Traffic_Report::recommendations( $traffic ) : array( 'rules' => array(), 'text' => '' );

		$wpe_available = $is_pro && (bool) Server_Pulse_Traffic_Analyzer::discover_log_files();

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=server_pulse_export_traffic' ), 'server_pulse_export_traffic' );

		include SERVER_PULSE_DIR . 'admin/views/traffic.php';
	}

	/**
	 * Export the traffic + error report as a standalone HTML download (Pro).
	 *
	 * @return void
	 */
	public function export_traffic() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'server-pulse' ) );
		}

		check_admin_referer( 'server_pulse_export_traffic' );

		if ( ! Server_Pulse_License::is_pro() ) {
			wp_die( esc_html__( 'This is a Pro feature.', 'server-pulse' ) );
		}

		$traffic = Server_Pulse_Traffic_Analyzer::report();
		$errors  = Server_Pulse_Log_Analyzer::report();

		if ( ! $traffic ) {
			wp_die( esc_html__( 'Run an analysis first.', 'server-pulse' ) );
		}

		$html = Server_Pulse_Traffic_Report::html( $traffic, $errors );
		$file = 'server-pulse-traffic-' . gmdate( 'Ymd-His' ) . '.html';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the report builder.
		exit;
	}

	/**
	 * Reschedule cron when settings change.
	 *
	 * @return void
	 */
	public function reschedule_cron() {
		$raw      = get_option( Server_Pulse_Settings::OPTION, array() );
		$interval = isset( $raw['sample_interval'] ) ? $raw['sample_interval'] : 'hourly';
		$allowed  = array( 'hourly', 'twicedaily', 'daily' );

		if ( ! in_array( $interval, $allowed, true ) ) {
			$interval = 'hourly';
		}

		wp_clear_scheduled_hook( 'server_pulse_sample_event' );
		wp_schedule_event( time() + 60, $interval, 'server_pulse_sample_event' );

		if ( class_exists( 'Server_Pulse_Cron' ) ) {
			Server_Pulse_Cron::schedule_events();
		}

		if ( ! empty( $raw['enable_storage_scan'] ) ) {
			if ( ! wp_next_scheduled( Server_Pulse_Storage_Scanner::EVENT ) ) {
				wp_schedule_event( time() + 600, 'daily', Server_Pulse_Storage_Scanner::EVENT );
			}
		} else {
			wp_clear_scheduled_hook( Server_Pulse_Storage_Scanner::EVENT );
		}

		// Force a fresh collection after settings change.
		delete_transient( Server_Pulse_Collector::CACHE_KEY );
		delete_transient( Server_Pulse_WpEngine_Provider::CACHE_KEY );
	}

	/**
	 * Install or remove the early loader when its setting changes.
	 *
	 * @return void
	 */
	public function sync_loader() {
		if ( ! class_exists( 'Server_Pulse_Loader' ) ) {
			return;
		}

		$raw     = get_option( Server_Pulse_Settings::OPTION, array() );
		$enabled = is_array( $raw ) && ! empty( $raw['sentinel_loader'] );

		$result = Server_Pulse_Loader::sync( $enabled );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'server_pulse_settings_group',
				'server_pulse_loader',
				sprintf(
					/* translators: %s: error message. */
					__( 'The early crash loader could not be updated: %s', 'server-pulse' ),
					$result->get_error_message()
				),
				'error'
			);
		}
	}
}
