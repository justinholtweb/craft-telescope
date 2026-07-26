/**
 * Telescope — control panel behaviour.
 *
 * Everything is delegated from the document and driven by a MutationObserver
 * rather than inline scripts, because Craft renders fields into element-editor
 * slideouts and the refresh button replaces a whole panel — in both cases any
 * inline <script> in the markup would never run.
 *
 * Chart.js (with the zoom plugin) and html2canvas + jsPDF are loaded by
 * TelescopeReportAsset. They are absent on screens that only show the compact
 * sidebar panel, so every use is guarded.
 */
(function () {
    'use strict';

    if (window.__telescopeBound) {
        return;
    }

    window.__telescopeBound = true;

    var COLOR_VIEWS = '#2563eb';
    var COLOR_USERS = '#0d9488';

    /* ---------------------------------------------------------------------
     * Timeline chart
     * ------------------------------------------------------------------ */

    /**
     * Draw the chart for one canvas, unless it already has one.
     */
    function initChart(canvas) {
        if (canvas.__telescopeChart || typeof Chart === 'undefined') {
            return;
        }

        var section = canvas.closest('.telescope-section');
        var dataScript = section && section.querySelector('.js-telescope-chart-data');

        if (!dataScript) {
            return;
        }

        var timeline;

        try {
            timeline = JSON.parse(dataScript.textContent);
        } catch (e) {
            return;
        }

        if (!timeline || !timeline.length) {
            return;
        }

        var resetButton = section.querySelector('.js-telescope-reset-zoom');
        var hasZoom = !!(Chart.registry && Chart.registry.plugins && safeGetPlugin('zoom'));

        var chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: timeline.map(function (point) {
                    // Parse as a local date; "2026-07-01" alone is treated as UTC
                    // and can render as the previous day west of Greenwich.
                    var parts = String(point.date).split('-');
                    var date = new Date(+parts[0], +parts[1] - 1, +parts[2]);
                    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                }),
                // Views first, so it leads the legend. Visitors is always the
                // smaller series, so drawing it second keeps it readable on top.
                datasets: [
                    dataset('Page views', timeline.map(function (p) { return p.views; }), COLOR_VIEWS),
                    dataset('Visitors', timeline.map(function (p) { return p.users; }), COLOR_USERS)
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 250 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { displayColors: true },
                    zoom: hasZoom ? {
                        zoom: {
                            wheel: { enabled: true },
                            pinch: { enabled: true },
                            drag: {
                                enabled: true,
                                backgroundColor: 'rgba(37, 99, 235, 0.10)',
                                borderColor: 'rgba(37, 99, 235, 0.40)',
                                borderWidth: 1
                            },
                            mode: 'x',
                            onZoomComplete: function () {
                                if (resetButton) {
                                    resetButton.classList.remove('hidden');
                                }
                            }
                        },
                        pan: { enabled: true, mode: 'x' }
                    } : undefined
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });

        canvas.__telescopeChart = chart;

        function resetZoom() {
            if (chart.resetZoom) {
                chart.resetZoom();
            }

            if (resetButton) {
                resetButton.classList.add('hidden');
            }
        }

        if (resetButton) {
            resetButton.addEventListener('click', resetZoom);
        }

        canvas.addEventListener('dblclick', resetZoom);
    }

    function dataset(label, data, color) {
        return {
            label: label,
            data: data,
            borderColor: color,
            backgroundColor: hexToRgba(color, 0.08),
            borderWidth: 2,
            tension: 0.35,
            fill: true,
            pointRadius: 0,
            pointHitRadius: 10,
            pointHoverRadius: 4
        };
    }

    function hexToRgba(hex, alpha) {
        var value = parseInt(hex.slice(1), 16);
        return 'rgba(' + [(value >> 16) & 255, (value >> 8) & 255, value & 255].join(', ') + ', ' + alpha + ')';
    }

    function safeGetPlugin(id) {
        try {
            return Chart.registry.plugins.get(id);
        } catch (e) {
            return null;
        }
    }

    /**
     * Draw any chart that has appeared and is not yet initialised.
     */
    function initCharts(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var canvases = scope.querySelectorAll('.js-telescope-chart');

        for (var i = 0; i < canvases.length; i++) {
            initChart(canvases[i]);
        }
    }

    // Craft inserts field HTML long after page load — in slideouts, in newly
    // revealed tabs, and when a report refreshes itself.
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;

            for (var j = 0; j < added.length; j++) {
                if (added[j].nodeType === 1) {
                    initCharts(added[j]);
                }
            }
        }
    });

    ready(function () {
        initCharts(document);
        observer.observe(document.body, { childList: true, subtree: true });
    });

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    /* ---------------------------------------------------------------------
     * SVG chart tooltips
     *
     * The server-rendered SVG chart (print view, and craft.telescope.chart()
     * in a template) carries its own hover targets.
     * ------------------------------------------------------------------ */

    document.addEventListener('mouseover', function (event) {
        var hit = event.target.closest ? event.target.closest('.telescope-chart-hit') : null;

        if (!hit) {
            return;
        }

        var wrap = hit.closest('.telescope-chart-wrap');

        if (!wrap) {
            return;
        }

        var tooltip = wrap.querySelector('.telescope-tooltip');

        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'telescope-tooltip';
            wrap.appendChild(tooltip);
        }

        tooltip.innerHTML =
            '<strong>' + escapeHtml(hit.getAttribute('data-label') || '') + '</strong>' +
            escapeHtml(hit.getAttribute('data-values') || '');

        var bounds = hit.getBoundingClientRect();
        var wrapBounds = wrap.getBoundingClientRect();

        tooltip.classList.add('is-visible');

        var left = bounds.left - wrapBounds.left + bounds.width / 2 - tooltip.offsetWidth / 2;
        left = Math.max(4, Math.min(left, wrapBounds.width - tooltip.offsetWidth - 4));

        tooltip.style.left = left + 'px';
        tooltip.style.top = Math.max(4, bounds.top - wrapBounds.top - tooltip.offsetHeight - 6) + 'px';
    });

    document.addEventListener('mouseout', function (event) {
        var hit = event.target.closest ? event.target.closest('.telescope-chart-hit') : null;

        if (!hit) {
            return;
        }

        var wrap = hit.closest('.telescope-chart-wrap');
        var tooltip = wrap ? wrap.querySelector('.telescope-tooltip') : null;

        if (tooltip) {
            tooltip.classList.remove('is-visible');
        }
    });

    /* ---------------------------------------------------------------------
     * PDF export
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.js-telescope-pdf') : null;

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();

        // The button sits inside the panel in the field, and up in the page
        // header on the standalone report screen.
        var panel = button.closest('.telescope') || document.querySelector('.telescope');

        if (!panel) {
            return;
        }

        if (typeof html2canvas === 'undefined' || !window.jspdf) {
            Craft.cp.displayError(Craft.t('telescope', 'The PDF libraries could not be loaded.'));
            return;
        }

        var originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = button.getAttribute('data-loading-label') || 'Building PDF…';

        exportPdf(panel)
            .catch(function (error) {
                console.error('[Telescope] PDF export failed', error);
                Craft.cp.displayError(Craft.t('telescope', 'Could not build the PDF.'));
            })
            .then(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
    });

    /**
     * Render the report to a PDF.
     *
     * html2canvas clones the entire document on every call, so capturing each
     * block separately costs several seconds apiece. Instead the whole panel is
     * captured once and the blocks are cropped out of that bitmap — same
     * page-break behaviour (a block either fits on the current page or starts
     * the next one, so nothing is ever sliced in half), a fraction of the time.
     */
    function exportPdf(panel) {
        var pdf = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: 'portrait' });

        var pageWidth = pdf.internal.pageSize.getWidth();
        var pageHeight = pdf.internal.pageSize.getHeight();
        var margin = 36;
        var usableWidth = pageWidth - margin * 2;
        var usableHeight = pageHeight - margin;

        // Chrome refuses canvases beyond roughly 16k pixels a side, and a very
        // long report plus a 2× scale can get there.
        var scale = Math.min(2, 8000 / Math.max(panel.getBoundingClientRect().height, 1));

        // Measured on the clone, not the live panel: hiding the on-screen-only
        // controls changes the layout, so offsets taken from the live DOM would
        // be off by their height and crop the tops off sections.
        var blocks = [];
        var charts = null;

        return swapChartsForImages(panel).then(function (swapped) {
            charts = swapped;

            return html2canvas(panel, {
                backgroundColor: '#ffffff',
                scale: scale,
                logging: false,
                useCORS: true,
                onclone: function (doc, element) {
                    // Restyle first, measure last: the class drops the
                    // breakdown tables to two columns, which changes every
                    // offset below them.
                    element.classList.add('telescope-pdf-capture');
                    hideScreenOnly(element);
                    flattenColors(element);
                    blocks = measureBlocks(element);
                }
            });
        }).then(function (full) {
            if (charts) {
                charts.restore();
            }

            var cursor = drawHeader(pdf, panel, margin, usableWidth);

            // Canvas pixels per PDF point, so a block's height can be measured
            // in the units the page is laid out in.
            var pointsPerPixel = usableWidth / full.width;

            blocks.forEach(function (block) {
                var top = Math.max(0, Math.round(block.top * scale));
                var remaining = Math.round(block.height * scale);

                while (remaining > 0) {
                    var available = usableHeight - cursor;

                    // A block taller than a whole page has to be split; give it
                    // a full fresh page before doing so.
                    if (available < 60) {
                        pdf.addPage();
                        cursor = margin;
                        available = usableHeight - cursor;
                    }

                    var takeable = Math.floor(available / pointsPerPixel);

                    if (remaining > takeable && remaining * pointsPerPixel <= usableHeight - margin) {
                        // It would fit on a page of its own — start one rather
                        // than cut it in half.
                        pdf.addPage();
                        cursor = margin;
                        takeable = Math.floor((usableHeight - cursor) / pointsPerPixel);
                    }

                    var take = Math.min(remaining, takeable);
                    var slice = crop(full, top, take);

                    if (!slice) {
                        break;
                    }

                    // JPEG rather than PNG: a lossless screenshot of a whole
                    // report runs to double-digit megabytes, which is not a file
                    // anyone wants to email to a client.
                    var height = slice.height * pointsPerPixel;
                    pdf.addImage(slice.toDataURL('image/jpeg', 0.92), 'JPEG', margin, cursor, usableWidth, height);

                    cursor += height + 14;
                    top += take;
                    remaining -= take;
                }
            });

            pdf.save(fileName(panel));
        }).catch(function (error) {
            // Never leave the panel showing a still image of its own chart.
            if (charts) {
                charts.restore();
            }

            throw error;
        });
    }

    /**
     * Drop the controls that only make sense on screen.
     */
    function hideScreenOnly(root) {
        var hidden = root.querySelectorAll('.telescope-no-print, .telescope-header');

        for (var i = 0; i < hidden.length; i++) {
            hidden[i].style.display = 'none';
        }
    }

    /**
     * Turn the server-rendered SVG chart into a PNG data URL.
     *
     * Two things are being routed around here, both in libraries the plugin
     * does not control:
     *
     * - Reading a Chart.js canvas back with `toDataURL()` yields a blank bitmap
     *   whenever the browser keeps that canvas on the GPU.
     * - html2canvas 1.4.1 does not rasterise SVG, whether inline or as an
     *   `<img>` source.
     *
     * Drawing the SVG into a canvas we own, and handing html2canvas the
     * resulting PNG, avoids both. The SVG is produced by the same code as the
     * print view, so the PDF and the printed page show an identical chart.
     *
     * @return {Promise<string|null>}
     */
    function rasterizeChart(canvas) {
        var section = canvas.closest('.telescope-section');
        var holder = section && section.querySelector('.js-telescope-chart-svg');
        var markup = null;

        if (holder) {
            try {
                markup = JSON.parse(holder.textContent);
            } catch (e) {
                markup = null;
            }
        }

        if (!markup) {
            // No SVG to work from; the canvas is the only source left.
            try {
                return Promise.resolve(canvas.toDataURL('image/png'));
            } catch (e) {
                return Promise.resolve(null);
            }
        }

        var rect = canvas.getBoundingClientRect();

        return new Promise(function (resolve) {
            var image = new Image();

            image.onload = function () {
                var target = document.createElement('canvas');
                target.width = Math.max(1, Math.round(rect.width * 2));
                target.height = Math.max(1, Math.round(rect.height * 2));

                var ctx = target.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, target.width, target.height);
                ctx.drawImage(image, 0, 0, target.width, target.height);

                try {
                    resolve(target.toDataURL('image/png'));
                } catch (e) {
                    resolve(null);
                }
            };

            image.onerror = function () {
                resolve(null);
            };

            image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
        });
    }

    /**
     * Stand a PNG of each chart in front of its canvas, and hand back a
     * function that puts things back. The image is drawn from the same data, so
     * the swap is invisible.
     *
     * @return {Promise<{restore: function}>}
     */
    function swapChartsForImages(panel) {
        var canvases = Array.prototype.slice.call(panel.querySelectorAll('canvas'));
        var restores = [];

        return Promise.all(canvases.map(rasterizeChart)).then(function (sources) {
            canvases.forEach(function (canvas, index) {
                if (!sources[index]) {
                    return;
                }

                var rect = canvas.getBoundingClientRect();
                var image = document.createElement('img');

                image.src = sources[index];
                image.className = 'telescope-chart-image';
                image.style.display = 'block';
                image.style.width = rect.width + 'px';
                image.style.height = rect.height + 'px';

                canvas.parentNode.insertBefore(image, canvas);
                canvas.style.display = 'none';

                restores.push(function () {
                    image.remove();
                    canvas.style.display = '';
                });
            });

            return {
                // Idempotent, so both the success and the failure path can call it.
                restore: function () {
                    restores.splice(0).forEach(function (restore) {
                        restore();
                    });
                }
            };
        });
    }

    /**
     * The top and height of each full-width row, relative to the panel.
     *
     * Only direct children are measured: the breakdown tables sit side by side
     * in a grid, so capturing each of them would crop the same horizontal band
     * four times over — the grid as a whole is the row.
     *
     * @return {Array<{top: number, height: number}>}
     */
    function measureBlocks(panel) {
        var panelTop = panel.getBoundingClientRect().top;
        var blocks = [];

        for (var i = 0; i < panel.children.length; i++) {
            var rect = panel.children[i].getBoundingClientRect();

            if (rect.height > 1) {
                // A few pixels of bleed: a bounding box measured before the
                // clone has fully settled comes up marginally short, which
                // shears the descenders off the last line of every block.
                // Blocks are at least 14px apart, so the bleed only ever picks
                // up whitespace — and crop() clamps it at the bottom edge.
                blocks.push({ top: rect.top - panelTop, height: rect.height + 6 });
            }
        }

        return blocks.length ? blocks : [{ top: 0, height: panel.getBoundingClientRect().height }];
    }

    /**
     * Cut a horizontal band out of a canvas.
     */
    function crop(source, top, height) {
        height = Math.min(height, source.height - top);

        if (height <= 0 || source.width <= 0) {
            return null;
        }

        var slice = document.createElement('canvas');
        slice.width = source.width;
        slice.height = height;

        var ctx = slice.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, slice.width, slice.height);
        ctx.drawImage(source, 0, top, source.width, height, 0, 0, source.width, height);

        return slice;
    }

    var COLOR_PROPERTIES = [
        'color',
        'backgroundColor',
        'borderTopColor',
        'borderRightColor',
        'borderBottomColor',
        'borderLeftColor',
        'outlineColor',
        'textDecorationColor',
        'fill',
        'stroke'
    ];

    /**
     * Rewrite every colour in a subtree as plain rgba().
     *
     * html2canvas 1.4.1 predates the CSS Color 4 `color()` / `oklch()` syntax
     * and throws on it — and Craft 5's control panel theme is full of
     * `color(srgb …)`. Painting each value onto a 1×1 canvas and reading the
     * pixel back converts anything the browser can parse, whatever the syntax,
     * so this keeps working as Craft's theme evolves.
     */
    function flattenColors(root) {
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;

        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var cache = {};
        var elements = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));

        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];

            if (element.nodeName === 'CANVAS') {
                continue;
            }

            var computed = window.getComputedStyle(element);

            for (var j = 0; j < COLOR_PROPERTIES.length; j++) {
                var property = COLOR_PROPERTIES[j];
                var value = computed[property];

                if (!value || !needsFlattening(value)) {
                    continue;
                }

                if (!(value in cache)) {
                    cache[value] = toRgba(ctx, value);
                }

                if (cache[value]) {
                    element.style[property] = cache[value];
                }
            }
        }
    }

    function needsFlattening(value) {
        return /(^|\s|\()(color|oklch|oklab|lab|lch|hwb)\(/.test(value);
    }

    function toRgba(ctx, value) {
        try {
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = value;
            ctx.fillRect(0, 0, 1, 1);

            var pixel = ctx.getImageData(0, 0, 1, 1).data;

            return 'rgba(' + pixel[0] + ', ' + pixel[1] + ', ' + pixel[2] + ', ' + (pixel[3] / 255) + ')';
        } catch (e) {
            return null;
        }
    }

    function drawHeader(pdf, panel, margin, usableWidth) {
        var title = panel.getAttribute('data-title') || 'Page analytics';
        var url = panel.getAttribute('data-url') || '';
        var siteName = panel.getAttribute('data-site-name') || '';
        var period = panel.getAttribute('data-period-label') || '';
        var generated = panel.getAttribute('data-generated') || '';

        var cursor = margin + 6;

        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(16);
        pdf.setTextColor(17, 24, 39);
        pdf.text(pdf.splitTextToSize(title, usableWidth)[0], margin, cursor);

        cursor += 16;

        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(9);
        pdf.setTextColor(107, 114, 128);

        if (siteName || url) {
            pdf.text(pdf.splitTextToSize([siteName, url].filter(Boolean).join(' · '), usableWidth)[0], margin, cursor);
            cursor += 12;
        }

        pdf.text([period, generated].filter(Boolean).join(' · '), margin, cursor);
        cursor += 10;

        pdf.setDrawColor(17, 24, 39);
        pdf.setLineWidth(1);
        pdf.line(margin, cursor, margin + usableWidth, cursor);

        return cursor + 18;
    }

    function fileName(panel) {
        var title = (panel.getAttribute('data-title') || 'analytics')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'analytics';

        return 'analytics-' + title + '.pdf';
    }

    /* ---------------------------------------------------------------------
     * Period and site switchers
     * ------------------------------------------------------------------ */

    document.addEventListener('change', function (event) {
        var select = event.target;

        if (!select.classList) {
            return;
        }

        var param = select.classList.contains('js-telescope-period') ? 'period'
            : select.classList.contains('js-telescope-site') ? 'siteId'
                : null;

        if (!param) {
            return;
        }

        var url = new URL(window.location.href);
        url.searchParams.set(param, select.value);
        window.location.href = url.toString();
    });

    /* ---------------------------------------------------------------------
     * Refresh
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.js-telescope-refresh') : null;

        if (!button) {
            return;
        }

        event.preventDefault();

        var panel = button.closest('.telescope');

        if (!panel || button.disabled) {
            return;
        }

        var originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = button.getAttribute('data-loading-label') || 'Refreshing…';

        var data = {
            elementId: panel.getAttribute('data-element-id'),
            period: panel.getAttribute('data-period') || ''
        };

        Craft.sendActionRequest('POST', 'telescope/reports/refresh', { data: data })
            .then(function (response) {
                if (response.data && response.data.html) {
                    // The replacement is our own server-rendered markup; the
                    // MutationObserver above redraws its chart.
                    panel.outerHTML = response.data.html;
                }
            })
            .catch(function (error) {
                var message = (error.response && error.response.data && error.response.data.message) ||
                    'Could not refresh the analytics report.';
                Craft.cp.displayError(message);
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
    });

    /* ---------------------------------------------------------------------
     * Settings screen
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.js-telescope-test') : null;

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();

        var output = document.querySelector('.js-telescope-test-result');
        var originalLabel = button.textContent;

        button.disabled = true;
        button.textContent = 'Testing…';

        if (output) {
            output.textContent = '';
            output.className = 'js-telescope-test-result telescope-notice';
        }

        Craft.sendActionRequest('POST', 'telescope/reports/test-connection')
            .then(function (response) {
                var result = response.data || {};
                render(result.ok, result.message, result.checks || []);
            })
            .catch(function (error) {
                var data = (error.response && error.response.data) || {};
                render(false, data.message || 'The connection test failed.', data.checks || []);
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });

        function render(ok, message, checks) {
            if (!output) {
                (ok ? Craft.cp.displayNotice : Craft.cp.displayError)(message);
                return;
            }

            var lines = checks.map(function (check) {
                return '<li>' + escapeHtml(check) + '</li>';
            }).join('');

            output.className = 'js-telescope-test-result telescope-notice' + (ok ? '' : ' telescope-notice-error');
            output.innerHTML = '<strong>' + escapeHtml(message) + '</strong>' +
                (lines ? '<ul>' + lines + '</ul>' : '');
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.js-telescope-clear-cache') : null;

        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        button.disabled = true;

        Craft.sendActionRequest('POST', 'telescope/reports/clear-cache')
            .then(function () {
                Craft.cp.displayNotice(Craft.t('telescope', 'Analytics cache cleared.'));
            })
            .catch(function () {
                Craft.cp.displayError(Craft.t('telescope', 'Could not clear the analytics cache.'));
            })
            .finally(function () {
                button.disabled = false;
            });
    });

    function escapeHtml(value) {
        var element = document.createElement('span');
        element.textContent = value;
        return element.innerHTML;
    }
})();
