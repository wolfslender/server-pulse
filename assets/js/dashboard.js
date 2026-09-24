/**
 * Server Pulse dashboard logic (vanilla JS).
 */
(function () {
	'use strict';

	if (typeof window.serverPulse === 'undefined') {
		return;
	}

	var config = window.serverPulse;
	var i18n = config.i18n || {};
	var L = i18n.labels || {};
	var SEV = i18n.severities || {};
	var charts = {};
	var historyDays = 7;
	var autoTimer = null;
	var cycles = 0;

	var sourceLabels = {};
	(config.statuses || []).forEach(function (status) {
		sourceLabels[status.id] = status.label;
	});

	function $(id) {
		return document.getElementById(id);
	}

	function setText(id, value) {
		var node = $(id);
		if (node) {
			node.textContent = value;
		}
	}

	function formatBytes(bytes) {
		bytes = Number(bytes) || 0;
		if (bytes <= 0) {
			return '0 B';
		}
		var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		var power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
		return (bytes / Math.pow(1024, power)).toFixed(power === 0 ? 0 : 2) + ' ' + units[power];
	}

	function formatNumber(value) {
		value = Number(value) || 0;
		return value.toLocaleString();
	}

	function formatPercent(value) {
		return (Math.round(Number(value) * 10) / 10) + '%';
	}

	function hasValue(value) {
		return value !== null && value !== undefined && value !== '';
	}

	function sourceFor(data, key) {
		var source = data.sources && data.sources[key];
		return source && sourceLabels[source] ? sourceLabels[source] : '';
	}

	function meterClass(value) {
		if (value >= 90) {
			return 'is-critical';
		}
		if (value >= 75) {
			return 'is-warning';
		}
		return 'is-good';
	}

	function setMeter(prefix, percent, options) {
		options = options || {};
		var fill = $('sp-fill-' + prefix);
		var valueNode = $('sp-value-' + prefix);
		var sourceNode = $('sp-source-' + prefix);
		var subNode = $('sp-sub-' + prefix);

		if (!hasValue(percent)) {
			if (fill) {
				fill.style.width = '0%';
				fill.className = 'sp-meter-fill';
			}
			if (valueNode) {
				valueNode.textContent = options.valueText || options.missing || config.i18n.notAvailable;
			}
			if (sourceNode) {
				sourceNode.textContent = sourceFor(options.data || {}, options.key || prefix) || '';
			}
			if (subNode) {
				subNode.textContent = options.sub || '';
			}
			return;
		}

		percent = Math.max(0, Math.min(100, Number(percent)));

		if (fill) {
			fill.style.width = percent + '%';
			fill.className = 'sp-meter-fill ' + meterClass(percent);
		}
		if (valueNode) {
			valueNode.textContent = formatPercent(percent);
		}
		if (sourceNode) {
			sourceNode.textContent = sourceFor(options.data || {}, options.key || prefix) || '';
		}
		if (subNode) {
			subNode.textContent = options.sub || '';
		}
	}

	function renderHealth(health) {
		var score = health && hasValue(health.score) ? Number(health.score) : 0;
		var ring = $('sp-ring-value');
		var circumference = 2 * Math.PI * 52;

		if (ring) {
			ring.style.strokeDasharray = circumference;
			ring.style.strokeDashoffset = circumference * (1 - score / 100);
			ring.setAttribute('class', 'sp-ring-value is-' + (health ? health.grade : 'warning'));
		}

		setText('sp-health-score', Math.round(score));
		setText('sp-health-grade', health ? health.grade : '—');
	}

	function renderSummary(data) {
		var summary = data.summary || {};

		// CPU.
		var cpuPercent = hasValue(summary.cpu_percent) ? summary.cpu_percent : null;
		var cpuSub = '';
		if (hasValue(summary.cpu_cores)) {
			cpuSub = summary.cpu_cores + ' ' + i18n.cores;
			if (summary.cpu_load) {
				cpuSub += ' · ' + i18n.load + ' ' + summary.cpu_load.one;
			}
		}
		setMeter('cpu', cpuPercent, { data: data, sub: cpuSub });

		// Memory.
		var memPercent = hasValue(summary.memory_percent) ? summary.memory_percent : null;
		var memKey = 'memory_percent';
		var memSub = '';
		if (hasValue(summary.memory_total) && hasValue(summary.memory_used)) {
			memSub = formatBytes(summary.memory_used) + ' / ' + formatBytes(summary.memory_total);
			memPercent = summary.memory_percent;
			memKey = 'memory_percent';
		} else if (hasValue(summary.php_memory_percent)) {
			memSub = 'PHP ' + formatBytes(summary.php_memory_used) + ' / ' + formatBytes(summary.php_memory_limit);
			memPercent = summary.php_memory_percent;
			memKey = 'php_memory_percent';
		}
		setMeter('memory', memPercent, { data: data, key: memKey, sub: memSub });

		// Disk.
		var diskPercent = hasValue(summary.disk_percent) ? summary.disk_percent : null;
		var diskSub = '';
		var diskValueText = '';

		if (hasValue(summary.disk_used)) {
			diskSub = formatBytes(summary.disk_used);
			if (hasValue(summary.disk_total)) {
				diskSub += ' / ' + formatBytes(summary.disk_total);
			}
			if (hasValue(summary.disk_files) || hasValue(summary.disk_database)) {
				diskSub += ' · ' + i18n.files + ' ' + formatBytes(summary.disk_files) + ', ' + i18n.db + ' ' + formatBytes(summary.disk_database);
			}
		} else if (hasValue(summary.disk_free)) {
			diskSub = formatBytes(summary.disk_free) + ' ' + i18n.free;
		} else if (hasValue(summary.storage_scan_total)) {
			diskValueText = formatBytes(summary.storage_scan_total);
			diskSub = i18n.scannedWpContent;
			if (hasValue(summary.storage_scan_uploads)) {
				diskSub += ' · ' + L.uploads.toLowerCase() + ' ' + formatBytes(summary.storage_scan_uploads);
			}
			if (hasValue(summary.storage_scan_plugins)) {
				diskSub += ' · ' + L.plugins.toLowerCase() + ' ' + formatBytes(summary.storage_scan_plugins);
			}
			if (hasValue(summary.storage_scan_themes)) {
				diskSub += ' · ' + L.themes.toLowerCase() + ' ' + formatBytes(summary.storage_scan_themes);
			}
		}

		setMeter('disk', diskPercent, {
			data: data,
			sub: diskSub,
			valueText: diskValueText,
			missing: hasValue(summary.disk_used) ? formatBytes(summary.disk_used) : config.i18n.notAvailable
		});

		// Traffic.
		var trafficSource = $('sp-source-traffic');
		if (trafficSource) {
			trafficSource.textContent = sourceFor(data, 'visit_count');
		}
		setText('sp-traffic-visits', hasValue(summary.visit_count) ? formatNumber(summary.visit_count) : '—');
		setText('sp-traffic-requests', hasValue(summary.request_count) ? formatNumber(summary.request_count) : '—');
		setText('sp-traffic-bandwidth', hasValue(summary.bandwidth_total) ? formatBytes(summary.bandwidth_total) : '—');
		var trafficSub = $('sp-sub-traffic');
		if (trafficSub) {
			trafficSub.textContent = hasValue(summary.bandwidth_cdn) ? i18n.cdn + ' ' + formatBytes(summary.bandwidth_cdn) : '';
		}
	}

	function row(label, value) {
		if (!hasValue(value)) {
			return '';
		}
		return '<tr><td>' + escapeHtml(label) + '</td><td><strong>' + escapeHtml(value) + '</strong></td></tr>';
	}

	function renderWordPress(summary) {
		var html = '';
		html += row(L.posts, hasValue(summary.posts) ? formatNumber(summary.posts) : '');
		html += row(L.pages, hasValue(summary.pages) ? formatNumber(summary.pages) : '');
		html += row(L.comments, hasValue(summary.comments) ? formatNumber(summary.comments) : '');
		html += row(L.users, hasValue(summary.users) ? formatNumber(summary.users) : '');
		html += row(L.dbSize, hasValue(summary.db_size) ? formatBytes(summary.db_size) : '');
		html += row(L.dbTables, hasValue(summary.db_tables) ? formatNumber(summary.db_tables) : '');
		html += row(L.dbAutoload, hasValue(summary.db_autoload) ? formatBytes(summary.db_autoload) : '');
		html += row(L.revisions, hasValue(summary.db_revisions) ? formatNumber(summary.db_revisions) + ' (' + formatBytes(summary.db_revisions_size) + ')' : '');
		html += row(L.transients, hasValue(summary.db_transients) ? formatNumber(summary.db_transients) : '');
		html += row(L.cronOverdue, hasValue(summary.cron_overdue) ? formatNumber(summary.cron_overdue) : '');
		html += row(L.objectCache, hasValue(summary.object_cache) ? (summary.object_cache ? i18n.enabled : i18n.disabled) : '');
		html += row(L.storageScan, hasValue(summary.storage_scan_total) ? formatBytes(summary.storage_scan_total) : '');
		html += row(L.uploads, hasValue(summary.storage_scan_uploads) ? formatBytes(summary.storage_scan_uploads) : '');
		html += row(L.plugins, hasValue(summary.storage_scan_plugins) ? formatBytes(summary.storage_scan_plugins) : '');
		html += row(L.themes, hasValue(summary.storage_scan_themes) ? formatBytes(summary.storage_scan_themes) : '');

		var body = $('sp-wordpress-body');
		if (body) {
			body.innerHTML = html || '<tr><td colspan="2">' + escapeHtml(i18n.noData) + '</td></tr>';
		}
	}

	function renderEnvironment(summary) {
		var html = '';
		html += row(L.wordpress, summary.wp_version);
		html += row(L.php, summary.php_version);
		html += row(L.mysql, summary.mysql_version);
		html += row(L.theme, summary.theme);
		html += row(L.activePlugins, hasValue(summary.plugins_active) ? summary.plugins_active + ' / ' + summary.plugins_total : '');
		html += row(L.maxExecution, hasValue(summary.max_execution) ? summary.max_execution + 's' : '');
		html += row(L.uploadMax, hasValue(summary.upload_max) ? formatBytes(summary.upload_max) : '');
		html += row(L.postMax, hasValue(summary.post_max) ? formatBytes(summary.post_max) : '');
		html += row(L.opcache, hasValue(summary.opcache) ? (summary.opcache ? i18n.enabled : i18n.disabled) : '');
		html += row(L.wpDebug, hasValue(summary.wp_debug) ? (summary.wp_debug ? i18n.on : i18n.off) : '');
		html += row(L.wpCron, hasValue(summary.wp_cron_disabled) ? (summary.wp_cron_disabled ? i18n.disabled : i18n.enabled) : '');
		html += row(L.uptime, hasValue(summary.uptime) ? formatUptime(summary.uptime) : '');

		var body = $('sp-environment-body');
		if (body) {
			body.innerHTML = html || '<tr><td colspan="2">' + escapeHtml(i18n.noData) + '</td></tr>';
		}
	}

	function formatUptime(seconds) {
		seconds = Number(seconds) || 0;
		var days = Math.floor(seconds / 86400);
		var hours = Math.floor((seconds % 86400) / 3600);
		var minutes = Math.floor((seconds % 3600) / 60);
		if (days > 0) {
			return days + 'd ' + hours + 'h';
		}
		if (hours > 0) {
			return hours + 'h ' + minutes + 'm';
		}
		return minutes + 'm';
	}

	function renderProcesses(summary) {
		var body = $('sp-processes-body');
		if (!body) {
			return;
		}

		if (!Array.isArray(summary.processes) || !summary.processes.length) {
			body.innerHTML = '<tr><td colspan="5">' + escapeHtml(i18n.noProcessData) + '</td></tr>';
			return;
		}

		body.innerHTML = summary.processes
			.map(function (proc) {
				return (
					'<tr><td>' +
					escapeHtml(proc.command) +
					'</td><td>' +
					escapeHtml(proc.user) +
					'</td><td>' +
					escapeHtml(proc.pid) +
					'</td><td>' +
					escapeHtml(proc.cpu) +
					'</td><td>' +
					escapeHtml(proc.memory) +
					'</td></tr>'
				);
			})
			.join('');
	}

	function renderAlerts(health) {
		var container = $('sp-alerts');
		if (!container) {
			return;
		}

		var alerts = (health && health.alerts) || [];
		if (!alerts.length) {
			container.innerHTML = '<p class="sp-empty">' + escapeHtml(i18n.noAlerts) + '</p>';
			return;
		}

		container.innerHTML = alerts
			.map(function (alert) {
				return '<div class="sp-alert is-' + escapeHtml(alert.severity) + '"><span class="sp-alert-dot"></span>' + escapeHtml(alert.message) + '</div>';
			})
			.join('');
	}

	function renderAlertHistory(alerts) {
		var body = $('sp-alert-history-body');
		if (!body) {
			return;
		}

		if (!Array.isArray(alerts) || !alerts.length) {
			body.innerHTML = '<tr><td colspan="5">' + escapeHtml(i18n.noAlertsRecorded) + '</td></tr>';
			return;
		}

		body.innerHTML = alerts
			.map(function (alert) {
				var status = alert.status === 'active' ? i18n.statusActive : i18n.statusResolved;
				return (
					'<tr><td>' +
					escapeHtml(alert.created_at) +
					'</td><td>' +
					escapeHtml(alert.label) +
					'</td><td><span class="sp-sev is-' +
					escapeHtml(alert.severity) +
					'">' +
					escapeHtml(SEV[alert.severity] || alert.severity) +
					'</span></td><td>' +
					escapeHtml(status) +
					'</td><td>' +
					escapeHtml(alert.message) +
					'</td></tr>'
				);
			})
			.join('');
	}

	function fetchAlerts() {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_get_alerts');
		params.append('nonce', config.nonce);
		params.append('limit', 20);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success) {
					renderAlertHistory(payload.data.alerts || []);
				}
			})
			.catch(function () {});
	}

	function renderTrends(data) {
		var windowNode = $('sp-trends-window');
		if (windowNode && data.window) {
			windowNode.textContent = data.window + 'd';
		}

		var baselines = data.baseline || {};
		['cpu_percent', 'memory_percent', 'disk_percent', 'php_memory_percent'].forEach(function (metric) {
			var valueNode = $('sp-trend-value-' + metric);
			var avgNode = $('sp-trend-avg-' + metric);
			var card = document.querySelector('.sp-trend[data-trend="' + metric + '"]');
			var b = baselines[metric];

			if (!valueNode) {
				return;
			}

			if (!b) {
				valueNode.textContent = config.i18n.notAvailable;
				valueNode.className = 'sp-trend-value';
				if (avgNode) {
					avgNode.textContent = '';
				}
				return;
			}

			var current = Number(b.current) || 0;
			var average = Number(b.average) || 0;
			var deviation = Number(b.deviation) || 0;
			var threshold = (config.trends && config.trends.deviation) || 15;
			var deviating = Math.abs(deviation) >= threshold;

			valueNode.textContent = formatPercent(current);
			valueNode.className = 'sp-trend-value ' + (deviating ? (deviation > 0 ? 'is-high' : 'is-low') : 'is-ok');
			if (avgNode) {
				avgNode.textContent = i18n.avg.replace('%s', formatPercent(average));
			}
			if (card) {
				card.title = i18n.deviation.replace('%s', (deviation >= 0 ? '+' : '') + deviation.toFixed(1));
			}
		});

		var detail = $('sp-trends-detail');
		if (!detail) {
			return;
		}

		var proj = data.projections || {};
		var lines = [];

		if (proj.disk && proj.disk.capacity > 0) {
			if (proj.disk.days_left !== null && proj.disk.days_left !== undefined) {
				lines.push(config.i18n.diskDaysLeft.replace('%d', proj.disk.days_left));
			} else {
				lines.push(config.i18n.diskStable);
			}
			if (proj.disk.rate_per_day > 0) {
				lines.push(config.i18n.diskGrowth.replace('%s', formatBytes(proj.disk.rate_per_day)));
			}
		}
		if (proj.database && proj.database.rate_per_day > 0) {
			lines.push(config.i18n.dbGrowth.replace('%s', formatBytes(proj.database.rate_per_day)));
		}
		if (proj.bandwidth && proj.bandwidth.limit > 0 && proj.bandwidth.percent !== null && proj.bandwidth.percent !== undefined) {
			lines.push(
				config.i18n.bandwidthProjected
					.replace('%d', Math.round(proj.bandwidth.percent))
					.replace(/%%/g, '%')
			);
		}

		detail.innerHTML = lines.length
			? lines
					.map(function (line) {
						return '<span class="sp-trend-line">' + escapeHtml(line) + '</span>';
					})
					.join('')
			: '';
	}

	function fetchTrends() {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_get_trends');
		params.append('nonce', config.nonce);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success) {
					renderTrends(payload.data);
				}
			})
			.catch(function () {});
	}

	function renderNotes(data) {
		var container = $('sp-notes');
		if (!container) {
			return;
		}

		var notes = [];
		var settingsUrl = 'tools.php?page=server-pulse&tab=settings';

		(config.statuses || []).forEach(function (status) {
			var provider = data.providers ? data.providers[status.id] : null;

			if (provider && provider.notes && provider.notes.length) {
				provider.notes.forEach(function (note) {
					notes.push('<p><strong>' + escapeHtml(provider.label || status.id) + ':</strong> ' + escapeHtml(note) + '</p>');
				});
				return;
			}

			if (status.detected && !status.available) {
				notes.push(
					'<p><strong>' +
						escapeHtml(status.label) +
						':</strong> ' +
						escapeHtml(i18n.detectedNotConfigured) +
						' <a href="' +
						settingsUrl +
						'">' +
						escapeHtml(i18n.openSettings) +
						'</a></p>'
				);
			}
		});

		container.innerHTML = notes.join('');
		container.style.display = notes.length ? 'block' : 'none';
	}

	function renderProviders(data) {
		Object.keys(data.providers || {}).forEach(function (id) {
			var pill = document.querySelector('.sp-pill[data-provider="' + id + '"]');
			if (!pill) {
				return;
			}
			pill.classList.toggle('is-on', !!data.providers[id].available);
			pill.classList.toggle('is-off', !data.providers[id].available);
		});
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function render(data) {
		renderProviders(data);
		renderHealth(data.health);
		renderSummary(data);
		renderWordPress(data.summary || {});
		renderEnvironment(data.summary || {});
		renderProcesses(data.summary || {});
		renderAlerts(data.health);
		renderNotes(data);
	}

	function fetchSnapshot(force) {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_get_snapshot');
		params.append('nonce', config.nonce);
		params.append('force', force ? 'true' : 'false');

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success) {
					render(payload.data);
					setText('sp-updated', new Date().toLocaleTimeString());
					return payload.data;
				}
				throw new Error((payload && payload.data && payload.data.message) || config.i18n.error);
			})
			.catch(function (error) {
				setText('sp-updated', error.message || config.i18n.error);
			});
	}

	function initCharts() {
		var containers = document.querySelectorAll('.sp-chart[data-metric]');
		Array.prototype.forEach.call(containers, function (container) {
			var metric = container.getAttribute('data-metric');
			charts[metric] = new window.ServerPulseChart(container, {
				label: container.getAttribute('data-label') || metric,
				unit: container.getAttribute('data-unit') || '',
				emptyLabel: i18n.noData
			});
		});
	}

	function fetchHistory() {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_get_history');
		params.append('nonce', config.nonce);
		params.append('days', historyDays);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					return;
				}
				var series = payload.data.series || {};
				Object.keys(charts).forEach(function (metric) {
					charts[metric].setData(series[metric] || []);
				});
			})
			.catch(function () {});
	}

	function sampleNow() {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_sample_now');
		params.append('nonce', config.nonce);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		}).then(function () {
			fetchHistory();
			fetchAlerts();
			fetchTrends();
		});
	}

	function scanStorage() {
		var button = $('sp-scan');
		if (button) {
			button.disabled = true;
		}
		setText('sp-updated', config.i18n.scanning);

		var params = new URLSearchParams();
		params.append('action', 'server_pulse_scan_storage');
		params.append('nonce', config.nonce);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (button) {
					button.disabled = false;
				}
				if (payload && payload.success) {
					setText('sp-updated', payload.data.message);
				} else {
					setText('sp-updated', (payload && payload.data && payload.data.message) || config.i18n.error);
				}
				return fetchSnapshot(true);
			})
			.catch(function () {
				if (button) {
					button.disabled = false;
				}
				setText('sp-updated', config.i18n.error);
			});
	}

	function tick() {
		fetchSnapshot(false);
		cycles++;
		if (cycles % 4 === 1) {
			fetchHistory();
			fetchAlerts();
			fetchTrends();
		}
	}

	function startAuto() {
		stopAuto();
		autoTimer = window.setInterval(tick, Math.max(5, Number(config.refresh) || 15) * 1000);
	}

	function stopAuto() {
		if (autoTimer) {
			window.clearInterval(autoTimer);
			autoTimer = null;
		}
	}

	function bind() {
		var refresh = $('sp-refresh');
		if (refresh) {
			refresh.addEventListener('click', function () {
				fetchSnapshot(true);
				fetchHistory();
				fetchTrends();
			});
		}

		var sample = $('sp-sample');
		if (sample) {
			sample.addEventListener('click', sampleNow);
		}

		var scan = $('sp-scan');
		if (scan) {
			scan.addEventListener('click', scanStorage);
		}

		var auto = $('sp-auto');
		if (auto) {
			auto.checked = true;
			auto.addEventListener('change', function () {
				if (auto.checked) {
					startAuto();
				} else {
					stopAuto();
				}
			});
		}

		Array.prototype.forEach.call(document.querySelectorAll('.sp-range-btn'), function (button) {
			button.addEventListener('click', function () {
				historyDays = Number(button.getAttribute('data-days')) || 7;
				Array.prototype.forEach.call(document.querySelectorAll('.sp-range-btn'), function (other) {
					other.classList.toggle('is-active', other === button);
				});
				fetchHistory();
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initCharts();
		bind();
		fetchSnapshot(true);
		fetchHistory();
		fetchAlerts();
		fetchTrends();
		startAuto();
	});
})();
