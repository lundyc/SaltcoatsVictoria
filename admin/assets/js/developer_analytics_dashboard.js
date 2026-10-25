/*
 * Developer analytics dashboard: KPI cards, trend charts, ranked bars,
 * split bars, heatmap and meters for developer_analytics.php.
 *
 * Every widget is an element with data-ad="<kind>" and a data-cfg attribute
 * naming a <script type="application/json"> block that holds its data. All
 * text from the data is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NS = 'http://www.w3.org/2000/svg';
    var INK = '#2d2b2c';
    var MUTED = '#70676b';
    var GRID = 'rgba(45, 43, 44, 0.08)';
    var SURFACE = '#fffdf9';

    /* ---------- helpers ---------- */

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function svg(tag, attrs) {
        var node = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    }

    function readCfg(node) {
        var id = node.getAttribute('data-cfg');
        var script = id ? document.getElementById(id) : null;
        if (!script) { return null; }
        try { return JSON.parse(script.textContent); } catch (e) { return null; }
    }

    function fmtNumber(value, fmt, decimals) {
        value = Number(value) || 0;
        switch (fmt) {
            case 'pct':
                return value.toLocaleString('en-GB', { minimumFractionDigits: decimals || 0, maximumFractionDigits: decimals || 0 }) + '%';
            case 'gbp':
                return '\u00a3' + value.toLocaleString('en-GB', { minimumFractionDigits: decimals || 0, maximumFractionDigits: decimals || 0 });
            case 'secd':
                return value.toLocaleString('en-GB', { minimumFractionDigits: decimals === undefined ? 1 : decimals, maximumFractionDigits: decimals === undefined ? 1 : decimals }) + 's';
            case 'sec':
                value = Math.round(value);
                return value < 60 ? value + 's' : Math.floor(value / 60) + 'm ' + (value % 60) + 's';
            case 'dec':
                return value.toLocaleString('en-GB', { minimumFractionDigits: decimals || 1, maximumFractionDigits: decimals || 1 });
            default:
                return Math.round(value).toLocaleString('en-GB');
        }
    }

    function ease(t) { return 1 - Math.pow(1 - t, 3); }

    function countUp(node, target, fmt, decimals) {
        if (reduceMotion || target === 0) { node.textContent = fmtNumber(target, fmt, decimals); return; }
        var start = null;
        var duration = 1000;
        function step(ts) {
            if (start === null) { start = ts; }
            var p = Math.min(1, (ts - start) / duration);
            node.textContent = fmtNumber(target * ease(p), fmt, decimals);
            if (p < 1) { requestAnimationFrame(step); } else { node.textContent = fmtNumber(target, fmt, decimals); }
        }
        requestAnimationFrame(step);
    }

    /** Run fn once when the element first scrolls into view. */
    function whenVisible(node, fn) {
        if (!('IntersectionObserver' in window)) { fn(); return; }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { io.disconnect(); fn(); }
            });
        }, { threshold: 0.15 });
        io.observe(node);
    }

    /* ---------- shared tooltip (used by HTML widgets) ---------- */

    var tip = null;
    function ensureTip() {
        if (tip) { return tip; }
        tip = el('div', 'ad-tip');
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);
        return tip;
    }

    function showTip(event, title, rows) {
        var t = ensureTip();
        t.textContent = '';
        if (title) { t.appendChild(el('div', 'ad-tip__title', title)); }
        (rows || []).forEach(function (row) {
            var line = el('div', 'ad-tip__row');
            if (row.color) {
                var key = el('span', 'ad-tip__key');
                key.style.background = row.color;
                line.appendChild(key);
            }
            line.appendChild(el('strong', 'ad-tip__value', row.value));
            line.appendChild(el('span', 'ad-tip__label', row.label));
            t.appendChild(line);
        });
        t.hidden = false;
        moveTip(event);
    }

    function moveTip(event) {
        if (!tip || tip.hidden) { return; }
        var pad = 14;
        var x = event.clientX + pad;
        var y = event.clientY + pad;
        var w = tip.offsetWidth;
        var h = tip.offsetHeight;
        if (x + w > window.innerWidth - 8) { x = event.clientX - w - pad; }
        if (y + h > window.innerHeight - 8) { y = event.clientY - h - pad; }
        tip.style.transform = 'translate(' + Math.max(8, x) + 'px,' + Math.max(8, y) + 'px)';
    }

    function hideTip() { if (tip) { tip.hidden = true; } }

    function bindTip(node, titleFn, rowsFn) {
        node.addEventListener('pointerenter', function (e) { showTip(e, titleFn(), rowsFn()); });
        node.addEventListener('pointermove', moveTip);
        node.addEventListener('pointerleave', hideTip);
        node.addEventListener('focus', function () {
            var r = node.getBoundingClientRect();
            showTip({ clientX: r.left + r.width / 2, clientY: r.top }, titleFn(), rowsFn());
        });
        node.addEventListener('blur', hideTip);
    }

    /* ---------- KPI cards ---------- */

    function sparkline(host, points, color) {
        if (!points || points.length < 2) { return; }
        var w = 120;
        var h = 34;
        var min = Math.min.apply(null, points);
        var max = Math.max.apply(null, points);
        var span = max - min || 1;
        var coords = points.map(function (v, i) {
            return [i / (points.length - 1) * (w - 6) + 3, h - 4 - (v - min) / span * (h - 10)];
        });
        var line = 'M' + coords.map(function (c) { return c[0].toFixed(1) + ' ' + c[1].toFixed(1); }).join(' L');
        var area = line + ' L' + coords[coords.length - 1][0].toFixed(1) + ' ' + h + ' L' + coords[0][0].toFixed(1) + ' ' + h + ' Z';
        var gid = 'adspark' + Math.random().toString(36).slice(2, 8);

        var s = svg('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none', 'aria-hidden': 'true', focusable: 'false' });
        var defs = svg('defs');
        var grad = svg('linearGradient', { id: gid, x1: '0', y1: '0', x2: '0', y2: '1' });
        grad.appendChild(svg('stop', { offset: '0', 'stop-color': color, 'stop-opacity': '0.28' }));
        grad.appendChild(svg('stop', { offset: '1', 'stop-color': color, 'stop-opacity': '0' }));
        defs.appendChild(grad);
        s.appendChild(defs);
        var areaPath = svg('path', { d: area, fill: 'url(#' + gid + ')', class: 'ad-spark__area' });
        var linePath = svg('path', { d: line, fill: 'none', stroke: color, 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'vector-effect': 'non-scaling-stroke', class: 'ad-spark__line' });
        s.appendChild(areaPath);
        s.appendChild(linePath);
        host.appendChild(s);
        var last = coords[coords.length - 1];
        var dot = el('span', 'ad-spark__dot');
        dot.style.left = (last[0] / w * 100) + '%';
        dot.style.top = (last[1] / h * 100) + '%';
        dot.style.background = color;
        host.appendChild(dot);

        if (!reduceMotion) {
            var len = linePath.getTotalLength ? linePath.getTotalLength() : 200;
            linePath.style.strokeDasharray = len;
            linePath.style.strokeDashoffset = len;
            host.classList.add('is-armed');
            whenVisible(host, function () {
                host.classList.add('is-drawn');
                linePath.style.transition = 'stroke-dashoffset 1.1s cubic-bezier(.22,.61,.36,1)';
                linePath.style.strokeDashoffset = 0;
            });
        }
    }

    function initKpi(card) {
        var valueNode = card.querySelector('[data-kpi-value]');
        var fmt = card.getAttribute('data-format') || 'int';
        var decimals = parseInt(card.getAttribute('data-decimals') || '0', 10);
        var target = parseFloat(card.getAttribute('data-value') || '0');
        if (valueNode) {
            valueNode.textContent = fmtNumber(0, fmt, decimals);
            whenVisible(card, function () { countUp(valueNode, target, fmt, decimals); });
        }
        var sparkHost = card.querySelector('[data-kpi-spark]');
        if (sparkHost) {
            var pts = [];
            try { pts = JSON.parse(sparkHost.getAttribute('data-points') || '[]'); } catch (e) { pts = []; }
            sparkline(sparkHost, pts, sparkHost.getAttribute('data-color') || '#6a2036');
        }
    }

    /* ---------- trend chart (Chart.js) ---------- */

    function hexToRgba(hex, alpha) {
        var m = /^#?([0-9a-f]{6})$/i.exec(hex);
        if (!m) { return hex; }
        var n = parseInt(m[1], 16);
        return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    var crosshair = {
        id: 'adCrosshair',
        afterDatasetsDraw: function (chart) {
            var active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
            if (!active.length) { return; }
            var x = active[0].element.x;
            var area = chart.chartArea;
            var ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(x, area.top);
            ctx.lineTo(x, area.bottom);
            ctx.lineWidth = 1;
            ctx.strokeStyle = 'rgba(45, 43, 44, 0.28)';
            ctx.setLineDash([3, 3]);
            ctx.stroke();
            ctx.restore();
        }
    };

    function initTrend(host) {
        var cfg = readCfg(host);
        if (!cfg || !window.Chart || !cfg.labels || !cfg.labels.length) { return; }
        var metrics = cfg.metrics || [];
        var exclusive = !!cfg.exclusive;
        var activeKeys = (cfg.active && cfg.active.length ? cfg.active : [metrics[0].key]).slice();

        var bar = el('div', 'ad-chips');
        bar.setAttribute('role', exclusive ? 'radiogroup' : 'group');
        bar.setAttribute('aria-label', cfg.title || 'Metrics');
        var stats = el('div', 'ad-trend__stats');
        var canvasWrap = el('div', 'ad-trend__canvas');
        var canvas = el('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', (cfg.title || 'Trend') + ' chart. A table of the same data is under Detailed tables.');
        canvasWrap.appendChild(canvas);
        host.appendChild(bar);
        host.appendChild(stats);
        host.appendChild(canvasWrap);

        var chips = {};
        metrics.forEach(function (m) {
            var chip = el('button', 'ad-chip');
            chip.type = 'button';
            chip.setAttribute(exclusive ? 'aria-checked' : 'aria-pressed', 'false');
            if (exclusive) { chip.setAttribute('role', 'radio'); }
            var dot = el('span', 'ad-chip__dot');
            dot.style.background = m.color;
            chip.appendChild(dot);
            chip.appendChild(el('span', null, m.label));
            chip.addEventListener('click', function () {
                if (exclusive) { activeKeys = [m.key]; }
                else if (activeKeys.indexOf(m.key) !== -1) { if (activeKeys.length > 1) { activeKeys = activeKeys.filter(function (k) { return k !== m.key; }); } }
                else { activeKeys.push(m.key); }
                sync(true);
            });
            chips[m.key] = chip;
            bar.appendChild(chip);
        });

        var chart = null;

        function datasetFor(m) {
            return {
                key: m.key,
                label: m.label,
                data: m.data,
                borderColor: m.color,
                borderWidth: 2.5,
                pointRadius: m.data.length <= 14 ? 4 : 0,
                pointBackgroundColor: m.color,
                pointBorderColor: SURFACE,
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBorderWidth: 2,
                pointHoverBorderColor: SURFACE,
                pointHoverBackgroundColor: m.color,
                tension: m.data.length <= 14 ? 0 : 0.3,
                fill: true,
                backgroundColor: function (ctx) {
                    var area = ctx.chart.chartArea;
                    if (!area) { return hexToRgba(m.color, 0.12); }
                    var g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                    g.addColorStop(0, hexToRgba(m.color, 0.26));
                    g.addColorStop(1, hexToRgba(m.color, 0));
                    return g;
                }
            };
        }

        function activeMetrics() {
            return metrics.filter(function (m) { return activeKeys.indexOf(m.key) !== -1; });
        }

        function renderStats() {
            stats.textContent = '';
            activeMetrics().forEach(function (m) {
                var vals = m.data.filter(function (v) { return v !== null && v !== undefined; });
                if (!vals.length) { return; }
                var sum = vals.reduce(function (a, b) { return a + b; }, 0);
                var best = m.reverse ? Math.min.apply(null, vals) : Math.max.apply(null, vals);
                var bestIdx = m.data.indexOf(best);
                var group = el('div', 'ad-stat');
                var dot = el('span', 'ad-chip__dot');
                dot.style.background = m.color;
                group.appendChild(dot);
                var primary = el('strong', null, '');
                var main = m.agg === 'avg' ? sum / vals.length : sum;
                group.appendChild(primary);
                group.appendChild(el('span', 'ad-stat__label', (m.agg === 'avg' ? 'average ' : 'total ') + m.label.toLowerCase()));
                group.appendChild(el('span', 'ad-stat__sep', '·'));
                var peakLabel = (m.reverse ? 'best ' : 'peak ') + fmtNumber(best, m.fmt, m.decimals) + (cfg.labels[bestIdx] ? ' on ' + cfg.labels[bestIdx] : '');
                group.appendChild(el('span', 'ad-stat__label', peakLabel));
                stats.appendChild(group);
                countUp(primary, main, m.fmt, m.decimals);
            });
        }

        function sync(animate) {
            var act = activeMetrics();
            metrics.forEach(function (m) {
                var on = activeKeys.indexOf(m.key) !== -1;
                chips[m.key].classList.toggle('is-active', on);
                chips[m.key].setAttribute(exclusive ? 'aria-checked' : 'aria-pressed', on ? 'true' : 'false');
            });
            renderStats();
            if (!chart) { return; }
            chart.data.datasets = act.map(datasetFor);
            var first = act[0];
            chart.options.scales.y.reverse = !!(first && first.reverse);
            chart.options.scales.y.beginAtZero = !(first && first.reverse);
            chart.options.scales.y.min = first && first.reverse ? 1 : undefined;
            chart.options.scales.y.ticks.callback = function (v) { return fmtNumber(v, first ? first.fmt : 'int', first ? first.decimals : 0); };
            chart.update(animate && !reduceMotion ? undefined : 'none');
        }

        function build() {
            var act = activeMetrics();
            var first = act[0];
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
            chart = new Chart(canvas, {
                type: 'line',
                data: { labels: cfg.labels, datasets: act.map(datasetFor) },
                plugins: [crosshair],
                options: {
                    maintainAspectRatio: false,
                    animation: reduceMotion ? false : { duration: 1000, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: { top: 6, right: 6 } },
                    scales: {
                        x: { grid: { display: false }, border: { color: GRID }, ticks: { color: MUTED, maxRotation: 0, autoSkipPadding: 18, font: { size: 11 } } },
                        y: {
                            beginAtZero: !(first && first.reverse), reverse: !!(first && first.reverse), min: first && first.reverse ? 1 : undefined,
                            grid: { color: GRID }, border: { display: false },
                            ticks: { color: MUTED, maxTicksLimit: 5, font: { size: 11 }, callback: function (v) { return fmtNumber(v, first ? first.fmt : 'int', first ? first.decimals : 0); } }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#ffffff', titleColor: MUTED, bodyColor: INK, borderColor: 'rgba(45,43,44,0.14)', borderWidth: 1,
                            padding: 10, cornerRadius: 8, displayColors: true, boxWidth: 12, boxHeight: 3, usePointStyle: false,
                            titleFont: { size: 11, weight: '600' }, bodyFont: { size: 13, weight: '700' },
                            callbacks: {
                                label: function (ctx) {
                                    var m = metrics.filter(function (x) { return x.key === ctx.dataset.key; })[0];
                                    return ' ' + fmtNumber(ctx.parsed.y, m.fmt, m.decimals) + '  ' + ctx.dataset.label;
                                }
                            }
                        }
                    }
                }
            });
        }

        sync(false);
        whenVisible(host, build);
    }

    /* ---------- ranked bars (HTML) ---------- */

    function initRank(host) {
        var cfg = readCfg(host);
        if (!cfg || !cfg.items || !cfg.items.length) { return; }
        var color = cfg.color || '#6a2036';
        var limit = cfg.limit || 8;
        var items = cfg.items;
        var max = Math.max.apply(null, items.map(function (i) { return i.value; })) || 1;
        var total = cfg.total || items.reduce(function (a, i) { return a + i.value; }, 0) || 1;
        var list = el('ol', 'ad-rank');
        host.appendChild(list);
        var expanded = false;
        var rows = [];

        items.forEach(function (item, index) {
            var li = el('li', 'ad-rank__row');
            li.tabIndex = 0;
            li.style.setProperty('--i', index);
            var rank = el('span', 'ad-rank__n', String(index + 1));
            var body = el('div', 'ad-rank__body');
            var top = el('div', 'ad-rank__top');
            var label = el('span', 'ad-rank__label', item.label);
            label.title = item.label;
            var value = el('strong', 'ad-rank__value', fmtNumber(item.value, cfg.fmt, cfg.decimals));
            top.appendChild(label);
            top.appendChild(value);
            var track = el('div', 'ad-rank__track');
            var fill = el('div', 'ad-rank__fill');
            fill.style.background = color;
            fill.dataset.w = Math.max(2, item.value / max * 100);
            fill.style.width = reduceMotion ? fill.dataset.w + '%' : '0%';
            track.appendChild(fill);
            body.appendChild(top);
            body.appendChild(track);
            if (item.sub) { body.appendChild(el('div', 'ad-rank__sub', item.sub)); }
            li.appendChild(rank);
            li.appendChild(body);
            bindTip(li, function () { return item.label; }, function () {
                var out = [{ value: fmtNumber(item.value, cfg.fmt, cfg.decimals), label: cfg.valueLabel || '', color: color }];
                if (cfg.showShare !== false) { out.push({ value: (item.value / total * 100).toFixed(1) + '%', label: 'of total' }); }
                (item.tip || []).forEach(function (t) { out.push({ value: t[1], label: t[0] }); });
                return out;
            });
            li.addEventListener('click', function () {
                rows.forEach(function (r) { r.classList.remove('is-selected'); });
                li.classList.add('is-selected');
            });
            rows.push(li);
            list.appendChild(li);
        });

        function applyLimit() {
            rows.forEach(function (li, i) { li.hidden = !expanded && i >= limit; });
        }
        applyLimit();

        if (items.length > limit) {
            var more = el('button', 'ad-link', 'Show all ' + items.length);
            more.type = 'button';
            more.addEventListener('click', function () {
                expanded = !expanded;
                more.textContent = expanded ? 'Show top ' + limit : 'Show all ' + items.length;
                applyLimit();
                if (expanded) { growBars(); }
            });
            host.appendChild(more);
        }

        function growBars() {
            rows.forEach(function (li) {
                var f = li.querySelector('.ad-rank__fill');
                f.style.transitionDelay = (reduceMotion ? 0 : parseInt(li.style.getPropertyValue('--i'), 10) * 45) + 'ms';
                f.style.width = f.dataset.w + '%';
            });
        }
        if (!reduceMotion) { whenVisible(host, growBars); }
    }

    /* ---------- split bar (HTML) ---------- */

    function initSplit(host) {
        var cfg = readCfg(host);
        if (!cfg || !cfg.parts || !cfg.parts.length) { return; }
        var parts = cfg.parts.filter(function (p) { return p.value > 0; });
        var total = parts.reduce(function (a, p) { return a + p.value; }, 0);
        if (!total) { return; }
        var bar = el('div', 'ad-split__bar');
        bar.setAttribute('role', 'img');
        bar.setAttribute('aria-label', parts.map(function (p) { return p.label + ' ' + Math.round(p.value / total * 100) + '%'; }).join(', '));
        var legend = el('ul', 'ad-split__legend');
        var segs = [];
        parts.forEach(function (p, i) {
            var pct = p.value / total * 100;
            var seg = el('div', 'ad-split__seg');
            seg.style.background = p.color;
            seg.style.flexGrow = reduceMotion ? pct : 0.0001;
            seg.dataset.g = pct;
            seg.tabIndex = 0;
            if (pct >= 12) { seg.appendChild(el('span', 'ad-split__pct', Math.round(pct) + '%')); }
            bar.appendChild(seg);
            segs.push(seg);

            var li = el('li', 'ad-split__item');
            var key = el('span', 'ad-split__key');
            key.style.background = p.color;
            li.appendChild(key);
            li.appendChild(el('span', 'ad-split__name', p.label));
            li.appendChild(el('strong', 'ad-split__num', fmtNumber(p.value, cfg.fmt, cfg.decimals)));
            li.appendChild(el('span', 'ad-split__share', pct.toFixed(1) + '%'));
            legend.appendChild(li);

            function focusOn(on) {
                segs.forEach(function (s, j) { s.classList.toggle('is-dim', on && j !== i); });
                Array.prototype.forEach.call(legend.children, function (l, j) { l.classList.toggle('is-dim', on && j !== i); });
            }
            [seg, li].forEach(function (node) {
                node.addEventListener('pointerenter', function () { focusOn(true); });
                node.addEventListener('pointerleave', function () { focusOn(false); });
            });
            bindTip(seg, function () { return p.label; }, function () {
                var out = [{ value: fmtNumber(p.value, cfg.fmt, cfg.decimals), label: cfg.valueLabel || '', color: p.color }, { value: pct.toFixed(1) + '%', label: 'share' }];
                (p.tip || []).forEach(function (t) { out.push({ value: t[1], label: t[0] }); });
                return out;
            });
        });
        host.appendChild(bar);
        host.appendChild(legend);
        if (!reduceMotion) {
            whenVisible(host, function () {
                segs.forEach(function (s, i) {
                    s.style.transitionDelay = (i * 90) + 'ms';
                    s.style.flexGrow = s.dataset.g;
                });
            });
        }
    }

    /* ---------- heatmap (HTML grid) ---------- */

    function initHeat(host) {
        var cfg = readCfg(host);
        if (!cfg || !cfg.values) { return; }
        var days = cfg.rows;
        var max = 0;
        cfg.values.forEach(function (row) { row.forEach(function (v) { if (v > max) { max = v; } }); });
        if (!max) { host.appendChild(el('p', 'analytics-empty', 'No activity recorded yet.')); return; }
        var grid = el('div', 'ad-heat');
        grid.setAttribute('role', 'img');
        grid.setAttribute('aria-label', cfg.title || 'Activity by day and hour');
        var corner = el('span', 'ad-heat__corner');
        grid.appendChild(corner);
        for (var h = 0; h < 24; h++) {
            var hl = el('span', 'ad-heat__hour', h % 3 === 0 ? String(h).padStart(2, '0') : '');
            grid.appendChild(hl);
        }
        var cells = [];
        days.forEach(function (day, d) {
            grid.appendChild(el('span', 'ad-heat__day', day));
            for (var hh = 0; hh < 24; hh++) {
                (function (dd, hr) {
                    var v = cfg.values[dd][hr];
                    var cell = el('span', 'ad-heat__cell');
                    var t = v / max;
                    if (v > 0) {
                        // one-hue sequential ramp, lightest step still clears the surface
                        cell.style.background = 'rgba(106, 32, 54, ' + (0.16 + t * 0.84).toFixed(3) + ')';
                    }
                    cell.style.setProperty('--d', (dd + hr) * 14);
                    cell.tabIndex = v > 0 ? 0 : -1;
                    if (v > 0) {
                        bindTip(cell, function () { return days[dd] + ' ' + String(hr).padStart(2, '0') + ':00'; }, function () {
                            return [{ value: fmtNumber(v, 'int'), label: cfg.valueLabel || 'events', color: '#6a2036' }];
                        });
                    }
                    cells.push(cell);
                    grid.appendChild(cell);
                })(d, hh);
            }
        });
        host.appendChild(grid);
        var scale = el('div', 'ad-heat__scale');
        scale.appendChild(el('span', null, 'Quiet'));
        var ramp = el('span', 'ad-heat__ramp');
        scale.appendChild(ramp);
        scale.appendChild(el('span', null, 'Busy · peak ' + fmtNumber(max, 'int')));
        host.appendChild(scale);
        if (!reduceMotion) {
            cells.forEach(function (c) { c.classList.add('is-armed'); });
            whenVisible(host, function () { cells.forEach(function (c) { c.classList.add('is-in'); }); });
        }
    }

    /* ---------- ring meter (SVG) ---------- */

    function initRing(host) {
        var cfg = readCfg(host);
        if (!cfg) { return; }
        var size = 112;
        var stroke = 11;
        var r = (size - stroke) / 2;
        var circ = 2 * Math.PI * r;
        var value = Math.max(0, Math.min(100, Number(cfg.value) || 0));
        var s = svg('svg', { viewBox: '0 0 ' + size + ' ' + size, class: 'ad-ring__svg', role: 'img', 'aria-label': cfg.label + ' ' + fmtNumber(value, 'pct', cfg.decimals) });
        s.appendChild(svg('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: 'rgba(106, 32, 54, 0.1)', 'stroke-width': stroke }));
        var arc = svg('circle', {
            cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: cfg.color || '#6a2036', 'stroke-width': stroke, 'stroke-linecap': 'round',
            'stroke-dasharray': circ, 'stroke-dashoffset': reduceMotion ? circ * (1 - value / 100) : circ,
            transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')', class: 'ad-ring__arc'
        });
        s.appendChild(arc);
        var wrap = el('div', 'ad-ring__fig');
        wrap.appendChild(s);
        var num = el('strong', 'ad-ring__num', fmtNumber(reduceMotion ? value : 0, 'pct', cfg.decimals));
        wrap.appendChild(num);
        var text = el('div', 'ad-ring__text');
        text.appendChild(el('div', 'ad-ring__label', cfg.label));
        if (cfg.sub) { text.appendChild(el('div', 'ad-ring__sub', cfg.sub)); }
        host.appendChild(wrap);
        host.appendChild(text);
        if (!reduceMotion) {
            whenVisible(host, function () {
                arc.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(.22,.61,.36,1)';
                arc.style.strokeDashoffset = circ * (1 - value / 100);
                countUp(num, value, 'pct', cfg.decimals);
            });
        }
    }


    /* ---------- page speed table ---------- */

    var RATING = {
        good: { icon: '✓', label: 'Good', color: '#0b7a0b' },
        ni: { icon: '!', label: 'Needs work', color: '#a86a00' },
        poor: { icon: '✕', label: 'Slow', color: '#b42323' },
        none: { icon: '–', label: 'No data', color: '#70676b' }
    };

    function speedValue(key, v) {
        if (v === null || v === undefined) { return '–'; }
        if (key === 'cls') { return Number(v).toFixed(2); }
        if (key === 'tbt') { return Math.round(v) + ' ms'; }
        return (v / 1000).toFixed(1) + ' s';
    }

    function initSpeed(host) {
        var cfg = readCfg(host);
        if (!cfg || !cfg.strategies) { return; }
        var columns = cfg.columns || [];
        var current = cfg.strategies.mobile && cfg.strategies.mobile.length ? 'mobile' : 'desktop';

        var bar = el('div', 'ad-chips');
        bar.setAttribute('role', 'radiogroup');
        bar.setAttribute('aria-label', 'Device');
        var summary = el('div', 'ad-trend__stats');
        var wrap = el('div', 'ad-speed__wrap');
        var table = el('div', 'ad-speed');
        wrap.appendChild(table);
        host.appendChild(bar);
        host.appendChild(summary);
        host.appendChild(wrap);

        var chips = {};
        [['mobile', 'On a phone (slow 4G)'], ['desktop', 'On a computer']].forEach(function (pair) {
            if (!cfg.strategies[pair[0]] || !cfg.strategies[pair[0]].length) { return; }
            var chip = el('button', 'ad-chip', pair[1]);
            chip.type = 'button';
            chip.setAttribute('role', 'radio');
            chip.addEventListener('click', function () { current = pair[0]; render(true); });
            chips[pair[0]] = chip;
            bar.appendChild(chip);
        });

        function render(animate) {
            Object.keys(chips).forEach(function (k) {
                chips[k].classList.toggle('is-active', k === current);
                chips[k].setAttribute('aria-checked', k === current ? 'true' : 'false');
            });
            var pages = cfg.strategies[current] || [];
            table.textContent = '';
            table.style.setProperty('--cols', columns.length);

            var head = el('div', 'ad-speed__row ad-speed__row--head');
            head.appendChild(el('div', 'ad-speed__page', 'Page'));
            columns.forEach(function (c) {
                var h = el('div', 'ad-speed__cell', c.label);
                h.title = c.help;
                head.appendChild(h);
            });
            head.appendChild(el('div', 'ad-speed__cell ad-speed__cell--size', 'Page size'));
            table.appendChild(head);

            var good = 0;
            var total = 0;
            pages.forEach(function (page, i) {
                var row = el('div', 'ad-speed__row' + (animate === false ? '' : ' is-in'));
                row.style.setProperty('--i', i);
                row.appendChild(el('div', 'ad-speed__page', page.label));
                columns.forEach(function (c) {
                    var r = RATING[page.ratings[c.key]] || RATING.none;
                    var cell = el('div', 'ad-speed__cell');
                    cell.tabIndex = 0;
                    cell.appendChild(el('strong', 'ad-speed__val', speedValue(c.key, page.metrics[c.key])));
                    var status = el('span', 'ad-speed__status');
                    status.style.color = r.color;
                    status.appendChild(el('span', 'ad-speed__icon', r.icon));
                    status.appendChild(document.createTextNode(' ' + r.label));
                    cell.appendChild(status);
                    bindTip(cell, function () { return c.label; }, function () {
                        return [{ value: speedValue(c.key, page.metrics[c.key]), label: r.label, color: r.color }, { value: c.good, label: 'is good' }, { value: c.poor, label: 'is slow' }];
                    });
                    if (page.ratings[c.key] === 'good') { good += 1; }
                    if (page.ratings[c.key] && page.ratings[c.key] !== 'none') { total += 1; }
                    row.appendChild(cell);
                });
                var size = el('div', 'ad-speed__cell ad-speed__cell--size');
                size.appendChild(el('strong', 'ad-speed__val', page.metrics.bytes ? (page.metrics.bytes / 1048576).toFixed(1) + ' MB' : '–'));
                size.appendChild(el('span', 'ad-speed__sub', (page.metrics.requests || 0) + ' requests'));
                row.appendChild(size);
                table.appendChild(row);
            });
            summary.textContent = '';
            var s = el('div', 'ad-stat');
            s.appendChild(el('strong', null, good + ' of ' + total));
            s.appendChild(el('span', 'ad-stat__label', 'checks are rated Good' + (cfg.checkedAt ? ' · last checked ' + cfg.checkedAt : '')));
            summary.appendChild(s);
        }
        render(false);
    }

    /* ---------- boot ---------- */

    var inits = { trend: initTrend, rank: initRank, split: initSplit, heat: initHeat, ring: initRing, speed: initSpeed };

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.ad-kpi'), initKpi);
        Array.prototype.forEach.call(document.querySelectorAll('[data-ad]'), function (node) {
            var fn = inits[node.getAttribute('data-ad')];
            if (fn) { try { fn(node); } catch (e) { if (window.console) { console.error('dashboard widget failed', e); } } }
        });
        // staggered entrance for cards and panels
        Array.prototype.forEach.call(document.querySelectorAll('.ad-reveal'), function (node, i) {
            node.style.setProperty('--ad-delay', Math.min(i, 12) * 60 + 'ms');
            if (reduceMotion) { node.classList.add('is-in'); return; }
            whenVisible(node, function () { node.classList.add('is-in'); });
        });
        // a details block that holds the old tables opens itself when a table control was used
        var tables = document.querySelector('[data-ad-tables]');
        if (tables && tables.getAttribute('data-open') === '1') { tables.open = true; }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
