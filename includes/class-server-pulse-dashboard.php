<?php
/**
 * WordPress dashboard widget.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a lightweight Server Pulse widget to the WordPress dashboard that
 * surfaces crash alerts and at-risk plugins.
 */
class Server_Pulse_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	/**
	 * Register the widget.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'server_pulse_dashboard', __( 'Server Pulse', 'server-pulse' ), array( $this, 'render' ) );
	}

	/**
	 * Render the widget. Reads cached data only.
	 *
	 * @return void
	 */
	public function render() {
		$crashes = Server_Pulse_Sentinel::crash_history();
		$report  = Server_Pulse_Advisor::cached_report();
		$risky   = Server_Pulse_Advisor::at_risk_plugins();

		$diagnostics = admin_url( 'admin.php?page=server-pulse-advisor' );
		$plugins_url = admin_url( 'plugins.php' );

		// 1. Crashes.
		if ( $crashes ) {
			echo '<div class="sp-dash-section">';
			echo '<h3 class="sp-dash-title"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Activation crashes', 'server-pulse' ) . '</h3>';

			foreach ( array_slice( $crashes, 0, 3 ) as $crash ) {
				$plugin = isset( $crash['plugin'] ) ? $crash['plugin'] : '';
				$rolled = ! empty( $crash['rolled_back'] );
				$short  = trim( str_replace( array( 'plugins/', 'mu-plugins/' ), '', $plugin ) );

				echo '<p class="sp-dash-row">';
				echo '<span class="sp-sev is-' . ( $rolled ? 'warning' : 'critical' ) . '">' . esc_html( $rolled ? __( 'rolled back', 'server-pulse' ) : __( 'needs action', 'server-pulse' ) ) . '</span> ';
				echo '<strong>' . esc_html( $short ) . '</strong><br />';
				echo '<span class="sp-dash-muted">' . esc_html( Server_Pulse_Sentinel::cause( $crash ) ) . '</span>';
				echo '</p>';
			}

			echo '<p><a class="button button-secondary" href="' . esc_url( $diagnostics ) . '">' . esc_html__( 'Review in diagnostics', 'server-pulse' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Open plugins', 'server-pulse' ) . '</a></p>';
			echo '</div>';
		}

		// 2. Advisor summary.
		echo '<div class="sp-dash-section">';
		echo '<h3 class="sp-dash-title"><span class="dashicons dashicons-search"></span> ' . esc_html__( 'Diagnostics', 'server-pulse' ) . '</h3>';

		if ( is_array( $report ) && isset( $report['counts'] ) ) {
			$counts = $report['counts'];
			echo '<p class="sp-dash-chips">';
			echo '<a class="sp-sev is-critical sp-filter-link" href="' . esc_url( add_query_arg( 'severity', 'critical', $diagnostics ) ) . '"><strong>' . (int) $counts['critical'] . '</strong> ' . esc_html__( 'critical', 'server-pulse' ) . '</a> ';
			echo '<a class="sp-sev is-warning sp-filter-link" href="' . esc_url( add_query_arg( 'severity', 'warning', $diagnostics ) ) . '"><strong>' . (int) $counts['warning'] . '</strong> ' . esc_html__( 'warnings', 'server-pulse' ) . '</a> ';
			echo '<a class="sp-sev is-info sp-filter-link" href="' . esc_url( add_query_arg( 'severity', 'info', $diagnostics ) ) . '"><strong>' . (int) $counts['info'] . '</strong> ' . esc_html__( 'info', 'server-pulse' ) . '</a>';
			echo '</p>';
		} else {
			echo '<p><a class="button button-primary" href="' . esc_url( $diagnostics ) . '">' . esc_html__( 'Run diagnostics', 'server-pulse' ) . '</a></p>';
		}

		if ( $risky ) {
			echo '<h4 class="sp-dash-sub">' . esc_html__( 'Plugins that may break the site', 'server-pulse' ) . '</h4>';
			echo '<ul class="sp-dash-list">';
			foreach ( array_slice( $risky, 0, 5 ) as $item ) {
				echo '<li><strong>' . esc_html( $item['name'] ) . '</strong> — ' . esc_html( $item['reason'] ) . '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';

		// 3. Footer.
		echo '<p class="sp-dash-footer"><a href="' . esc_url( admin_url( 'admin.php?page=server-pulse' ) ) . '">' . esc_html__( 'Open Server Pulse', 'server-pulse' ) . '</a></p>';
	}
}