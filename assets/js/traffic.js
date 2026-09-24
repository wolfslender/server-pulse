/**
 * Server Pulse — Pro traffic & logs view (vanilla JS).
 */
(function () {
	'use strict';

	if (typeof window.serverPulseTraffic === 'undefined') {
		return;
	}

	var config = window.serverPulseTraffic;
	var i18n = config.i18n || {};

	function $(id) {
		return document.getElementById(id);
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function num(value) {
		var n = Number(value) || 0;
		return n.toLocaleString();
	}

	function table(headers, rows) {
		var out = '<table class="sp-table"><thead><tr>';
		headers.forEach(function (h) {
			out += '<th>' + escapeHtml(h) + '</th>';
		});
		out += '</tr></thead><tbody>';
		if (!rows.length) {
			out += '<tr><td colspan="' + headers.length + '" class="sp-muted">—</td></tr>';
		}
		rows.forEach(function (cells) {
			out += '<tr>';
			cells.forEach(function (cell, index) {
				out += '<td>' + (index === 0 && typeof cell === 'string' ? '<code>' + escapeHtml(cell) + '</code>' : escapeHtml(cell)) + '</td>';
			});
			out += '</tr>';
		});
		return out + '</tbody></table>';
	}

	function kpis(traffic) {
		var status = traffic.status || {};
		var items = [
			{ label: i18n.requests, value: traffic.requests || 0 },
			{ label: '5xx', value: status['5xx'] || 0 },
			{ label: '504', value: status['504'] || 0 },
			{ label: '404', value: status['404'] || 0 },
			{ label: i18n.emptyUa, value: traffic.empty_ua || 0 }
		];

		var out = '<div class="sp-kpis">';
		items.forEach(function (item) {
			out += '<div class="sp-kpi"><span class="sp-muted">' + escapeHtml(item.label) + '</span><b>' + escapeHtml(num(item.value)) + '</b></div>';
		});
		return out + '</div>';
	}

	function section(title, html) {
		return '<div class="sp-card"><h2>' + escapeHtml(title) + '</h2>' + html + '</div>';
	}

	function renderRecommendations(recs) {
		if (!recs || !recs.rules || !recs.rules.length) {
			return '';
		}

		var out = '<div class="sp-card"><h2>' + escapeHtml(i18n.actions) + '</h2><ol class="sp-actions">';
		recs.rules.forEach(function (rule) {
			out += '<li>';
			out += '<strong>' + escapeHtml(rule.title) + '</strong>';
			out += '<p class="sp-muted">' + escapeHtml(rule.why) + '</p>';
			out += '<div class="sp-rule"><pre>' + escapeHtml(rule.rule) + '</pre>';
			out += '<button type="button" class="button sp-copy" data-copy="' + escapeHtml(rule.rule) + '">' + escapeHtml('Copy') + '</button>';
			out += '</div></li>';
		});
		return out + '</ol></div>';
	}

	function renderErrors(errors) {
		if (!errors || !errors.groups || !errors.groups.length) {
			return '';
		}

		var rows = errors.groups.map(function (group) {
			return [
				num(group.count),
				group.severity,
				group.file + ':' + group.line,
				group.message
			];
		});

		return section(i18n.phpErrors, table([i18n.thCount, i18n.thSeverity, i18n.thLocation, i18n.thMessage], rows));
	}

	function render(data) {
		var container = $('sp-traffic-report');
		if (!container) {
			return;
		}

		var traffic = (data && data.traffic) || {};
		var errors = (data && data.errors) || {};
		var recs = (data && data.recommendations) || { rules: [] };

		if (!traffic.requests) {
			container.innerHTML = '<p class="sp-empty">' + escapeHtml(i18n.emptyState) + '</p>';
			return;
		}

		var html = '';

		if (traffic.capped) {
			html += '<div class="notice notice-warning inline"><p>' + escapeHtml(i18n.capped) + '</p></div>';
		}

		html += '<div class="sp-card">' + kpis(traffic);
		html += '<p class="sp-muted">' + escapeHtml(i18n.generated) + ': ' + escapeHtml(new Date((traffic.generated_at || 0) * 1000).toLocaleString()) + '</p>';
		html += '</div>';

		html += renderRecommendations(recs);

		var ipRows = (traffic.top_ips || []).map(function (row) {
			return [row.ip, num(row.requests), num(row.five_xx), num(row.empty_ua)];
		});
		html += section(i18n.thIp, table([i18n.thIp, i18n.thReq, i18n.th5xx, i18n.thEmptyUa], ipRows));

		var dayRows = Object.keys(traffic.five_xx_by_day || {}).map(function (day) {
			return [day, num(traffic.five_xx_by_day[day]), num((traffic.by_day || {})[day] || 0)];
		});
		html += section(i18n.byDay, table([i18n.day, '5xx', i18n.thReq], dayRows));

		var minuteRows = (traffic.top_minutes || []).map(function (row) {
			return [row.minute, num(row.requests), num(row.five_xx)];
		});
		html += section(i18n.busiestMinutes, table([i18n.thMinute, i18n.thReq, i18n.th5xx], minuteRows));

		var missingRows = (traffic.missing_assets || []).map(function (row) {
			return [row.path, num(row.requests), row.suggestion];
		});
		html += section(i18n.missingAssets, table([i18n.thPath, i18n.thCount, i18n.thSuggestion], missingRows));

		var heavyRows = (traffic.heavy_endpoints || []).map(function (row) {
			var help = (i18n.heavyHelp && i18n.heavyHelp[row.type]) ? i18n.heavyHelp[row.type] : (i18n.heavyHelp ? i18n.heavyHelp.generic : '');
			var search = row.search ? row.search : '';
			var work = help;
			if (search) {
				work += ' ' + i18n.thSearchFor + ': ' + search;
			}
			return [row.path, num(row.requests), work];
		});
		var heavyHtml = table([i18n.thPath, i18n.thReq, i18n.thWhatToDo], heavyRows);
		if (heavyRows.length) {
			heavyHtml += '<p class="sp-muted sp-howto">' + escapeHtml(i18n.howToLook) + '</p>';
		}
		html += section(i18n.heavyEndpoints, heavyHtml);

		html += renderErrors(errors);

		container.innerHTML = html;
		bindCopy();
	}

	function bindCopy() {
		Array.prototype.forEach.call(document.querySelectorAll('.sp-copy'), function (button) {
			button.addEventListener('click', function () {
				var text = button.getAttribute('data-copy') || '';
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						setStatus(i18n.copied);
					}, function () {});
				}
			});
		});
	}

	function setStatus(message) {
		var node = $('sp-traffic-status');
		if (node) {
			node.textContent = message;
		}
	}

	function request(action, formData) {
		formData = formData || new FormData();
		formData.append('action', action);
		formData.append('nonce', config.nonce);

		return fetch(config.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(function (response) {
			return response.json();
		});
	}

	function analyzeWpe() {
		setStatus(i18n.running);
		request('server_pulse_traffic_analyze', buildForm('wpe')).then(function (payload) {
			if (payload && payload.success) {
				render(payload.data);
				setStatus('');
			} else {
				setStatus((payload && payload.data && payload.data.message) || i18n.error);
			}
		}).catch(function () {
			setStatus(i18n.error);
		});
	}

	function buildForm(source, file) {
		var form = new FormData();
		form.append('source', source);
		if (file) {
			form.append('logfile', file);
		}
		return form;
	}

	function analyzeUpload() {
		var input = $('sp-traffic-file');
		if (!input || !input.files || !input.files.length) {
			setStatus(i18n.noFile);
			return;
		}
		setStatus(i18n.running);
		request('server_pulse_traffic_analyze', buildForm('upload', input.files[0])).then(function (payload) {
			if (payload && payload.success) {
				render(payload.data);
				setStatus('');
			} else {
				setStatus((payload && payload.data && payload.data.message) || i18n.error);
			}
		}).catch(function () {
			setStatus(i18n.error);
		});
	}

	function clearReports() {
		if (!window.confirm(i18n.confirmClear)) {
			return;
		}
		request('server_pulse_traffic_clear').then(function (payload) {
			if (payload && payload.success) {
				render({});
				setStatus(i18n.cleared);
			}
		});
	}

	function bind() {
		var wpe = $('sp-traffic-wpe');
		if (wpe) {
			wpe.addEventListener('click', analyzeWpe);
		}

		var file = $('sp-traffic-file');
		var upload = $('sp-traffic-upload');
		var name = $('sp-traffic-filename');

		if (file) {
			file.addEventListener('change', function () {
				var has = file.files && file.files.length;
				if (upload) {
					upload.disabled = !has;
				}
				if (name) {
					name.textContent = has ? file.files[0].name : '';
				}
			});
		}
		if (upload) {
			upload.addEventListener('click', analyzeUpload);
		}

		var clear = $('sp-traffic-clear');
		if (clear) {
			clear.addEventListener('click', clearReports);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		bind();
		request('server_pulse_traffic_get').then(function (payload) {
			if (payload && payload.success) {
				render(payload.data);
			}
		}).catch(function () {});
	});
})();
