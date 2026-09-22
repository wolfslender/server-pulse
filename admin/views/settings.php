<?php
/**
 * Settings view.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

// Settings and provider statuses are provided by Server_Pulse_Admin::render_settings().
$settings = isset( $settings ) ? $settings : Server_Pulse_Settings::all();
$statuses = isset( $statuses ) ? $statuses : array();
?>
<div class="wrap sp-wrap">
	<div class="sp-header">
		<div class="sp-brand">
			<span class="dashicons dashicons-performance"></span>
			<div>
				<h1><?php esc_html_e( 'Server Pulse Settings', 'server-pulse' ); ?></h1>
				<p class="sp-tagline"><?php esc_html_e( 'Configure data providers, sampling and thresholds.', 'server-pulse' ); ?></p>
			</div>
		</div>
	</div>

	<?php if ( ! Server_Pulse_Crypto::is_secure() ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'OpenSSL is not available on this server, so credentials are stored with a reversible encoding instead of real encryption. Keep your API tokens scoped and rotate them regularly.', 'server-pulse' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'server_pulse_settings_group' ); ?>

		<div class="sp-card">
			<h2><?php esc_html_e( 'General', 'server-pulse' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sp-sample-interval"><?php esc_html_e( 'Sampling frequency', 'server-pulse' ); ?></label></th>
					<td>
						<select id="sp-sample-interval" name="server_pulse_settings[sample_interval]">
							<option value="hourly" <?php selected( $settings['sample_interval'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'server-pulse' ); ?></option>
							<option value="twicedaily" <?php selected( $settings['sample_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'server-pulse' ); ?></option>
							<option value="daily" <?php selected( $settings['sample_interval'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'server-pulse' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How often a background sample is stored for the history charts.', 'server-pulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-retention"><?php esc_html_e( 'Retention (days)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="1" max="365" id="sp-retention" name="server_pulse_settings[retention_days]" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-refresh"><?php esc_html_e( 'Dashboard refresh (seconds)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="5" max="300" id="sp-refresh" name="server_pulse_settings[dashboard_refresh]" value="<?php echo esc_attr( $settings['dashboard_refresh'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shell access', 'server-pulse' ); ?></th>
					<td>
						<label><input type="checkbox" name="server_pulse_settings[allow_shell]" value="1" <?php checked( $settings['allow_shell'], 1 ); ?> /> <?php esc_html_e( 'Allow read-only shell commands (top processes, df fallback)', 'server-pulse' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only enable this on servers you control. Many managed hosts disable shell_exec entirely.', 'server-pulse' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'Thresholds', 'server-pulse' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'CPU %', 'server-pulse' ); ?></th>
					<td><input type="number" min="1" max="100" name="server_pulse_settings[thresholds][cpu]" value="<?php echo esc_attr( $settings['thresholds']['cpu'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Memory %', 'server-pulse' ); ?></th>
					<td><input type="number" min="1" max="100" name="server_pulse_settings[thresholds][memory]" value="<?php echo esc_attr( $settings['thresholds']['memory'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Disk %', 'server-pulse' ); ?></th>
					<td><input type="number" min="1" max="100" name="server_pulse_settings[thresholds][disk]" value="<?php echo esc_attr( $settings['thresholds']['disk'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Autoload limit (MB)', 'server-pulse' ); ?></th>
					<td><input type="number" min="0.1" max="50" step="0.1" name="server_pulse_settings[thresholds][autoload]" value="<?php echo esc_attr( $settings['thresholds']['autoload'] ); ?>" class="small-text" /></td>
				</tr>
			</table>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'WordPress provider', 'server-pulse' ); ?></h2>
			<label><input type="checkbox" name="server_pulse_settings[enable_wordpress]" value="1" <?php checked( $settings['enable_wordpress'], 1 ); ?> /> <?php esc_html_e( 'Enable WordPress & database metrics (always available)', 'server-pulse' ); ?></label>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'Native server provider', 'server-pulse' ); ?></h2>
			<label><input type="checkbox" name="server_pulse_settings[enable_native]" value="1" <?php checked( $settings['enable_native'], 1 ); ?> /> <?php esc_html_e( 'Enable native OS metrics (CPU, RAM, disk, uptime)', 'server-pulse' ); ?></label>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'Local storage scan', 'server-pulse' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Estimates disk usage by scanning wp-content (uploads, plugins, themes). Useful when no hosting API is available. Runs daily and can be triggered from the dashboard.', 'server-pulse' ); ?></p>
			<label><input type="checkbox" name="server_pulse_settings[enable_storage_scan]" value="1" <?php checked( $settings['enable_storage_scan'], 1 ); ?> /> <?php esc_html_e( 'Enable the local storage scan', 'server-pulse' ); ?></label>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'WP Engine', 'server-pulse' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Generate API credentials at my.wpengine.com/api_access (account owner). Save your changes first, then run the test.', 'server-pulse' ); ?></p>
			<label><input type="checkbox" name="server_pulse_settings[enable_wpengine]" value="1" <?php checked( $settings['enable_wpengine'], 1 ); ?> /> <?php esc_html_e( 'Enable WP Engine provider', 'server-pulse' ); ?></label>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sp-wpe-user"><?php esc_html_e( 'API User ID', 'server-pulse' ); ?></label></th>
					<td><input type="text" id="sp-wpe-user" name="server_pulse_settings[wpengine_api_user]" value="<?php echo esc_attr( $settings['wpengine_api_user'] ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-wpe-pass"><?php esc_html_e( 'API Password', 'server-pulse' ); ?></label></th>
					<td>
						<input type="password" id="sp-wpe-pass" name="server_pulse_settings[wpengine_api_pass]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['wpengine_api_pass'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : ''; ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-wpe-account"><?php esc_html_e( 'Account ID (optional)', 'server-pulse' ); ?></label></th>
					<td>
						<input type="text" id="sp-wpe-account" name="server_pulse_settings[wpengine_account_id]" value="<?php echo esc_attr( $settings['wpengine_account_id'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Leave empty to auto-detect from the first account returned by the API.', 'server-pulse' ); ?></p>
					</td>
				</tr>
			</table>
			<button type="button" class="button sp-test" data-provider="wpengine"><?php esc_html_e( 'Test connection', 'server-pulse' ); ?></button>
			<span class="sp-test-result" id="sp-test-wpengine"></span>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'cPanel', 'server-pulse' ); ?></h2>
			<label><input type="checkbox" name="server_pulse_settings[enable_cpanel]" value="1" <?php checked( $settings['enable_cpanel'], 1 ); ?> /> <?php esc_html_e( 'Enable cPanel provider', 'server-pulse' ); ?></label>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sp-cpanel-host"><?php esc_html_e( 'Host', 'server-pulse' ); ?></label></th>
					<td><input type="text" id="sp-cpanel-host" name="server_pulse_settings[cpanel_host]" value="<?php echo esc_attr( $settings['cpanel_host'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-cpanel-user"><?php esc_html_e( 'Username', 'server-pulse' ); ?></label></th>
					<td><input type="text" id="sp-cpanel-user" name="server_pulse_settings[cpanel_user]" value="<?php echo esc_attr( $settings['cpanel_user'] ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-cpanel-token"><?php esc_html_e( 'API token', 'server-pulse' ); ?></label></th>
					<td><input type="password" id="sp-cpanel-token" name="server_pulse_settings[cpanel_token]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['cpanel_token'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : ''; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-cpanel-port"><?php esc_html_e( 'Port', 'server-pulse' ); ?></label></th>
					<td><input type="number" id="sp-cpanel-port" name="server_pulse_settings[cpanel_port]" value="<?php echo esc_attr( $settings['cpanel_port'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Use SSL', 'server-pulse' ); ?></th>
					<td><label><input type="checkbox" name="server_pulse_settings[cpanel_ssl]" value="1" <?php checked( $settings['cpanel_ssl'], 1 ); ?> /> <?php esc_html_e( 'Connect over HTTPS', 'server-pulse' ); ?></label></td>
				</tr>
			</table>
			<button type="button" class="button sp-test" data-provider="cpanel"><?php esc_html_e( 'Test connection', 'server-pulse' ); ?></button>
			<span class="sp-test-result" id="sp-test-cpanel"></span>
		</div>

		<?php submit_button(); ?>
	</form>
</div>
