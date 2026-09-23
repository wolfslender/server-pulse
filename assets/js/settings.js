/**
 * Server Pulse settings screen: provider connection tests.
 */
(function () {
	'use strict';

	if (typeof window.serverPulseSettings === 'undefined') {
		return;
	}

	var config = window.serverPulseSettings;

	function renderSteps(steps) {
		var html = '<ul class="sp-diag">';
		steps.forEach(function (step) {
			var status = step.status || 'ok';
			html +=
				'<li class="sp-diag-item is-' +
				status +
				'"><span class="sp-diag-dot"></span><strong>' +
				escapeHtml(step.label) +
				'</strong><span class="sp-diag-detail">' +
				escapeHtml(step.detail) +
				'</span></li>';
		});
		html += '</ul>';
		return html;
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function test(button) {
		var provider = button.getAttribute('data-provider');
		var target = document.getElementById('sp-test-' + provider);

		if (!target) {
			return;
		}

		button.disabled = true;
		target.className = 'sp-test-result';
		target.innerHTML = config.i18n.testing;

		var params = new URLSearchParams();
		params.append('action', 'server_pulse_test_connection');
		params.append('nonce', config.nonce);
		params.append('provider', provider);

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
				button.disabled = false;

				if (!payload || !payload.success) {
					target.className = 'sp-test-result is-error';
					target.innerHTML = escapeHtml((payload && payload.data && payload.data.message) || config.i18n.failed);
					return;
				}

				var data = payload.data || {};
				target.className = 'sp-test-result ' + (data.ok === false ? 'is-error' : 'is-ok');

				if (Array.isArray(data.steps)) {
					target.innerHTML =
						'<p class="sp-diag-title">' +
						(data.ok === false ? config.i18n.failed : config.i18n.ok) +
						'</p>' +
						renderSteps(data.steps);
				} else {
					target.innerHTML = config.i18n.ok;
				}
			})
			.catch(function () {
				button.disabled = false;
				target.className = 'sp-test-result is-error';
				target.innerHTML = config.i18n.error;
			});
	}

	function testAlert(button) {
		var target = document.getElementById('sp-test-alert-result');
		if (!target) {
			return;
		}

		button.disabled = true;
		target.className = 'sp-test-result';
		target.innerHTML = config.i18n.sending;

		var params = new URLSearchParams();
		params.append('action', 'server_pulse_send_test_alert');
		params.append('nonce', config.nonce);

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
				button.disabled = false;

				if (!payload || !payload.success) {
					target.className = 'sp-test-result is-error';
					target.innerHTML = escapeHtml((payload && payload.data && payload.data.message) || config.i18n.testFailed);
					return;
				}

				var data = payload.data || {};
				var failed = Object.keys(data.failed || {});
				target.className = 'sp-test-result ' + (failed.length ? 'is-error' : 'is-ok');

				var html = '<p class="sp-diag-title">' + escapeHtml(config.i18n.testSent) + '</p><ul class="sp-diag">';
				(data.sent || []).forEach(function (channel) {
					html += '<li class="sp-diag-item is-ok"><span class="sp-diag-dot"></span><strong>' + escapeHtml(channel) + '</strong></li>';
				});
				failed.forEach(function (channel) {
					html +=
						'<li class="sp-diag-item is-error"><span class="sp-diag-dot"></span><strong>' +
						escapeHtml(channel) +
						'</strong><span class="sp-diag-detail">' +
						escapeHtml(data.failed[channel]) +
						'</span></li>';
				});
				html += '</ul>';
				target.innerHTML = html;
			})
			.catch(function () {
				button.disabled = false;
				target.className = 'sp-test-result is-error';
				target.innerHTML = escapeHtml(config.i18n.error);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.sp-test'), function (button) {
			button.addEventListener('click', function () {
				test(button);
			});
		});

		Array.prototype.forEach.call(document.querySelectorAll('.sp-test-alert'), function (button) {
			button.addEventListener('click', function () {
				testAlert(button);
			});
		});
	});
})();
