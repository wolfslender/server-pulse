<?php
/**
 * Pro traffic and logs view.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

$sp_is_pro       = ! empty( $is_pro );
$sp_wpe_detected = ! empty( $wpe_available );
?>
<div class="wrap sp-wrap">
	<?php Server_Pulse_Admin::render_tabs( 'traffic' ); ?>

	<div class="sp-header">
		<div class="sp-brand">
			<span class="dashicons dashicons-chart-area"></span>
			<div>
				<h1><?php esc_html_e( 'Traffic & logs', 'server-pulse' ); ?></h1>
				<p class="sp-tagline"><?php esc_html_e( 'Find out what is hitting your site, which requests 504 and which missing files are burning PHP workers.', 'server-pulse' ); ?></p>
			</div>
		</div>
		<div class="sp-header-actions">
			<span class="sp-badge <?php echo $sp_is_pro ? 'is-on' : ''; ?>"><?php echo esc_html( $sp_is_pro ? __( 'Pro', 'server-pulse' ) : __( 'Free', 'server-pulse' ) ); ?></span>
		</div>
	</div>

	<?php if ( ! $sp_is_pro ) : ?>
		<div class="sp-card sp-locked">
			<h2><?php esc_html_e( 'Traffic & log analysis is a Pro feature', 'server-pulse' ); ?></h2>
			<p><?php esc_html_e( 'Server Pulse Pro reads your Apache/WP Engine logs and turns them into an action plan: 504 spikes, abusive clients, missing assets (favicon, passkey endpoint, ads.txt) and noisy PHP errors, with edge rules you can copy to WP Engine.', 'server-pulse' ); ?></p>
			<p class="description"><?php esc_html_e( 'Unlock it under Settings → Plan (development switch) or with your license.', 'server-pulse' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=server-pulse&tab=settings' ) ); ?>"><?php esc_html_e( 'Go to Settings', 'server-pulse' ); ?></a>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="sp-card">
		<h2><?php esc_html_e( 'Analyze', 'server-pulse' ); ?></h2>
		<p class="description">
			<?php if ( $sp_wpe_detected ) : ?>
				<?php esc_html_e( 'WP Engine logs were detected on this server. Analyze them directly, or upload an exported log file (they are read in memory and never stored).', 'server-pulse' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Upload a log export from the WP Engine User Portal (access or error log, .log or .log.gz). Files are read in memory and never stored.', 'server-pulse' ); ?>
			<?php endif; ?>
		</p>

		<div class="sp-traffic-controls">
			<?php if ( $sp_wpe_detected ) : ?>
				<button type="button" class="button button-primary" id="sp-traffic-wpe"><?php esc_html_e( 'Analyze WP Engine logs', 'server-pulse' ); ?></button>
			<?php endif; ?>

			<label class="button sp-file-btn">
				<input type="file" id="sp-traffic-file" accept=".log,.txt,.gz,application/gzip,text/plain" hidden />
				<?php esc_html_e( 'Choose log file', 'server-pulse' ); ?>
			</label>
			<span id="sp-traffic-filename" class="sp-muted"></span>
			<button type="button" class="button" id="sp-traffic-upload" disabled><?php esc_html_e( 'Upload & analyze', 'server-pulse' ); ?></button>

			<span class="sp-spacer"></span>

			<a class="button sp-export" id="sp-traffic-export" href="<?php echo esc_url( $export_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download report', 'server-pulse' ); ?></a>
			<button type="button" class="button" id="sp-traffic-clear"><?php esc_html_e( 'Clear', 'server-pulse' ); ?></button>
		</div>

		<p class="sp-updated" id="sp-traffic-status"></p>
	</div>

	<div id="sp-traffic-report">
		<p class="sp-empty"><?php esc_html_e( 'No report yet. Analyze the WP Engine logs or upload a log export.', 'server-pulse' ); ?></p>
	</div>
</div>
