/**
 * Minimal dependency-free SVG line chart used by Server Pulse.
 * Exposes window.ServerPulseChart.
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';

	function create(name, attrs) {
		var node = document.createElementNS(NS, name);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function parseDate(value) {
		if (!value) {
			return null;
		}
		var date = new Date(String(value).replace(' ', 'T') + 'Z');
		return isNaN(date.getTime()) ? null : date;
	}

	function formatValue(value, unit) {
		var rounded = Math.round(value * 10) / 10;
		return rounded + (unit || '');
	}

	/**
	 * @param {HTMLElement} el Container element.
	 * @param {Object}      opts Chart options.
	 */
	function Chart(el, opts) {
		this.el = el;
		this.opts = Object.assign(
			{
				color: '#2271b1',
				fill: 'rgba(34, 113, 177, 0.14)',
				label: '',
				unit: '',
				height: 130
			},
			opts || {}
		);

		this._build();
	}

	Chart.prototype._build = function () {
		this.el.innerHTML = '';

		this.label = document.createElement('div');
		this.label.className = 'sp-chart-label';
		this.label.textContent = this.opts.label;
		this.el.appendChild(this.label);

		this.value = document.createElement('div');
		this.value.className = 'sp-chart-value';
		this.value.textContent = '—';
		this.el.appendChild(this.value);

		this.svg = create('svg', {
			viewBox: '0 0 600 ' + this.opts.height,
			preserveAspectRatio: 'none',
			class: 'sp-chart-svg'
		});
		this.el.appendChild(this.svg);
	};

	Chart.prototype.setData = function (points) {
		points = Array.isArray(points) ? points : [];
		this.svg.innerHTML = '';

		if (!points.length) {
			var empty = create('text', {
				x: 300,
				y: this.opts.height / 2,
				'text-anchor': 'middle',
				class: 'sp-chart-empty'
			});
			empty.textContent = 'No data yet';
			this.svg.appendChild(empty);
			this.value.textContent = '—';
			return;
		}

		var width = 600;
		var height = this.opts.height;
		var padTop = 12;
		var padBottom = 18;
		var padX = 6;

		var values = points.map(function (p) {
			return Number(p.v);
		});
		var times = points.map(function (p, index) {
			var parsed = parseDate(p.t);
			return parsed ? parsed.getTime() : index;
		});

		var min = Math.min.apply(null, values);
		var max = Math.max.apply(null, values);
		if (min === max) {
			min = min - 1;
			max = max + 1;
		}

		var span = max - min;
		var timeMin = Math.min.apply(null, times);
		var timeMax = Math.max.apply(null, times);
		var timeSpan = timeMax - timeMin || 1;

		var plotW = width - padX * 2;
		var plotH = height - padTop - padBottom;

		function xAt(index) {
			return padX + ((times[index] - timeMin) / timeSpan) * plotW;
		}

		function yAt(value) {
			return padTop + (1 - (value - min) / span) * plotH;
		}

		// Grid.
		for (var g = 0; g <= 3; g++) {
			var gy = padTop + (plotH / 3) * g;
			this.svg.appendChild(
				create('line', {
					x1: 0,
					y1: gy,
					x2: width,
					y2: gy,
					class: 'sp-grid-line'
				})
			);
		}

		var linePoints = [];
		var areaPoints = [padX + ', ' + (height - padBottom)];

		points.forEach(
			function (point, index) {
				var px = xAt(index);
				var py = yAt(values[index]);
				linePoints.push(px + ',' + py);
				areaPoints.push(px + ',' + py);
			}.bind(this)
		);

		areaPoints.push((padX + plotW) + ',' + (height - padBottom));

		this.svg.appendChild(create('polygon', { points: areaPoints.join(' '), fill: this.opts.fill, stroke: 'none' }));

		this.svg.appendChild(
			create('polyline', {
				points: linePoints.join(' '),
				fill: 'none',
				stroke: this.opts.color,
				'stroke-width': 2,
				'stroke-linejoin': 'round',
				'stroke-linecap': 'round'
			})
		);

		// Last point marker.
		var lastIndex = points.length - 1;
		this.svg.appendChild(
			create('circle', {
				cx: xAt(lastIndex),
				cy: yAt(values[lastIndex]),
				r: 3.5,
				fill: this.opts.color
			})
		);

		this.value.textContent = formatValue(values[lastIndex], this.opts.unit);
	};

	window.ServerPulseChart = Chart;
})();
