<?php
/**
 * Alerts log view.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

// Values are provided by Server_Pulse_Admin::render_alerts().
$history  = isset( $history ) ? $history : array();
$settings = isset( $settings ) ? $settings : Server_Pulse_Settings::all();
$alerts_config = $settings['alerts'];
$active = 0;

foreach ( $history as $row ) {
	if ( 'active' === $row['status'] ) {
		$active++;
	}
}
?>
<div class="wrap sp-wrap">
	<div class="sp-header">
		<div class="sp-brand">
			<span class="dashicons dashicons-warning"></span>
			<div>
				<h1><?php esc_html_e( 'Alerts', 'server-pulse' ); ?></h1>
				<p class="sp-tagline"><?php esc_html_e( 'Alert history, delivery status and channel configuration.', 'server-pulse' ); ?></p>
			</div>
		</div>
		<div class="sp-header-actions">
			<button type="button" class="button button-primary sp-test-alert"><?php esc_html_e( 'Send test alert', 'server-pulse' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-settings' ) ); ?>"><?php esc_html_e( 'Configure alerts', 'server-pulse' ); ?></a>
		</div>
	</div>

	<div class="sp-test-result" id="sp-test-alert-result"></div>

	<?php if ( empty( $alerts_config['enabled'] ) ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'The alert engine is currently disabled in settings, so no new alerts will be recorded or sent.', 'server-pulse' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="sp-top-grid">
		<div class="sp-card">
			<h2><?php esc_html_e( 'Active alerts', 'server-pulse' ); ?></h2>
			<div class="sp-metric-value"><span><?php echo esc_html( (string) $active ); ?></span></div>
		</div>
		<div class="sp-card">
			<h2><?php esc_html_e( 'Plan', 'server-pulse' ); ?></h2>
			<div class="sp-metric-value"><span><?php echo esc_html( Server_Pulse_License::plan_label() ); ?></span></div>
			<p class="sp-metric-sub">
				<?php
				if ( Server_Pulse_License::is_pro() ) {
					esc_html_e( 'All channels available.', 'server-pulse' );
				} else {
					esc_html_e( 'Email alerts included. Webhook, Slack, Discord and Telegram require Pro.', 'server-pulse' );
				}
				?>
			</p>
		</div>
	</div>

	<div class="sp-card">
		<h2><?php esc_html_e( 'History', 'server-pulse' ); ?></h2>
		<table class="widefat striped sp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When (UTC)', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Rule', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Value', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Status', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Notifications', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Message', 'server-pulse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $history ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No alerts recorded yet.', 'server-pulse' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><span class="sp-sev is-<?php echo esc_attr( $row['severity'] ); ?>"><?php echo esc_html( $row['severity'] ); ?></span></td>
							<td><?php echo esc_html( $row['value'] ? number_format_i18n( $row['value'], 2 ) : '—' ); ?></td>
							<td><?php echo esc_html( 'active' === $row['status'] ? __( 'Active', 'server-pulse' ) : __( 'Resolved', 'server-pulse' ) ); ?></td>
							<td><?php echo esc_html( (string) $row['notified'] ); ?></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
