/*
 * Orbit chart renderer — turns the server-computed payload into inline SVG.
 *
 * The server (libs/chart.php) decides every number: normalised points, bar geometry, donut angles and
 * axis ticks. This file only draws, measures and handles hover. That split is deliberate — the parts
 * that can be wrong are unit-tested in PHP, and this stays small enough to read in one sitting.
 *
 * Contract with the markup (see views/common/chart.php):
 *   <div class="chart" data-chart><script type="application/json">{payload}</script>
 *     <div class="chart__canvas"></div>
 *     <table class="chart__table">…</table>   ← the no-JavaScript / screen-reader version
 *   </div>
 *
 * If anything here throws, the table stays visible and the page is still correct: every function is
 * wrapped so a broken chart can never blank a dashboard.
 */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';

  /* ------------------------------------------------------------------ utils */

  function esc(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtNumber(value, payload) {
    var decimals = payload.format === 'currency' ? 0 : (Math.abs(value) < 10 && value % 1 !== 0 ? 1 : 0);
    var text;
    try {
      text = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      }).format(value);
    } catch (err) {
      text = String(Math.round(value));
    }
    return (payload.prefix || '') + text + (payload.suffix || '');
  }

  function fmtShort(value) {
    var absolute = Math.abs(value);
    if (absolute >= 1000000000) return (value / 1000000000).toFixed(1).replace('.0', '') + 'B';
    if (absolute >= 1000000) return (value / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (absolute >= 1000) return (value / 1000).toFixed(1).replace('.0', '') + 'k';
    return String(Math.round(value));
  }

  function niceLabel(label) {
    /* "2026-09-14" → "14/09" so a 30-day axis stays readable; anything else is passed through. */
    var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(label));
    if (match) return match[3] + '/' + match[2];
    return String(label);
  }

  function el(tag, attrs) {
    var node = document.createElementNS(SVG_NS, tag);
    for (var key in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, key)) node.setAttribute(key, attrs[key]);
    }
    return node;
  }

  /* Geometry: map the 0..100 payload space into the plot rectangle. */
  function makeMapper(plot) {
    return function (x, y) {
      return {
        x: plot.left + (x / 100) * plot.width,
        y: plot.top + (y / 100) * plot.height
      };
    };
  }

  function drawGrid(svg, plot, map, payload) {
    var group = el('g', { 'class': 'chart__grid' });
    payload.ticks.forEach(function (tick) {
      var y = map(0, 100 - (tick / payload.max) * 100).y;
      group.appendChild(el('line', { x1: plot.left, x2: plot.left + plot.width, y1: y, y2: y }));
      var text = el('text', {
        'class': 'chart__axis-label',
        x: plot.left - 8,
        y: y + 3.5,
        'text-anchor': 'end'
      });
      text.textContent = fmtShort(tick);
      svg.appendChild(text);
    });
    svg.appendChild(group);
  }

  function drawXLabels(svg, plot, payload) {
    var labels = payload.labels;
    if (!labels.length) return;
    var maxLabels = Math.max(2, Math.floor(plot.width / 64));
    var step = Math.ceil(labels.length / maxLabels);
    labels.forEach(function (label, index) {
      if (index % step !== 0 && index !== labels.length - 1) return;
      var x = labels.length === 1 ? plot.left + plot.width / 2
        : plot.left + (index / (labels.length - 1)) * plot.width;
      var text = el('text', { 'class': 'chart__x-label', x: x, y: plot.top + plot.height + 16 });
      text.textContent = niceLabel(label);
      svg.appendChild(text);
    });
  }

  function drawSeriesPath(svg, map, payload, series) {
    if (series.area && payload.type === 'area') {
      var area = el('path', {
        d: pathFromPoints(series.area, map),
        'class': 'chart__area c-' + series.color
      });
      svg.appendChild(area);
    }
    if (series.path) {
      svg.appendChild(el('path', {
        d: pathFromPoints(series.path, map),
        'class': 'chart__line c-' + series.color,
        fill: 'none'
      }));
    }
    if (series.showMarkers && series.points) {
      series.points.forEach(function (point) {
        var mapped = map(point.x, point.y);
        svg.appendChild(el('circle', {
          cx: mapped.x, cy: mapped.y, r: 3,
          'class': 'chart__marker c-' + series.color
        }));
      });
    }
  }

  /* The payload ships ready-made SVG path *commands*; re-map their coordinates to the plot box. */
  function pathFromPoints(path, map) {
    return String(path).replace(/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/g, function (match, x, y) {
      var mapped = map(parseFloat(x), parseFloat(y));
      return mapped.x + ' ' + mapped.y;
    });
  }

  function drawBars(svg, map, payload, series) {
    (series.bars || []).forEach(function (bar, index) {
      var topLeft = map(bar.x, bar.y);
      var width = (bar.width / 100) * plotWidth(svg.__plot);
      var height = (bar.height / 100) * svg.__plot.height;
      svg.appendChild(el('rect', {
        x: topLeft.x, y: topLeft.y,
        width: Math.max(1, width), height: Math.max(0, height),
        rx: Math.min(4, width / 3),
        'class': 'chart__bar c-' + series.color,
        'data-index': index
      }));
    });
  }

  function plotWidth(plot) { return plot.width; }

  function drawDonut(svg, plot, payload) {
    var size = Math.min(plot.width, plot.height * 2.2);
    var cx = plot.left + plot.width / 2;
    var cy = plot.top + plot.height / 2;
    var radius = Math.max(20, size / 2 - 18);
    var circumference = 2 * Math.PI * radius;
    var group = el('g', { transform: 'rotate(-90 ' + cx + ' ' + cy + ')' });

    group.appendChild(el('circle', {
      cx: cx, cy: cy, r: radius, 'class': 'chart__donut-track',
      'stroke-width': 22, 'fill': 'none'
    }));

    var offset = 0;
    payload.series.forEach(function (slice) {
      var length = ((slice.end - slice.start) / 360) * circumference;
      group.appendChild(el('circle', {
        cx: cx, cy: cy, r: radius,
        'class': 'chart__slice c-' + slice.color,
        'stroke-dasharray': length + ' ' + (circumference - length),
        'stroke-dashoffset': -offset,
        'data-index': payload.series.indexOf(slice)
      }));
      offset += length;
    });
    svg.appendChild(group);

    var total = el('text', { 'class': 'chart__donut-total', x: cx, y: cy + 2 });
    total.textContent = fmtShort(payload.total);
    svg.appendChild(total);
    var label = el('text', { 'class': 'chart__donut-sub', x: cx, y: cy + 16 });
    label.textContent = payload.totalLabel || 'total';
    svg.appendChild(label);
  }

  function drawSpark(svg, map, payload, series) {
    if (!series.path) return;
    svg.appendChild(el('path', {
      d: pathFromPoints(series.path, map),
      'class': 'chart__line c-' + series.color, fill: 'none'
    }));
  }

  /* ----------------------------------------------------------------- render */

  function render(root) {
    var dataNode = root.querySelector('script[type="application/json"]');
    var canvas = root.querySelector('.chart__canvas');
    if (!dataNode || !canvas) return;
    var payload;
    try {
      payload = JSON.parse(dataNode.textContent || '{}');
    } catch (err) {
      return;
    }
    var tip = root.querySelector('.chart__tip');
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'chart__tip';
      root.appendChild(tip);
    }

    function showTip(event, html) {
      tip.innerHTML = html;
      tip.classList.add('chart__tip--on');
      var box = root.getBoundingClientRect();
      tip.style.left = (event.clientX - box.left) + 'px';
      tip.style.top = (event.clientY - box.top - 6) + 'px';
    }
    function hideTip() { tip.classList.remove('chart__tip--on'); }

    function draw() {
      var width = Math.max(240, canvas.clientWidth || root.clientWidth || 640);
      var height = payload.height || 220;
      var padding = payload.type === 'spark'
        ? { top: 3, right: 3, bottom: 3, left: 3 }
        : { top: 14, right: 10, bottom: 26, left: 54 };
      var plot = {
        left: padding.left,
        top: padding.top,
        width: Math.max(60, width - padding.left - padding.right),
        height: Math.max(40, height - padding.top - padding.bottom)
      };
      var svg = el('svg', {
        viewBox: '0 0 ' + width + ' ' + height,
        width: width,
        height: height,
        role: 'img',
        'aria-label': root.getAttribute('data-summary') || 'Chart',
        preserveAspectRatio: 'xMidYMid meet'
      });
      svg.__plot = plot;
      var map = makeMapper(plot);

      if (payload.type === 'donut') {
        drawDonut(svg, plot, payload);
      } else if (payload.type === 'spark') {
        drawSpark(svg, map, payload, payload.series[0] || {});
      } else {
        if (!payload.sparkline) {
          drawGrid(svg, plot, map, payload);
          drawXLabels(svg, plot, payload);
        }
        payload.series.forEach(function (series) {
          if (payload.type === 'bar') {
            drawBars(svg, map, payload, series);
          } else {
            drawSeriesPath(svg, map, payload, series);
          }
        });
      }

      /* hover layer: one hit band per x position (line/area) or bar */
      var hit = el('g', { 'class': 'chart__hits' });
      var hits = [];
      if (payload.type === 'bar' && payload.series[0] && payload.series[0].bars) {
        payload.series[0].bars.forEach(function (bar, index) {
          hits.push({
            x: map(bar.x, 0).x,
            w: (bar.width / 100) * plot.width,
            y: plot.top,
            h: plot.height,
            index: index
          });
        });
      } else if (payload.type === 'donut') {
        /* handled per slice below */
      } else if (payload.labels.length) {
        var count = payload.labels.length;
        var band = plot.width / Math.max(1, count - (count > 1 ? 1 : 0));
        payload.labels.forEach(function (label, index) {
          var x = count === 1 ? plot.left : map((index / (count - 1)) * 100, 0).x - band / 2;
          hits.push({
            x: Math.max(plot.left, x),
            w: Math.min(band, plot.left + plot.width - Math.max(plot.left, x)),
            y: plot.top,
            h: plot.height,
            index: index
          });
        });
      }
      hits.forEach(function (band) {
        var rect = el('rect', {
          x: band.x, y: band.y, width: Math.max(6, band.w), height: band.h, 'class': 'chart__hit'
        });
        rect.addEventListener('mousemove', function (event) {
          var lines = ['<span class="chart__tip-label">' + esc(niceLabel(payload.labels[band.index] || '')) + '</span>'];
          payload.series.forEach(function (series) {
            var value = series.bars ? (series.bars[band.index] || {}).value
              : (series.points ? (series.points[band.index] || {}).value : null);
            if (value === null || value === undefined) return;
            lines.push((series.name ? esc(series.name) + ': ' : '') + fmtNumber(value, payload));
          });
          showTip(event, lines.join(' '));
        });
        rect.addEventListener('mouseleave', hideTip);
        hit.appendChild(rect);
      });
      svg.appendChild(hit);
      canvas.innerHTML = '';
      canvas.appendChild(svg);
      root.classList.add('is-rendered');

      /* donut slices get their own tooltip */
      if (payload.type === 'donut') {
        canvas.querySelectorAll('.chart__slice').forEach(function (slice) {
          var data = payload.series[parseInt(slice.getAttribute('data-index'), 10)];
          if (!data) return;
          slice.addEventListener('mousemove', function (event) {
            showTip(event, '<span class="chart__tip-label">' + esc(data.label) + '</span>' +
              fmtNumber(data.value, payload) + ' · ' + data.share + '%');
          });
          slice.addEventListener('mouseleave', hideTip);
        });
      }
    }

    draw();
    if (typeof ResizeObserver !== 'undefined') {
      var lastWidth = 0;
      var observer = new ResizeObserver(function () {
        var width = canvas.clientWidth;
        if (Math.abs(width - lastWidth) < 8) return; /* ignore sub-8px jitter */
        lastWidth = width;
        draw();
      });
      observer.observe(canvas);
    } else if (window.addEventListener) {
      var timer = null;
      window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(draw, 150);
      });
    }
  }

  function boot() {
    var charts = document.querySelectorAll('[data-chart]');
    for (var i = 0; i < charts.length; i++) {
      try {
        render(charts[i]);
      } catch (err) {
        /* The server-rendered table remains as the chart: a failure here must not break the page. */
        if (window.console && console.warn) console.warn('Orbit chart skipped:', err);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
