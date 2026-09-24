<?php
/**
 * Dashboard view.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

$server_pulse_statuses = ( new Server_Pulse_Provider_Manager() )->statuses();
$server_pulse_advisor  = Server_Pulse_Advisor::cached_report();
$server_pulse_findings = is_array( $server_pulse_advisor )
	? array_values(
		array_filter(
			$server_pulse_advisor['findings'],
			static function ( $finding ) {
				return in_array( $finding['severity'], array( 'critical', 'warning' ), true );
			}
		)
	)
	: array();
?>
<div class="wrap sp-wrap">
	<div class="sp-header">
		<div class="sp-brand">
			<span class="dashicons dashicons-performance"></span>
			<div>
				<h1><?php esc_html_e( 'Server Pulse', 'server-pulse' ); ?></h1>
				<p class="sp-tagline"><?php esc_html_e( 'Real-time server and WordPress health', 'server-pulse' ); ?></p>
			</div>
		</div>
		<div class="sp-header-actions">
			<span id="sp-updated" class="sp-updated"><?php esc_html_e( 'Loading…', 'server-pulse' ); ?></span>
			<label class="sp-auto"><input type="checkbox" id="sp-auto" checked /> <?php esc_html_e( 'Auto refresh', 'server-pulse' ); ?></label>
			<button type="button" class="button" id="sp-refresh"><?php esc_html_e( 'Refresh', 'server-pulse' ); ?></button>
			<button type="button" class="button button-secondary" id="sp-sample"><?php esc_html_e( 'Store sample', 'server-pulse' ); ?></button>
			<button type="button" class="button button-secondary" id="sp-scan"><?php esc_html_e( 'Scan storage', 'server-pulse' ); ?></button>
		</div>
	</div>

	<div class="sp-provider-bar" id="sp-providers">
		<?php foreach ( $server_pulse_statuses as $server_pulse_status ) : ?>
			<span class="sp-pill <?php echo $server_pulse_status['available'] ? 'is-on' : 'is-off'; ?>" data-provider="<?php echo esc_attr( $server_pulse_status['id'] ); ?>">
				<span class="sp-dot"></span>
				<?php echo esc_html( $server_pulse_status['label'] ); ?>
				<?php if ( $server_pulse_status['detected'] ) : ?>
					<em><?php esc_html_e( 'detected', 'server-pulse' ); ?></em>
				<?php endif; ?>
			</span>
		<?php endforeach; ?>
	</div>

	<div class="sp-top-grid">
		<div class="sp-card sp-health-card">
			<h2><?php esc_html_e( 'Health score', 'server-pulse' ); ?></h2>
			<div class="sp-health-ring" id="sp-health-ring">
				<svg viewBox="0 0 120 120" width="140" height="140" aria-hidden="true">
					<circle class="sp-ring-track" cx="60" cy="60" r="52"></circle>
					<circle class="sp-ring-value" id="sp-ring-value" cx="60" cy="60" r="52"></circle>
				</svg>
				<div class="sp-ring-label">
					<strong id="sp-health-score">—</strong>
					<span id="sp-health-grade">—</span>
				</div>
			</div>
		</div>

		<div class="sp-card sp-metric-card" data-metric="cpu">
			<header>
				<h2><?php esc_html_e( 'CPU', 'server-pulse' ); ?></h2>
				<span class="sp-source" id="sp-source-cpu"></span>
			</header>
			<div class="sp-meter"><div class="sp-meter-fill" id="sp-fill-cpu"></div></div>
			<div class="sp-metric-value"><span id="sp-value-cpu">—</span></div>
			<p class="sp-metric-sub" id="sp-sub-cpu"></p>
		</div>

		<div class="sp-card sp-metric-card" data-metric="memory">
			<header>
				<h2><?php esc_html_e( 'Memory (RAM)', 'server-pulse' ); ?></h2>
				<span class="sp-source" id="sp-source-memory"></span>
			</header>
			<div class="sp-meter"><div class="sp-meter-fill" id="sp-fill-memory"></div></div>
			<div class="sp-metric-value"><span id="sp-value-memory">—</span></div>
			<p class="sp-metric-sub" id="sp-sub-memory"></p>
		</div>

		<div class="sp-card sp-metric-card" data-metric="disk">
			<header>
				<h2><?php esc_html_e( 'Disk / Storage', 'server-pulse' ); ?></h2>
				<span class="sp-source" id="sp-source-disk"></span>
			</header>
			<div class="sp-meter"><div class="sp-meter-fill" id="sp-fill-disk"></div></div>
			<div class="sp-metric-value"><span id="sp-value-disk">—</span></div>
			<p class="sp-metric-sub" id="sp-sub-disk"></p>
		</div>

		<div class="sp-card sp-metric-card" data-metric="traffic">
			<header>
				<h2><?php esc_html_e( 'Traffic', 'server-pulse' ); ?></h2>
				<span class="sp-source" id="sp-source-traffic"></span>
			</header>
			<div class="sp-traffic-grid">
				<div><span class="sp-traffic-label"><?php esc_html_e( 'Visits', 'server-pulse' ); ?></span><strong id="sp-traffic-visits">—</strong></div>
				<div><span class="sp-traffic-label"><?php esc_html_e( 'Requests', 'server-pulse' ); ?></span><strong id="sp-traffic-requests">—</strong></div>
				<div><span class="sp-traffic-label"><?php esc_html_e( 'Bandwidth', 'server-pulse' ); ?></span><strong id="sp-traffic-bandwidth">—</strong></div>
			</div>
			<p class="sp-metric-sub" id="sp-sub-traffic"></p>
		</div>
	</div>

	<div class="sp-card sp-trends-card">
		<header class="sp-chart-header">
			<h2><?php esc_html_e( 'Trends & projections', 'server-pulse' ); ?></h2>
			<span class="sp-trends-window" id="sp-trends-window"></span>
		</header>
		<div class="sp-trends-grid">
			<div class="sp-trend" data-trend="cpu_percent">
				<strong><?php esc_html_e( 'CPU', 'server-pulse' ); ?></strong>
				<span class="sp-trend-value" id="sp-trend-value-cpu_percent">—</span>
				<span class="sp-trend-avg" id="sp-trend-avg-cpu_percent"></span>
			</div>
			<div class="sp-trend" data-trend="memory_percent">
				<strong><?php esc_html_e( 'Memory', 'server-pulse' ); ?></strong>
				<span class="sp-trend-value" id="sp-trend-value-memory_percent">—</span>
				<span class="sp-trend-avg" id="sp-trend-avg-memory_percent"></span>
			</div>
			<div class="sp-trend" data-trend="disk_percent">
				<strong><?php esc_html_e( 'Disk', 'server-pulse' ); ?></strong>
				<span class="sp-trend-value" id="sp-trend-value-disk_percent">—</span>
				<span class="sp-trend-avg" id="sp-trend-avg-disk_percent"></span>
			</div>
			<div class="sp-trend" data-trend="php_memory_percent">
				<strong><?php esc_html_e( 'PHP memory', 'server-pulse' ); ?></strong>
				<span class="sp-trend-value" id="sp-trend-value-php_memory_percent">—</span>
				<span class="sp-trend-avg" id="sp-trend-avg-php_memory_percent"></span>
			</div>
		</div>
		<div class="sp-trends-detail" id="sp-trends-detail"></div>
	</div>

	<div class="sp-card sp-chart-card">
		<header class="sp-chart-header">
			<h2><?php esc_html_e( 'History', 'server-pulse' ); ?></h2>
			<div class="sp-range" role="tablist">
				<button type="button" class="sp-range-btn" data-days="1">24h</button>
				<button type="button" class="sp-range-btn is-active" data-days="7">7d</button>
				<button type="button" class="sp-range-btn" data-days="30">30d</button>
			</div>
		</header>
		<div class="sp-charts">
			<div class="sp-chart" data-metric="cpu_percent" data-label="<?php esc_attr_e( 'CPU %', 'server-pulse' ); ?>" data-unit="%"></div>
			<div class="sp-chart" data-metric="memory_percent" data-label="<?php esc_attr_e( 'Memory %', 'server-pulse' ); ?>" data-unit="%"></div>
			<div class="sp-chart" data-metric="disk_percent" data-label="<?php esc_attr_e( 'Disk %', 'server-pulse' ); ?>" data-unit="%"></div>
			<div class="sp-chart" data-metric="visit_count" data-label="<?php esc_attr_e( 'Visits', 'server-pulse' ); ?>" data-unit=""></div>
		</div>
	</div>

	<div class="sp-two-col">
		<div class="sp-card">
			<h2><?php esc_html_e( 'WordPress & database', 'server-pulse' ); ?></h2>
			<table class="widefat striped sp-table">
				<tbody id="sp-wordpress-body">
					<tr><td colspan="2"><?php esc_html_e( 'Loading…', 'server-pulse' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="sp-card">
			<h2><?php esc_html_e( 'Environment', 'server-pulse' ); ?></h2>
			<table class="widefat striped sp-table">
				<tbody id="sp-environment-body">
					<tr><td colspan="2"><?php esc_html_e( 'Loading…', 'server-pulse' ); ?></td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<div class="sp-card">
		<h2><?php esc_html_e( 'Top processes', 'server-pulse' ); ?></h2>
		<table class="widefat striped sp-table" id="sp-processes-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Process', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'User', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'PID', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'CPU %', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'MEM %', 'server-pulse' ); ?></th>
				</tr>
			</thead>
			<tbody id="sp-processes-body">
				<tr><td colspan="5"><?php esc_html_e( 'No process data available on this host.', 'server-pulse' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="sp-card sp-advisor-card">
		<header class="sp-chart-header">
			<h2><?php esc_html_e( 'Diagnostics', 'server-pulse' ); ?></h2>
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-advisor' ) ); ?>"><?php esc_html_e( 'Open diagnostics', 'server-pulse' ); ?></a>
		</header>
		<?php if ( is_array( $server_pulse_advisor ) ) : ?>
			<div class="sp-advisor-counts">
				<a class="sp-sev is-critical sp-filter-link" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-advisor&severity=critical' ) ); ?>"><strong><?php echo esc_html( $server_pulse_advisor['counts']['critical'] ); ?></strong> <?php esc_html_e( 'critical', 'server-pulse' ); ?></a>
				<a class="sp-sev is-warning sp-filter-link" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-advisor&severity=warning' ) ); ?>"><strong><?php echo esc_html( $server_pulse_advisor['counts']['warning'] ); ?></strong> <?php esc_html_e( 'warnings', 'server-pulse' ); ?></a>
				<a class="sp-sev is-info sp-filter-link" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-advisor&severity=info' ) ); ?>"><strong><?php echo esc_html( $server_pulse_advisor['counts']['info'] ); ?></strong> <?php esc_html_e( 'info', 'server-pulse' ); ?></a>
			</div>
			<?php if ( $server_pulse_findings ) : ?>
				<ul class="sp-advisor-list">
					<?php foreach ( array_slice( $server_pulse_findings, 0, 4 ) as $server_pulse_finding ) : ?>
						<li class="is-<?php echo esc_attr( $server_pulse_finding['severity'] ); ?>">
							<span class="sp-sev is-<?php echo esc_attr( $server_pulse_finding['severity'] ); ?>"><?php echo esc_html( ucfirst( $server_pulse_finding['severity'] ) ); ?></span>
							<?php echo esc_html( $server_pulse_finding['title'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sp-empty"><?php esc_html_e( 'No problems detected. Nice.', 'server-pulse' ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<p class="sp-empty"><?php esc_html_e( 'Diagnostics run in the background; results will appear here shortly.', 'server-pulse' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="sp-card sp-alerts-card">
		<header class="sp-chart-header">
			<h2><?php esc_html_e( 'Alerts', 'server-pulse' ); ?></h2>
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=server-pulse-alerts' ) ); ?>"><?php esc_html_e( 'Alert log', 'server-pulse' ); ?></a>
		</header>
		<div id="sp-alerts">
			<p class="sp-empty"><?php esc_html_e( 'No alerts. Everything looks healthy.', 'server-pulse' ); ?></p>
		</div>
	</div>

	<div class="sp-card">
		<h2><?php esc_html_e( 'Recent alert history', 'server-pulse' ); ?></h2>
		<table class="widefat striped sp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When (UTC)', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Rule', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Status', 'server-pulse' ); ?></th>
					<th><?php esc_html_e( 'Message', 'server-pulse' ); ?></th>
				</tr>
			</thead>
			<tbody id="sp-alert-history-body">
				<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'server-pulse' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="sp-card sp-notes" id="sp-notes"></div>
</div>
