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

		<?php
		$sp_alerts = $settings['alerts'];
		$sp_pro    = Server_Pulse_License::is_pro();
		?>
		<div class="sp-card">
			<h2><?php esc_html_e( 'Alerts & notifications', 'server-pulse' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Server Pulse watches the thresholds above plus site availability, and notifies you when something crosses a limit. Alerts are de-duplicated so you are not flooded.', 'server-pulse' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Alert engine', 'server-pulse' ); ?></th>
					<td><label><input type="checkbox" name="server_pulse_settings[alerts][enabled]" value="1" <?php checked( $sp_alerts['enabled'], 1 ); ?> /> <?php esc_html_e( 'Enable alert evaluation', 'server-pulse' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Site availability', 'server-pulse' ); ?></th>
					<td><label><input type="checkbox" name="server_pulse_settings[alerts][uptime_enabled]" value="1" <?php checked( $sp_alerts['uptime_enabled'], 1 ); ?> /> <?php esc_html_e( 'Check the homepage every 5 minutes and alert if it goes down', 'server-pulse' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify on recovery', 'server-pulse' ); ?></th>
					<td><label><input type="checkbox" name="server_pulse_settings[alerts][notify_recovery]" value="1" <?php checked( $sp_alerts['notify_recovery'], 1 ); ?> /> <?php esc_html_e( 'Send a message when an alert returns to normal', 'server-pulse' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-cooldown"><?php esc_html_e( 'Reminder cooldown (hours)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="1" max="168" id="sp-cooldown" name="server_pulse_settings[alerts][cooldown_hours]" value="<?php echo esc_attr( $sp_alerts['cooldown_hours'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'How often to re-send a notification while an alert is still active.', 'server-pulse' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-cap"><?php esc_html_e( 'Daily notification cap', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="0" max="100" id="sp-cap" name="server_pulse_settings[alerts][daily_cap]" value="<?php echo esc_attr( $sp_alerts['daily_cap'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Maximum non-critical notifications per day. Set 0 for no limit. Critical alerts always go through.', 'server-pulse' ); ?></p></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Rules', 'server-pulse' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php
				$sp_rules = array(
					'cpu'          => __( 'CPU usage', 'server-pulse' ),
					'memory'       => __( 'Memory usage', 'server-pulse' ),
					'disk'         => __( 'Disk usage', 'server-pulse' ),
					'php_memory'   => __( 'PHP memory usage', 'server-pulse' ),
					'autoload'     => __( 'Autoloaded options', 'server-pulse' ),
					'cron'         => __( 'Overdue cron events', 'server-pulse' ),
					'object_cache' => __( 'Object cache recommendation', 'server-pulse' ),
					'cpu_trend'    => __( 'CPU trend (vs 7-day average)', 'server-pulse' ),
					'memory_trend' => __( 'Memory trend (vs 7-day average)', 'server-pulse' ),
					'disk_trend'   => __( 'Disk trend (vs 7-day average)', 'server-pulse' ),
					'php_memory_trend' => __( 'PHP memory trend (vs 7-day average)', 'server-pulse' ),
					'disk_projection'  => __( 'Disk capacity projection', 'server-pulse' ),
					'bandwidth_projection' => __( 'Bandwidth projection', 'server-pulse' ),
				);
				foreach ( $sp_rules as $sp_rule => $sp_rule_label ) :
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $sp_rule_label ); ?></th>
						<td><label><input type="checkbox" name="server_pulse_settings[alerts][rules][<?php echo esc_attr( $sp_rule ); ?>]" value="1" <?php checked( ! empty( $sp_alerts['rules'][ $sp_rule ] ), true ); ?> /> <?php esc_html_e( 'Notify', 'server-pulse' ); ?></label></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h3><?php esc_html_e( 'Trend thresholds', 'server-pulse' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sp-trend-deviation"><?php esc_html_e( 'Deviation sensitivity (points)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="5" max="60" id="sp-trend-deviation" name="server_pulse_settings[alerts][trend_deviation]" value="<?php echo esc_attr( $sp_alerts['trend_deviation'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'How many percentage points above its 7-day average a metric must be before a trend alert fires.', 'server-pulse' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-disk-days"><?php esc_html_e( 'Disk projection horizon (days)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="7" max="90" id="sp-disk-days" name="server_pulse_settings[alerts][disk_days_threshold]" value="<?php echo esc_attr( $sp_alerts['disk_days_threshold'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Alert when disk is projected to reach capacity within this many days.', 'server-pulse' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-bandwidth-pct"><?php esc_html_e( 'Bandwidth limit projection (%)', 'server-pulse' ); ?></label></th>
					<td><input type="number" min="60" max="100" id="sp-bandwidth-pct" name="server_pulse_settings[alerts][bandwidth_pct_threshold]" value="<?php echo esc_attr( $sp_alerts['bandwidth_pct_threshold'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Alert when the current rate puts the site above this percent of the monthly bandwidth limit.', 'server-pulse' ); ?></p></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Email', 'server-pulse' ); ?> <span class="sp-badge"><?php esc_html_e( 'Free', 'server-pulse' ); ?></span></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email alerts', 'server-pulse' ); ?></th>
					<td><label><input type="checkbox" name="server_pulse_settings[alerts][email_enabled]" value="1" <?php checked( $sp_alerts['email_enabled'], 1 ); ?> /> <?php esc_html_e( 'Send alerts by email', 'server-pulse' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-recipients"><?php esc_html_e( 'Recipients', 'server-pulse' ); ?></label></th>
					<td><input type="text" id="sp-recipients" name="server_pulse_settings[alerts][email_recipients]" value="<?php echo esc_attr( $sp_alerts['email_recipients'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma separated. Leave empty to use the site admin email.', 'server-pulse' ); ?></p></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Pro channels', 'server-pulse' ); ?> <span class="sp-badge <?php echo $sp_pro ? 'is-on' : ''; ?>"><?php echo esc_html( $sp_pro ? __( 'Unlocked', 'server-pulse' ) : __( 'Pro', 'server-pulse' ) ); ?></span></h3>
			<?php if ( ! $sp_pro ) : ?>
				<p class="description"><?php esc_html_e( 'Webhook, Slack, Discord and Telegram delivery are Pro features. The fields below are kept in place and activate automatically once Pro is unlocked.', 'server-pulse' ); ?></p>
			<?php endif; ?>
			<fieldset <?php echo $sp_pro ? '' : 'disabled'; ?>>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp-webhook"><?php esc_html_e( 'Generic webhook URL', 'server-pulse' ); ?></label></th>
						<td><input type="password" id="sp-webhook" name="server_pulse_settings[alerts][webhook_url]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $sp_alerts['webhook_url'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : 'https://'; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp-slack"><?php esc_html_e( 'Slack webhook URL', 'server-pulse' ); ?></label></th>
						<td><input type="password" id="sp-slack" name="server_pulse_settings[alerts][slack_webhook]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $sp_alerts['slack_webhook'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : 'https://hooks.slack.com/…'; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp-discord"><?php esc_html_e( 'Discord webhook URL', 'server-pulse' ); ?></label></th>
						<td><input type="password" id="sp-discord" name="server_pulse_settings[alerts][discord_webhook]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $sp_alerts['discord_webhook'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : 'https://discord.com/api/webhooks/…'; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp-telegram-token"><?php esc_html_e( 'Telegram bot token', 'server-pulse' ); ?></label></th>
						<td><input type="password" id="sp-telegram-token" name="server_pulse_settings[alerts][telegram_token]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $sp_alerts['telegram_token'] ? esc_attr__( '•••••••• (saved)', 'server-pulse' ) : ''; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp-telegram-chat"><?php esc_html_e( 'Telegram chat ID', 'server-pulse' ); ?></label></th>
						<td><input type="text" id="sp-telegram-chat" name="server_pulse_settings[alerts][telegram_chat]" value="<?php echo esc_attr( $sp_alerts['telegram_chat'] ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
				</table>
			</fieldset>

			<p>
				<button type="button" class="button sp-test-alert"><?php esc_html_e( 'Send test alert', 'server-pulse' ); ?></button>
				<span class="sp-test-result" id="sp-test-alert-result"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Save your changes before sending a test.', 'server-pulse' ); ?></p>
		</div>

		<?php submit_button(); ?>
	</form>
</div>
