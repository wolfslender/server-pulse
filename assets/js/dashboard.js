/**
 * Server Pulse dashboard logic (vanilla JS).
 */
(function () {
	'use strict';

	if (typeof window.serverPulse === 'undefined') {
		return;
	}

	var config = window.serverPulse;
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
			cpuSub = summary.cpu_cores + ' cores';
			if (summary.cpu_load) {
				cpuSub += ' · load ' + summary.cpu_load.one;
			}
		}
		setMeter('cpu', cpuPercent, { data: data, sub: cpuSub });

		// Memory.
		var memPercent = hasValue(summary.memory_percent) ? summary.memory_percent : null;
		var memSub = '';
		if (hasValue(summary.memory_total) && hasValue(summary.memory_used)) {
			memSub = formatBytes(summary.memory_used) + ' / ' + formatBytes(summary.memory_total);
			memPercent = summary.memory_percent;
		} else if (hasValue(summary.php_memory_percent)) {
			memSub = 'PHP ' + formatBytes(summary.php_memory_used) + ' / ' + formatBytes(summary.php_memory_limit);
			memPercent = summary.php_memory_percent;
		}
		setMeter('memory', memPercent, { data: data, key: 'memory_percent', sub: memSub });

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
				diskSub += ' · files ' + formatBytes(summary.disk_files) + ', DB ' + formatBytes(summary.disk_database);
			}
		} else if (hasValue(summary.disk_free)) {
			diskSub = formatBytes(summary.disk_free) + ' free';
		} else if (hasValue(summary.storage_scan_total)) {
			diskValueText = formatBytes(summary.storage_scan_total);
			diskSub = 'Scanned wp-content';
			if (hasValue(summary.storage_scan_uploads)) {
				diskSub += ' · uploads ' + formatBytes(summary.storage_scan_uploads);
			}
			if (hasValue(summary.storage_scan_plugins)) {
				diskSub += ' · plugins ' + formatBytes(summary.storage_scan_plugins);
			}
			if (hasValue(summary.storage_scan_themes)) {
				diskSub += ' · themes ' + formatBytes(summary.storage_scan_themes);
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
			trafficSub.textContent = hasValue(summary.bandwidth_cdn) ? 'CDN ' + formatBytes(summary.bandwidth_cdn) : '';
		}
	}

	function row(label, value) {
		if (!hasValue(value)) {
			return '';
		}
		return '<tr><td>' + label + '</td><td><strong>' + value + '</strong></td></tr>';
	}

	function renderWordPress(summary) {
		var html = '';
		html += row('Posts', hasValue(summary.posts) ? formatNumber(summary.posts) : '');
		html += row('Pages', hasValue(summary.pages) ? formatNumber(summary.pages) : '');
		html += row('Comments', hasValue(summary.comments) ? formatNumber(summary.comments) : '');
		html += row('Users', hasValue(summary.users) ? formatNumber(summary.users) : '');
		html += row('Database size', hasValue(summary.db_size) ? formatBytes(summary.db_size) : '');
		html += row('Database tables', hasValue(summary.db_tables) ? formatNumber(summary.db_tables) : '');
		html += row('Autoloaded options', hasValue(summary.db_autoload) ? formatBytes(summary.db_autoload) : '');
		html += row('Revisions', hasValue(summary.db_revisions) ? formatNumber(summary.db_revisions) + ' (' + formatBytes(summary.db_revisions_size) + ')' : '');
		html += row('Transients', hasValue(summary.db_transients) ? formatNumber(summary.db_transients) : '');
		html += row('Overdue cron events', hasValue(summary.cron_overdue) ? formatNumber(summary.cron_overdue) : '');
		html += row('Object cache', hasValue(summary.object_cache) ? (summary.object_cache ? 'Enabled' : 'Disabled') : '');
		html += row('Storage scan (wp-content)', hasValue(summary.storage_scan_total) ? formatBytes(summary.storage_scan_total) : '');
		html += row('Uploads', hasValue(summary.storage_scan_uploads) ? formatBytes(summary.storage_scan_uploads) : '');
		html += row('Plugins', hasValue(summary.storage_scan_plugins) ? formatBytes(summary.storage_scan_plugins) : '');
		html += row('Themes', hasValue(summary.storage_scan_themes) ? formatBytes(summary.storage_scan_themes) : '');

		var body = $('sp-wordpress-body');
		if (body) {
			body.innerHTML = html || '<tr><td colspan="2">No data.</td></tr>';
		}
	}

	function renderEnvironment(summary) {
		var html = '';
		html += row('WordPress', summary.wp_version);
		html += row('PHP', summary.php_version);
		html += row('MySQL / MariaDB', summary.mysql_version);
		html += row('Theme', summary.theme);
		html += row('Active plugins', hasValue(summary.plugins_active) ? summary.plugins_active + ' / ' + summary.plugins_total : '');
		html += row('Max execution time', hasValue(summary.max_execution) ? summary.max_execution + 's' : '');
		html += row('Upload max filesize', hasValue(summary.upload_max) ? formatBytes(summary.upload_max) : '');
		html += row('Post max size', hasValue(summary.post_max) ? formatBytes(summary.post_max) : '');
		html += row('OPcache', hasValue(summary.opcache) ? (summary.opcache ? 'Enabled' : 'Disabled') : '');
		html += row('WP_DEBUG', hasValue(summary.wp_debug) ? (summary.wp_debug ? 'On' : 'Off') : '');
		html += row('WP-Cron', hasValue(summary.wp_cron_disabled) ? (summary.wp_cron_disabled ? 'Disabled' : 'Enabled') : '');
		html += row('Uptime', hasValue(summary.uptime) ? formatUptime(summary.uptime) : '');

		var body = $('sp-environment-body');
		if (body) {
			body.innerHTML = html || '<tr><td colspan="2">No data.</td></tr>';
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
			body.innerHTML = '<tr><td colspan="5">No process data available on this host.</td></tr>';
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
					proc.pid +
					'</td><td>' +
					proc.cpu +
					'</td><td>' +
					proc.memory +
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
			container.innerHTML = '<p class="sp-empty">No alerts. Everything looks healthy.</p>';
			return;
		}

		container.innerHTML = alerts
			.map(function (alert) {
				return '<div class="sp-alert is-' + alert.severity + '"><span class="sp-alert-dot"></span>' + escapeHtml(alert.message) + '</div>';
			})
			.join('');
	}

	function renderNotes(data) {
		var container = $('sp-notes');
		if (!container) {
			return;
		}

		var notes = [];
		var settingsUrl = 'admin.php?page=server-pulse-settings';

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
						escapeHtml('detected on this host but not configured or enabled.') +
						' <a href="' +
						settingsUrl +
						'">Open settings</a></p>'
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
				unit: container.getAttribute('data-unit') || ''
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
		startAuto();
	});
})();
