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
	 * Dashboard page hook.
	 *
	 * @var string
	 */
	private $dashboard_hook = '';

	/**
	 * Settings page hook.
	 *
	 * @var string
	 */
	private $settings_hook = '';

	/**
	 * Alerts page hook.
	 *
	 * @var string
	 */
	private $alerts_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . SERVER_PULSE_BASENAME, array( $this, 'action_links' ) );
		add_action( 'update_option_' . Server_Pulse_Settings::OPTION, array( $this, 'reschedule_cron' ), 10, 0 );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->dashboard_hook = add_menu_page(
			__( 'Server Pulse', 'server-pulse' ),
			__( 'Server Pulse', 'server-pulse' ),
			'manage_options',
			'server-pulse',
			array( $this, 'render_dashboard' ),
			'dashicons-performance',
			90
		);

		$this->settings_hook = add_submenu_page(
			'server-pulse',
			__( 'Server Pulse Settings', 'server-pulse' ),
			__( 'Settings', 'server-pulse' ),
			'manage_options',
			'server-pulse-settings',
			array( $this, 'render_settings' )
		);

		$this->alerts_hook = add_submenu_page(
			'server-pulse',
			__( 'Server Pulse Alerts', 'server-pulse' ),
			__( 'Alerts', 'server-pulse' ),
			'manage_options',
			'server-pulse-alerts',
			array( $this, 'render_alerts' )
		);
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
		$url = admin_url( 'admin.php?page=server-pulse-settings' );

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
		if ( $hook !== $this->dashboard_hook && $hook !== $this->settings_hook && $hook !== $this->alerts_hook ) {
			return;
		}

		wp_enqueue_style(
			'server-pulse-admin',
			SERVER_PULSE_URL . 'assets/css/admin.css',
			array(),
			SERVER_PULSE_VERSION
		);

		if ( $hook === $this->settings_hook || $hook === $this->alerts_hook ) {
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

		if ( $hook !== $this->dashboard_hook ) {
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
					'loading'          => __( 'Collecting live metrics…', 'server-pulse' ),
					'error'            => __( 'Could not fetch metrics.', 'server-pulse' ),
					'notAvailable'     => __( 'Not available on this host', 'server-pulse' ),
					'justNow'          => __( 'just now', 'server-pulse' ),
					'updatedAgo'       => __( 'Updated %s ago', 'server-pulse' ),
					'confirmed'        => __( 'Connection successful.', 'server-pulse' ),
					'confirmPurge'     => __( 'Store a sample now?', 'server-pulse' ),
					'scanning'         => __( 'Scanning storage… this can take a few seconds.', 'server-pulse' ),
					'diskDaysLeft'     => __( '~%d days of disk left at the current pace', 'server-pulse' ),
					'diskStable'       => __( 'Disk usage is steady', 'server-pulse' ),
					'diskGrowth'       => __( 'Disk is growing %s/day', 'server-pulse' ),
					'dbGrowth'         => __( 'Database is growing %s/day', 'server-pulse' ),
					'bandwidthProjected' => __( 'Bandwidth projected at ~%d%% of the monthly limit', 'server-pulse' ),
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

		if ( ! empty( $raw['enable_storage_scan'] ) ) {
			if ( ! wp_next_scheduled( Server_Pulse_Storage_Scanner::EVENT ) ) {
				wp_schedule_event( time() + 600, 'daily', Server_Pulse_Storage_Scanner::EVENT );
			}
		} else {
			wp_clear_scheduled_hook( Server_Pulse_Storage_Scanner::EVENT );
		}

		if ( ! wp_next_scheduled( 'server_pulse_alert_event' ) ) {
			wp_schedule_event( time() + 300, 'server_pulse_five_minutes', 'server_pulse_alert_event' );
		}

		// Force a fresh collection after settings change.
		delete_transient( Server_Pulse_Collector::CACHE_KEY );
		delete_transient( Server_Pulse_WpEngine_Provider::CACHE_KEY );
	}
}
