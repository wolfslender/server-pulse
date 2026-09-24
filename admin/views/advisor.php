<?php
/**
 * Diagnostics advisor view.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap sp-wrap">
	<div class="sp-header">
		<div class="sp-brand">
			<span class="dashicons dashicons-search"></span>
			<div>
				<h1><?php esc_html_e( 'Server Pulse Diagnostics', 'server-pulse' ); ?></h1>
				<p class="sp-tagline"><?php esc_html_e( 'What is wrong, why it matters, and how to fix it — without WP_DEBUG.', 'server-pulse' ); ?></p>
			</div>
		</div>
		<div class="sp-header-actions">
			<span id="sp-advisor-updated" class="sp-updated"></span>
			<button type="button" class="button button-primary" id="sp-advisor-run"><?php esc_html_e( 'Run diagnostics', 'server-pulse' ); ?></button>
		</div>
	</div>

	<div class="sp-advisor-summary" id="sp-advisor-summary">
		<button type="button" class="sp-sev is-critical sp-filter" data-severity="critical">
			<strong id="sp-advisor-critical">0</strong> <?php esc_html_e( 'critical', 'server-pulse' ); ?>
		</button>
		<button type="button" class="sp-sev is-warning sp-filter" data-severity="warning">
			<strong id="sp-advisor-warning">0</strong> <?php esc_html_e( 'warnings', 'server-pulse' ); ?>
		</button>
		<button type="button" class="sp-sev is-info sp-filter" data-severity="info">
			<strong id="sp-advisor-info">0</strong> <?php esc_html_e( 'info', 'server-pulse' ); ?>
		</button>
		<span class="sp-sev is-good" id="sp-advisor-host-wrap"><strong id="sp-advisor-host">—</strong></span>
	</div>

	<div class="sp-advisor-cats" id="sp-advisor-cats"></div>

	<div id="sp-advisor">
		<p class="sp-empty"><?php esc_html_e( 'Running diagnostics…', 'server-pulse' ); ?></p>
	</div>
</div>
