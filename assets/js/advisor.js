/**
 * Server Pulse diagnostics advisor (vanilla JS).
 */
(function () {
	'use strict';

	if (typeof window.serverPulseAdvisor === 'undefined') {
		return;
	}

	var config = window.serverPulseAdvisor;

	var CATEGORY_ORDER = ['server', 'database', 'wordpress', 'security', 'performance', 'storage'];

	var CATEGORY_LABELS = config.i18n.categories || {};
	var SEVERITY_LABELS = config.i18n.severities || {};

	var report = null;
	var state = { severity: '', category: '' };

	function $(id) {
		return document.getElementById(id);
	}

	function setText(id, value) {
		var node = $(id);
		if (node) {
			node.textContent = value;
		}
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function categoryLabel(category) {
		return CATEGORY_LABELS[category] || category;
	}

	function severityLabel(severity) {
		return SEVERITY_LABELS[severity] || severity;
	}

	function allFindings() {
		return (report && Array.isArray(report.findings)) ? report.findings : [];
	}

	function filtersActive() {
		return !!(state.severity || state.category);
	}

	function countBySeverity(findings) {
		var counts = { critical: 0, warning: 0, info: 0, good: 0 };

		findings.forEach(function (finding) {
			var severity = finding.severity || 'info';
			if (Object.prototype.hasOwnProperty.call(counts, severity)) {
				counts[severity]++;
			}
		});

		return counts;
	}

	function countByCategory(findings) {
		var counts = {};

		findings.forEach(function (finding) {
			var category = finding.category || 'other';
			counts[category] = (counts[category] || 0) + 1;
		});

		return counts;
	}

	// Findings that match the active filters, but ignoring one dimension so
	// the counts shown on the other dimension stay consistent with the list.
	function scopedFindings(ignore) {
		return allFindings().filter(function (finding) {
			if ('severity' !== ignore && state.severity && finding.severity !== state.severity) {
				return false;
			}
			if ('category' !== ignore && state.category && finding.category !== state.category) {
				return false;
			}
			return true;
		});
	}

	function renderAll() {
		renderSummary();
		renderCategoryChips();
		renderList();
	}

	function renderFinding(finding) {
		var severity = finding.severity || 'info';
		var html = '<div class="sp-finding is-' + escapeHtml(severity) + '">';
		html += '<div class="sp-finding-head">';
		html += '<span class="sp-sev is-' + escapeHtml(severity) + '">' + escapeHtml(severityLabel(severity)) + '</span>';
		html += '<strong>' + escapeHtml(finding.title) + '</strong>';
		html += '</div>';

		if (finding.explanation) {
			html += '<p class="sp-finding-text">' + escapeHtml(finding.explanation) + '</p>';
		}

		if (finding.recommendation) {
			html +=
				'<p class="sp-finding-fix"><span class="dashicons dashicons-lightbulb"></span>' +
				escapeHtml(finding.recommendation) +
				'</p>';
		}

		if (finding.detail) {
			html += '<p class="sp-finding-detail">' + escapeHtml(finding.detail) + '</p>';
		}

		if (finding.action && severity !== 'good') {
			html +=
				'<button type="button" class="button sp-fix" data-fix="' +
				escapeHtml(finding.action) +
				'">' +
				escapeHtml(config.i18n.applyFix) +
				'</button>';
		}

		html += '</div>';
		return html;
	}

	function visibleFindings() {
		return scopedFindings( '' );
	}

	function renderList() {
		var container = $('sp-advisor');
		if (!container) {
			return;
		}

		var findings = visibleFindings();

		if (!findings.length) {
			var message = filtersActive() ? config.i18n.emptyFiltered : config.i18n.empty;
			container.innerHTML = '<p class="sp-empty">' + escapeHtml(message) + '</p>';
			return;
		}

		// Group by category preserving a stable order.
		var groups = {};
		findings.forEach(function (finding) {
			var category = finding.category || 'other';
			if (!groups[category]) {
				groups[category] = [];
			}
			groups[category].push(finding);
		});

		var order = CATEGORY_ORDER.filter(function (category) {
			return groups[category];
		});
		Object.keys(groups).forEach(function (category) {
			if (order.indexOf(category) === -1) {
				order.push(category);
			}
		});

		var html = '';
		order.forEach(function (category) {
			html +=
				'<h3 class="sp-cat-head">' +
				escapeHtml(categoryLabel(category)) +
				' <span class="sp-cat-count">' +
				groups[category].length +
				'</span></h3>';
			html += groups[category].map(renderFinding).join('');
		});

		container.innerHTML = html;
		bindFixes();
	}

	function renderCategoryChips() {
		var container = $('sp-advisor-cats');
		if (!container) {
			return;
		}

		var counts = countByCategory( scopedFindings( 'category' ) );

		var order = CATEGORY_ORDER.filter(function (category) {
			return counts[category];
		});
		Object.keys(counts).forEach(function (category) {
			if (order.indexOf(category) === -1 && counts[category]) {
				order.push(category);
			}
		});

		var html = '<button type="button" class="sp-chip sp-cat-chip' + (state.category ? '' : ' is-active') + '" data-category="">' + escapeHtml(config.i18n.all) + '</button>';

		order.forEach(function (category) {
			html +=
				'<button type="button" class="sp-chip sp-cat-chip' +
				(state.category === category ? ' is-active' : '') +
				'" data-category="' +
				escapeHtml(category) +
				'">' +
				escapeHtml(categoryLabel(category)) +
				' <span class="sp-cat-count">' +
				counts[category] +
				'</span></button>';
		});

		if (filtersActive()) {
			html += '<button type="button" class="sp-chip sp-clear-filters" id="sp-advisor-clear">' + escapeHtml(config.i18n.clearFilters) + '</button>';
		}

		container.innerHTML = html;

		Array.prototype.forEach.call(container.querySelectorAll('.sp-cat-chip'), function (chip) {
			chip.addEventListener('click', function () {
				state.category = chip.getAttribute('data-category') || '';
				renderAll();
			});
		});

		var clear = $('sp-advisor-clear');
		if (clear) {
			clear.addEventListener('click', function () {
				state.severity = '';
				state.category = '';
				renderAll();
			});
		}
	}

	function renderSummary() {
		var counts = countBySeverity( scopedFindings( 'severity' ) );

		setText('sp-advisor-critical', counts.critical);
		setText('sp-advisor-warning', counts.warning);
		setText('sp-advisor-info', counts.info);
		setText('sp-advisor-host', (report && report.host && report.host.label) || '—');

		Array.prototype.forEach.call(document.querySelectorAll('#sp-advisor-summary .sp-filter'), function (chip) {
			chip.classList.toggle('is-active', chip.getAttribute('data-severity') === state.severity);
		});
	}

	function render(data) {
		report = data || { findings: [] };
		renderAll();
	}

	function bindSeverityFilters() {
		Array.prototype.forEach.call(document.querySelectorAll('#sp-advisor-summary .sp-filter'), function (chip) {
			chip.addEventListener('click', function () {
				var severity = chip.getAttribute('data-severity') || '';
				state.severity = state.severity === severity ? '' : severity;
				renderAll();
			});
		});
	}

	function bindFixes() {
		Array.prototype.forEach.call(document.querySelectorAll('.sp-fix'), function (button) {
			button.addEventListener('click', function () {
				if (!window.confirm(config.i18n.confirm)) {
					return;
				}
				runFix(button);
			});
		});
	}

	function runFix(button) {
		var fix = button.getAttribute('data-fix');
		button.disabled = true;
		button.textContent = config.i18n.fixing;

		var params = new URLSearchParams();
		params.append('action', 'server_pulse_run_fix');
		params.append('nonce', config.nonce);
		params.append('fix', fix);

		fetch(config.ajaxurl, {
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
					setText('sp-advisor-updated', payload.data.message);
				} else {
					setText('sp-advisor-updated', (payload && payload.data && payload.data.message) || config.i18n.error);
				}
				fetchReport(true);
			})
			.catch(function () {
				setText('sp-advisor-updated', config.i18n.error);
				button.disabled = false;
			});
	}

	function fetchReport(force) {
		var params = new URLSearchParams();
		params.append('action', 'server_pulse_get_advisor');
		params.append('nonce', config.nonce);
		params.append('force', force ? 'true' : 'false');

		if (force) {
			setText('sp-advisor-updated', config.i18n.running);
		}

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
					setText('sp-advisor-updated', new Date().toLocaleTimeString());
				} else {
					setText('sp-advisor-updated', (payload && payload.data && payload.data.message) || config.i18n.error);
				}
			})
			.catch(function () {
				setText('sp-advisor-updated', config.i18n.error);
			});
	}

	function bind() {
		bindSeverityFilters();

		var run = $('sp-advisor-run');
		if (run) {
			run.addEventListener('click', function () {
				run.disabled = true;
				fetchReport(true).then(function () {
					run.disabled = false;
				});
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var params = new URLSearchParams(window.location.search);
		var severity = params.get('severity');
		var category = params.get('category');

		if (severity && Object.prototype.hasOwnProperty.call(SEVERITY_LABELS, severity)) {
			state.severity = severity;
		}
		if (category && CATEGORY_ORDER.indexOf(category) !== -1) {
			state.category = category;
		}

		bind();
		fetchReport(false);
	});
})();
