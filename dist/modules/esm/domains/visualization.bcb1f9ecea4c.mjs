export const serviceNames = Object.freeze(['visualization']);
export const dependencies = Object.freeze(['radarMotion', 'radarController']);

function factory(window, deps, provided) {
    void deps;
    provided.visualization = Object.freeze({
        create(context) {
            const {
                state, $, $$, clamp, appLocale, capitalize, meteonexaText,
                escapeHTML, temperature, unitLabel, formatClock,
                currentHourlyIndex, t, currentResolvedCondition,
                localSeriesIndex, drawHistoryChart
            } = context;
            if (!state || typeof $ !== 'function' || typeof $$ !== 'function') {
                throw new Error('METEONEXA_VISUALIZATION_CONTEXT_INVALID');
            }
        function canvasSetup(canvas) {
            if (!canvas || canvas.hidden || canvas.getClientRects().length === 0)
                return null;
            const rect = canvas.getBoundingClientRect();
            const width = Math.max(1, Math.round(rect.width));
            const height = Math.max(1, Math.round(rect.height));
            if (width < 8 || height < 8)
                return null;
            const ratio = Math.min(window.devicePixelRatio || 1, 1.75);
            const pixelWidth = Math.max(1, Math.round(width * ratio));
            const pixelHeight = Math.max(1, Math.round(height * ratio));
            if (canvas.width !== pixelWidth || canvas.height !== pixelHeight) {
                canvas.width = pixelWidth;
                canvas.height = pixelHeight;
            }
            const ctx = canvas.getContext('2d');
            if (!ctx)
                return null;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            return { ctx, width, height, ratio };
        }
        function formatChartTooltipTime(value) {
            const date = new Date(value);
            if (Number.isNaN(date.getTime()))
                return String(value || '');
            return capitalize(new Intl.DateTimeFormat(appLocale(), {
                weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
            }).format(date));
        }
        function ensureChartTooltip() {
            let tooltip = $('#chart-tooltip');
            if (tooltip)
                return tooltip;
            tooltip = document.createElement('div');
            tooltip.id = 'chart-tooltip';
            tooltip.className = 'chart-tooltip';
            tooltip.setAttribute('role', 'status');
            tooltip.setAttribute('aria-live', 'polite');
            tooltip.hidden = true;
            document.body.appendChild(tooltip);
            return tooltip;
        }
        function chartValueText(series, value) {
            const numeric = Number(value);
            if (!Number.isFinite(numeric))
                return '--';
            const digits = Number.isInteger(series.digits) ? series.digits : (Math.abs(numeric) < 10 ? 1 : 0);
            const formatted = numeric.toLocaleString(appLocale(), { maximumFractionDigits: digits, minimumFractionDigits: digits });
            return `${formatted}${series.unit || ''}`;
        }
        function chartTooltipViewport() {
            const viewport = window.visualViewport;
            return {
                left: Number(viewport?.offsetLeft) || 0,
                top: Number(viewport?.offsetTop) || 0,
                width: Number(viewport?.width) || window.innerWidth || document.documentElement.clientWidth || 320,
                height: Number(viewport?.height) || window.innerHeight || document.documentElement.clientHeight || 320
            };
        }
        function positionChartTooltip(tooltip, anchorX, anchorY) {
            const margin = 14;
            const gap = 16;
            const viewport = chartTooltipViewport();
            const minX = viewport.left + margin;
            const minY = viewport.top + margin;
            const maxX = viewport.left + viewport.width - margin;
            const maxY = viewport.top + viewport.height - margin;
            const safeX = Number.isFinite(Number(anchorX)) ? Number(anchorX) : viewport.left + viewport.width / 2;
            const safeY = Number.isFinite(Number(anchorY)) ? Number(anchorY) : viewport.top + viewport.height / 2;

            // Measure off-screen while hidden from view. This avoids Firefox painting
            // one frame at 0,0 before the final coordinates are assigned.
            tooltip.hidden = false;
            tooltip.style.visibility = 'hidden';
            tooltip.style.left = '-10000px';
            tooltip.style.top = '-10000px';
            const rect = tooltip.getBoundingClientRect();

            let left = safeX + gap;
            if (left + rect.width > maxX)
                left = safeX - rect.width - gap;
            let top = safeY - rect.height - gap;
            if (top < minY)
                top = safeY + gap;

            left = clamp(left, minX, Math.max(minX, maxX - rect.width));
            top = clamp(top, minY, Math.max(minY, maxY - rect.height));
            tooltip.style.left = `${Math.round(left)}px`;
            tooltip.style.top = `${Math.round(top)}px`;
            tooltip.style.visibility = 'visible';
        }
        function chartTooltipAnchor(canvas, index, meta, event) {
            const rect = canvas.getBoundingClientRect();
            const pad = meta.pad || { left: 0, right: 0, top: 0, bottom: 0 };
            const chartWidth = Math.max(1, rect.width - (pad.left || 0) - (pad.right || 0));
            const count = Math.max(1, meta.labels?.length || 1);
            const x = rect.left + (pad.left || 0) + (index / Math.max(1, count - 1)) * chartWidth;
            const minY = rect.top + (pad.top || 0);
            const maxY = rect.bottom - (pad.bottom || 0);
            const rawY = Number(event?.clientY ?? event?.touches?.[0]?.clientY);
            const y = Number.isFinite(rawY) && rawY >= rect.top - 1 && rawY <= rect.bottom + 1
                ? clamp(rawY, minY, Math.max(minY, maxY))
                : minY + Math.max(1, maxY - minY) / 2;
            return { x, y };
        }
        function removeChartSelection(canvas) {
            canvas?.parentElement?.querySelector?.('.chart-selection-marker')?.remove();
        }
        function showChartSelection(canvas, index, meta, clientY) {
            const parent = canvas.parentElement;
            if (!parent)
                return;
            parent.style.position = 'relative';
            let marker = parent.querySelector('.chart-selection-marker');
            if (!marker) {
                marker = document.createElement('span');
                marker.className = 'chart-selection-marker';
                marker.innerHTML = '<i></i>';
                parent.appendChild(marker);
            }
            const canvasRect = canvas.getBoundingClientRect();
            const parentRect = parent.getBoundingClientRect();
            const pad = meta.pad || { left: 0, right: 0, top: 0, bottom: 0 };
            const chartWidth = Math.max(1, canvasRect.width - pad.left - pad.right);
            const x = canvasRect.left - parentRect.left + pad.left + (index / Math.max(1, meta.labels.length - 1)) * chartWidth;
            const top = canvasRect.top - parentRect.top + pad.top;
            const height = Math.max(20, canvasRect.height - pad.top - pad.bottom);
            marker.style.left = `${Math.round(x)}px`;
            marker.style.top = `${Math.round(top)}px`;
            marker.style.height = `${Math.round(height)}px`;
            const dot = $('i', marker);
            if (dot) {
                const localY = clamp((Number(clientY) || canvasRect.top + canvasRect.height / 2) - canvasRect.top - pad.top, 0, height);
                dot.style.top = `${Math.round(localY)}px`;
            }
        }
        function ensureChartInteractionLayer(canvas) {
            if (!canvas?.parentElement)
                return canvas;
            const parent = canvas.parentElement;
            parent.style.position = 'relative';
            let layer = canvas._chartHitLayer;
            if (!layer || !layer.isConnected) {
                layer = document.createElement('span');
                layer.className = 'chart-interaction-layer';
                layer.setAttribute('aria-hidden', 'false');
                parent.appendChild(layer);
                canvas._chartHitLayer = layer;
            }
            const sync = () => {
                if (!canvas.isConnected || !layer.isConnected) return;
                const canvasRect = canvas.getBoundingClientRect();
                const parentRect = parent.getBoundingClientRect();
                layer.style.left = `${Math.round(canvasRect.left - parentRect.left + parent.scrollLeft)}px`;
                layer.style.top = `${Math.round(canvasRect.top - parentRect.top + parent.scrollTop)}px`;
                layer.style.width = `${Math.max(1, Math.round(canvasRect.width))}px`;
                layer.style.height = `${Math.max(1, Math.round(canvasRect.height))}px`;
            };
            sync();
            if (!canvas._chartHitResizeObserver && 'ResizeObserver' in window) {
                canvas._chartHitResizeObserver = new ResizeObserver(sync);
                canvas._chartHitResizeObserver.observe(canvas);
                canvas._chartHitResizeObserver.observe(parent);
            }
            canvas._chartSyncHitLayer = sync;
            return layer;
        }
        let pinnedChartCanvas = null;
        function hideChartTooltip() {
            const tooltip = $('#chart-tooltip');
            if (tooltip)
                tooltip.hidden = true;
            $$('.chart-hovering').forEach(canvas => {
                clearTimeout(canvas._chartTooltipTimer);
                canvas._chartTooltipPinned = false;
                canvas.classList.remove('chart-hovering');
                removeChartSelection(canvas);
            });
            pinnedChartCanvas = null;
        }
        function unpinOtherChart(canvas) {
            if (!pinnedChartCanvas || pinnedChartCanvas === canvas) return;
            pinnedChartCanvas._chartTooltipPinned = false;
            pinnedChartCanvas.classList.remove('chart-hovering');
            removeChartSelection(pinnedChartCanvas);
            pinnedChartCanvas = null;
        }
        const dismissChartTooltipOnViewportMove = () => hideChartTooltip();
        window.addEventListener('scroll', dismissChartTooltipOnViewportMove, { capture: true, passive: true });
        window.addEventListener('resize', dismissChartTooltipOnViewportMove, { passive: true });
        window.addEventListener('orientationchange', dismissChartTooltipOnViewportMove, { passive: true });
        document.addEventListener('touchmove', dismissChartTooltipOnViewportMove, { capture: true, passive: true });
        document.addEventListener('wheel', dismissChartTooltipOnViewportMove, { capture: true, passive: true });
        window.visualViewport?.addEventListener('scroll', dismissChartTooltipOnViewportMove, { passive: true });
        window.visualViewport?.addEventListener('resize', dismissChartTooltipOnViewportMove, { passive: true });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible')
                hideChartTooltip();
        });
        document.addEventListener('pointerdown', event => {
            if (!pinnedChartCanvas) return;
            if (event.target === pinnedChartCanvas || event.target === pinnedChartCanvas._chartHitLayer) return;
            hideChartTooltip();
        }, { capture: true });
        document.addEventListener('mousedown', event => {
            if (!pinnedChartCanvas) return;
            if (event.target === pinnedChartCanvas || event.target === pinnedChartCanvas._chartHitLayer) return;
            hideChartTooltip();
        }, { capture: true });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && (pinnedChartCanvas || !$('#chart-tooltip')?.hidden))
                hideChartTooltip();
        });
        function registerChartInteraction(canvas, meta) {
            if (!canvas || !meta?.labels?.length || !meta?.series?.length)
                return;
            canvas._chartMeta = meta;
            canvas.style.cursor = 'crosshair';
            canvas.setAttribute('role', 'presentation');
            canvas.setAttribute('aria-hidden', 'true');

            const hit = ensureChartInteractionLayer(canvas);
            if (!hit)
                return;
            canvas._chartSyncHitLayer?.();
            hit.style.cursor = 'crosshair';
            hit.tabIndex = 0;
            hit.setAttribute('role', 'img');
            hit.setAttribute('aria-label', t('visualization.value_tap_hover_over_points_read_values', { title: t(meta.title || meteonexaText('visualization.registerchartinteraction.weather_chart')) }));

            if (hit._chartInteractionReady)
                return;
            hit._chartInteractionReady = true;
            canvas._chartSelectedIndex = 0;

            const show = (event, { pin = false } = {}) => {
                const current = canvas._chartMeta;
                if (!current?.labels?.length)
                    return;
                if (!pin && pinnedChartCanvas && pinnedChartCanvas !== canvas)
                    return;
                const rect = canvas.getBoundingClientRect();
                const pad = current.pad || { left: 0, right: 0 };
                const chartWidth = Math.max(1, rect.width - pad.left - pad.right);
                const clientX = Number(event?.clientX ?? event?.touches?.[0]?.clientX ?? rect.left + pad.left);
                const clientY = Number(event?.clientY ?? event?.touches?.[0]?.clientY ?? rect.top + rect.height / 2);
                const relativeX = clamp(clientX - rect.left - pad.left, 0, chartWidth);
                const index = Number.isInteger(event?.chartIndex)
                    ? clamp(event.chartIndex, 0, current.labels.length - 1)
                    : Math.round(relativeX / chartWidth * Math.max(0, current.labels.length - 1));
                canvas._chartSelectedIndex = index;
                const tooltip = ensureChartTooltip();
                const rows = current.series.map(series => {
                    const value = series.values?.[index];
                    return `<span class="chart-tooltip-row"><i style="--series-color:${escapeHTML(series.color || '#6fdcff')}"></i><b>${escapeHTML(series.label || meteonexaText('visualization.show.value'))}</b><strong>${escapeHTML(chartValueText(series, value))}</strong></span>`;
                }).join('');
                const tooltipLabel = typeof current.labelFormatter === 'function'
                    ? current.labelFormatter(current.labels[index], index)
                    : formatChartTooltipTime(current.labels[index]);
                tooltip.innerHTML = `<small>${escapeHTML(current.title || meteonexaText('visualization.show.chart_details'))}</small><time>${escapeHTML(tooltipLabel)}</time>${rows}`;
                if (pin) {
                    unpinOtherChart(canvas);
                    pinnedChartCanvas = canvas;
                    canvas._chartTooltipPinned = true;
                }
                canvas.classList.add('chart-hovering');
                showChartSelection(canvas, index, current, clientY);
                const anchor = chartTooltipAnchor(canvas, index, current, event);
                positionChartTooltip(tooltip, anchor.x, anchor.y);
            };

            let touchStart = null;
            let moveFrame = 0;
            let latestMove = null;
            let suppressClickUntil = 0;
            const queueHover = event => {
                const pointerType = String(event?.pointerType || '');
                if (pointerType === 'touch' || canvas._chartTooltipPinned)
                    return;
                latestMove = { clientX: event.clientX, clientY: event.clientY };
                if (moveFrame) return;
                moveFrame = requestAnimationFrame(() => {
                    moveFrame = 0;
                    if (latestMove && !canvas._chartTooltipPinned)
                        show(latestMove);
                    latestMove = null;
                });
            };
            const clearTransient = () => {
                if (canvas._chartTooltipPinned) return;
                if (moveFrame) cancelAnimationFrame(moveFrame);
                moveFrame = 0;
                latestMove = null;
                const tooltip = $('#chart-tooltip');
                if (tooltip) tooltip.hidden = true;
                canvas.classList.remove('chart-hovering');
                removeChartSelection(canvas);
            };

            hit.addEventListener('pointerdown', event => {
                if (event.pointerType === 'touch')
                    touchStart = { x: event.clientX, y: event.clientY };
            });
            hit.addEventListener('pointermove', queueHover, { passive: true });
            // Firefox occasionally drops PointerEvent movement over accelerated canvas
            // compositing layers. The transparent hit layer plus a MouseEvent fallback
            // makes hover deterministic without depending on canvas event dispatch.
            hit.addEventListener('mousemove', queueHover, { passive: true });
            hit.addEventListener('mouseenter', queueHover, { passive: true });
            hit.addEventListener('pointerup', event => {
                if (event.pointerType !== 'touch')
                    return;
                const distance = touchStart ? Math.hypot(event.clientX - touchStart.x, event.clientY - touchStart.y) : 0;
                touchStart = null;
                if (distance <= 12) {
                    suppressClickUntil = Date.now() + 700;
                    event.preventDefault();
                    show(event, { pin: true });
                }
            });
            hit.addEventListener('click', event => {
                if (Date.now() < suppressClickUntil) return;
                show(event, { pin: true });
            });
            hit.addEventListener('pointerleave', event => {
                if (event.pointerType !== 'touch')
                    clearTransient();
            });
            hit.addEventListener('mouseleave', clearTransient);
            hit.addEventListener('pointercancel', () => {
                touchStart = null;
                clearTransient();
            });
            hit.addEventListener('blur', clearTransient);
            hit.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter', ' ', 'Escape'].includes(event.key))
                    return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    hideChartTooltip();
                    return;
                }
                event.preventDefault();
                const count = canvas._chartMeta?.labels?.length || 1;
                if (event.key === 'ArrowLeft') canvas._chartSelectedIndex = Math.max(0, canvas._chartSelectedIndex - 1);
                if (event.key === 'ArrowRight') canvas._chartSelectedIndex = Math.min(count - 1, canvas._chartSelectedIndex + 1);
                if (event.key === 'Home') canvas._chartSelectedIndex = 0;
                if (event.key === 'End') canvas._chartSelectedIndex = count - 1;
                const rect = canvas.getBoundingClientRect();
                const pad = canvas._chartMeta.pad || { left: 0, right: 0 };
                const x = rect.left + pad.left + canvas._chartSelectedIndex / Math.max(1, count - 1) * Math.max(1, rect.width - pad.left - pad.right);
                show({ clientX: x, clientY: rect.top + rect.height / 2, chartIndex: canvas._chartSelectedIndex, type: 'keyboard' }, { pin: event.key === 'Enter' || event.key === ' ' });
            });
        }
        function drawChartAxisTitle(ctx, text, x, y, { rotate = 0, align = 'center' } = {}) {
            if (!text) return;
            ctx.save();
            ctx.translate(x, y);
            if (rotate) ctx.rotate(rotate);
            ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--muted').trim() || '#7890ad';
            ctx.font = '700 10px Inter, system-ui, sans-serif';
            ctx.textAlign = align;
            ctx.textBaseline = 'middle';
            ctx.globalAlpha = .92;
            ctx.fillText(text, 0, 0);
            ctx.restore();
        }
        function chartUnitAxisLabel(label, unit) {
            const cleanLabel = String(label || '').trim();
            const cleanUnit = String(unit || '').trim();
            return cleanUnit ? `${cleanLabel} (${cleanUnit})` : cleanLabel;
        }
        function schedulePrimaryChartMotion(canvas, redraw) {
            if (!canvas || state.settings.reduceMotion)
                return;
            if (canvas._primaryMotionRaf)
                cancelAnimationFrame(canvas._primaryMotionRaf);
            const startedAt = performance.now();
            const maxDuration = 6500;
            let lastPaintAt = 0;
            const loop = now => {
                if (!canvas.isConnected || document.hidden) {
                    canvas._primaryMotionRaf = null;
                    return;
                }
                const elapsed = now - startedAt;
                if (elapsed > maxDuration) {
                    canvas._primaryMotionRaf = null;
                    return;
                }
                if (now - lastPaintAt >= 50) {
                    lastPaintAt = now;
                    redraw(elapsed);
                }
                canvas._primaryMotionRaf = requestAnimationFrame(loop);
            };
            canvas._primaryMotionRaf = requestAnimationFrame(loop);
        }
        function drawChart(canvas, hours, detailed = false, motionElapsed = null) {
            const setup = canvasSetup(canvas);
            if (!setup || !state.weather)
                return;
            const { ctx, width, height } = setup;
            const data = state.weather;
            const start = currentHourlyIndex(data);
            const count = Math.min(hours, data.hourly.time.length - start);
            const temperatures = data.hourly.temperature_2m.slice(start, start + count).map(convertTemp);
            const feels = data.hourly.apparent_temperature.slice(start, start + count).map(convertTemp);
            const rain = data.hourly.precipitation_probability.slice(start, start + count).map(Number);
            const labels = data.hourly.time.slice(start, start + count);
            const pad = detailed ? { left: 58, right: 54, top: 27, bottom: 48 } : { left: 52, right: 50, top: 22, bottom: 44 };
            const chartWidth = width - pad.left - pad.right;
            const chartHeight = height - pad.top - pad.bottom;
            const minTemp = Math.floor(Math.min(...temperatures, ...(detailed ? feels : temperatures)) - 2);
            const maxTemp = Math.ceil(Math.max(...temperatures, ...(detailed ? feels : temperatures)) + 2);
            const x = index => pad.left + index / Math.max(1, count - 1) * chartWidth;
            const yTemp = value => pad.top + (maxTemp - value) / Math.max(1, maxTemp - minTemp) * chartHeight;
            const yRain = value => pad.top + chartHeight - value / 100 * chartHeight;
            ctx.clearRect(0, 0, width, height);
            const css = getComputedStyle(document.body);
            const gridColor = document.body.dataset.theme === 'light' ? 'rgba(27,83,142,.10)' : 'rgba(146,194,255,.08)';
            const textColor = css.getPropertyValue('--muted').trim() || '#7189a6';
            ctx.font = '11px Inter, system-ui, sans-serif';
            ctx.textBaseline = 'middle';
            for (let line = 0; line <= 4; line += 1) {
                const y = pad.top + line / 4 * chartHeight;
                ctx.strokeStyle = gridColor;
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(width - pad.right, y);
                ctx.stroke();
                const value = Math.round(maxTemp - line / 4 * (maxTemp - minTemp));
                ctx.fillStyle = textColor;
                ctx.textAlign = 'right';
                ctx.fillText(`${value}°`, pad.left - 8, y);
            }
            // Secondary Y axis: rain probability. Keeping it explicit prevents the
            // precipitation bars from being visually confused with temperature.
            ctx.font = '600 10px Inter, system-ui, sans-serif';
            ctx.fillStyle = textColor;
            ctx.textAlign = 'left';
            for (let row = 0; row <= 4; row += 1) {
                const y = pad.top + row / 4 * chartHeight;
                ctx.fillText(`${Math.round(100 - row * 25)}%`, width - pad.right + 7, y);
            }
            drawChartAxisTitle(ctx, chartUnitAxisLabel(t('history.yrain.temperature'), unitLabel()), 11, pad.top + chartHeight / 2, { rotate: -Math.PI / 2 });
            drawChartAxisTitle(ctx, chartUnitAxisLabel(t('visualization.yrain.rain_probability'), '%'), width - 11, pad.top + chartHeight / 2, { rotate: Math.PI / 2 });
            drawChartAxisTitle(ctx, t('app.currentsharepayload.time'), pad.left + chartWidth / 2, height - 7);
            const barWidth = Math.max(2, chartWidth / count * .42);
            rain.forEach((value, index) => {
                const barHeight = chartHeight - (yRain(value) - pad.top);
                const gradient = ctx.createLinearGradient(0, yRain(value), 0, pad.top + chartHeight);
                gradient.addColorStop(0, 'rgba(116,102,255,.65)');
                gradient.addColorStop(1, 'rgba(49,127,255,.04)');
                ctx.fillStyle = gradient;
                ctx.beginPath();
                ctx.roundRect(x(index) - barWidth / 2, yRain(value), barWidth, Math.max(1, barHeight), [4, 4, 0, 0]);
                ctx.fill();
            });
            function plot(values, stroke, fill, lineWidth = 2.4, dashed = false) {
                ctx.save();
                if (dashed)
                    ctx.setLineDash([5, 5]);
                ctx.beginPath();
                values.forEach((value, index) => {
                    const pointX = x(index), pointY = yTemp(value);
                    if (index === 0)
                        ctx.moveTo(pointX, pointY);
                    else
                        ctx.lineTo(pointX, pointY);
                });
                ctx.strokeStyle = stroke;
                ctx.lineWidth = lineWidth;
                ctx.lineJoin = 'round';
                ctx.lineCap = 'round';
                ctx.stroke();
                if (fill) {
                    const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + chartHeight);
                    gradient.addColorStop(0, fill);
                    gradient.addColorStop(1, 'rgba(50,184,255,0)');
                    ctx.lineTo(x(count - 1), pad.top + chartHeight);
                    ctx.lineTo(x(0), pad.top + chartHeight);
                    ctx.closePath();
                    ctx.fillStyle = gradient;
                    ctx.fill();
                }
                ctx.restore();
            }
            plot(temperatures, '#42c8ff', 'rgba(48,188,255,.17)', 2.5);
            if (detailed)
                plot(feels, '#ffbd54', null, 1.7, true);
            temperatures.forEach((value, index) => {
                ctx.beginPath();
                ctx.arc(x(index), yTemp(value), detailed ? 2.8 : 2.4, 0, Math.PI * 2);
                ctx.fillStyle = '#72dcff';
                ctx.fill();
            });
            if (detailed)
                feels.forEach((value, index) => {
                    ctx.beginPath();
                    ctx.arc(x(index), yTemp(value), 2.1, 0, Math.PI * 2);
                    ctx.fillStyle = '#ffd07c';
                    ctx.fill();
                });
            const labelStep = detailed ? Math.max(1, Math.ceil(count / 10)) : Math.max(1, Math.ceil(count / 8));
            labels.forEach((label, index) => {
                if (index % labelStep !== 0 && index !== count - 1)
                    return;
                ctx.fillStyle = textColor;
                ctx.textAlign = index === 0 ? 'left' : index === count - 1 ? 'right' : 'center';
                ctx.fillText(index === 0 ? "" + escapeHTML(meteonexaText("app.currentsharepayload.time")) : formatClock(label), x(index), height - 12);
            });
            if (motionElapsed !== null && !state.settings.reduceMotion) {
                const motionSeries = [
                    { label: "" + escapeHTML(meteonexaText("history.yrain.temperature")), values: temperatures, color: '#42c8ff' },
                    ...(detailed ? [{ label: "" + escapeHTML(meteonexaText("history.renderhistory.feels_like")), values: feels, color: '#ffbd54' }] : []),
                    { label: "" + meteonexaText("visualization.yrain.rain_probability"), values: rain, color: '#8b7cff' }
                ];
                const rainSeriesIndex = motionSeries.length - 1;
                drawChartPlayhead(ctx, motionSeries, labels, x, (datasetIndex, value) => datasetIndex === rainSeriesIndex ? yRain(value) : yTemp(value), pad, chartHeight, motionElapsed, !detailed);
            }
            registerChartInteraction(canvas, {
                title: detailed ? meteonexaText("visualization.full_48_hour_forecast") : meteonexaText("visualization.next_24_hour_trend"),
                labels,
                pad,
                series: [
                    { label: "" + escapeHTML(meteonexaText("history.yrain.temperature")), values: temperatures, unit: unitLabel(), color: '#42c8ff', digits: 1 },
                    ...(detailed ? [{ label: "" + escapeHTML(meteonexaText("history.renderhistory.feels_like")), values: feels, unit: unitLabel(), color: '#ffbd54', digits: 1 }] : []),
                    { label: "" + meteonexaText("visualization.yrain.rain_probability"), values: rain, unit: '%', color: '#8b7cff', digits: 0 }
                ]
            });
            if (motionElapsed === null && !state.settings.reduceMotion) {
                schedulePrimaryChartMotion(canvas, elapsed => drawChart(canvas, hours, detailed, elapsed));
            }
        }
        function drawHomeChart() { drawChart($('#home-chart'), 24, false); }
        function drawDetailChart() { drawChart($('#detail-chart'), 48, true); }
        function drawAllDetailCharts() {
            drawDetailChart();
            drawTrendCharts(true);
        }
        const weatherFX = {
            canvas: null, ctx: null, kind: '', isDay: true, particles: [], raf: null,
            width: 0, height: 0, dpr: 1, last: 0, lightningAt: 0
        };
        function initializeWeatherFX() {
            weatherFX.canvas = $('#weather-fx-canvas');
            if (!weatherFX.canvas)
                return;
            weatherFX.ctx = weatherFX.canvas.getContext('2d');
            resizeWeatherFX();
            if (!weatherFX.raf)
                weatherFX.raf = requestAnimationFrame(weatherFXFrame);
        }
        function resizeWeatherFX() {
            if (!weatherFX.canvas || !weatherFX.ctx)
                return;
            const dpr = Math.min(1.5, window.devicePixelRatio || 1);
            const host = weatherFX.canvas.parentElement;
            const rect = host?.getBoundingClientRect();
            const width = Math.max(320, Math.round(rect?.width || innerWidth));
            const height = Math.max(420, Math.round(rect?.height || innerHeight));
            weatherFX.width = width;
            weatherFX.height = height;
            weatherFX.dpr = dpr;
            weatherFX.canvas.width = Math.max(1, Math.round(width * dpr));
            weatherFX.canvas.height = Math.max(1, Math.round(height * dpr));
            weatherFX.canvas.style.width = '100%';
            weatherFX.canvas.style.height = '100%';
            weatherFX.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            buildWeatherParticles();
        }
        function buildWeatherParticles() {
            if (!weatherFX.width || !weatherFX.height)
                return;
            const mobileFactor = innerWidth < 720 ? .58 : 1;
            const reduced = state.settings.reduceMotion ? .22 : 1;
            const counts = { rain: 150, storm: 190, snow: 105, night: 68, clear: 42, cloud: 35, fog: 18 };
            const count = Math.max(6, Math.round((counts[weatherFX.kind] || 30) * mobileFactor * reduced));
            weatherFX.particles = Array.from({ length: count }, (_, i) => ({
                x: Math.random() * weatherFX.width,
                y: Math.random() * weatherFX.height,
                z: .35 + Math.random() * .9,
                speed: .4 + Math.random() * 1.5,
                size: 1 + Math.random() * 3.2,
                drift: -0.45 + Math.random() * .9,
                phase: Math.random() * Math.PI * 2,
                seed: i
            }));
        }
        function updateWeatherAtmosphere(force = false) {
            if (!state.weather?.current)
                return;
            const current = state.weather.current;
            const resolved = currentResolvedCondition(state.weather);
            const meta = resolved.meta;
            const kind = meta.kind === 'cloud' ? 'cloud' : meta.kind;
            const isDay = Boolean(resolved.isDay);
            if (!force && weatherFX.kind === kind && weatherFX.isDay === isDay)
                return;
            weatherFX.kind = kind;
            weatherFX.isDay = isDay;
            const root = $('#weather-atmosphere');
            if (root) {
                root.className = `weather-atmosphere weather-${kind} ${isDay ? 'is-day' : 'is-night'}`;
                root.style.setProperty('--cloud-cover', String(clamp(Number(current.cloud_cover || 0) / 100, .1, 1)));
                root.style.setProperty('--rain-strength', String(clamp(Number(current.precipitation || current.rain || 0) / 8, .2, 1)));
            }
            document.body.dataset.weather = kind;
            document.body.dataset.daylight = isDay ? 'day' : 'night';
            buildWeatherParticles();
        }
        function weatherFXFrame(timestamp) {
            weatherFX.raf = requestAnimationFrame(weatherFXFrame);
            if (!weatherFX.ctx || document.hidden)
                return;
            if (timestamp - weatherFX.last < (state.settings.reduceMotion ? 70 : 30))
                return;
            const dt = Math.min(2.2, (timestamp - (weatherFX.last || timestamp)) / 16.67);
            weatherFX.last = timestamp;
            const { ctx, width, height, particles, kind } = weatherFX;
            ctx.clearRect(0, 0, width, height);
            if (!particles.length)
                return;
            if (kind === 'rain' || kind === 'storm') {
                ctx.lineCap = 'round';
                particles.forEach(particle => {
                    particle.x += (1.4 + particle.z * 2.3) * dt;
                    particle.y += (8 + particle.speed * 8) * dt;
                    if (particle.y > height + 30 || particle.x > width + 30) {
                        particle.y = -30;
                        particle.x = Math.random() * width - width * .15;
                    }
                    const length = 12 + particle.z * 24;
                    const gradient = ctx.createLinearGradient(particle.x, particle.y, particle.x - 6, particle.y - length);
                    gradient.addColorStop(0, `rgba(80,205,255,${.18 + particle.z * .28})`);
                    gradient.addColorStop(1, 'rgba(80,205,255,0)');
                    ctx.strokeStyle = gradient;
                    ctx.lineWidth = .7 + particle.z * 1.25;
                    ctx.beginPath();
                    ctx.moveTo(particle.x, particle.y);
                    ctx.lineTo(particle.x - 7, particle.y - length);
                    ctx.stroke();
                });
                if (kind === 'storm' && timestamp > weatherFX.lightningAt) {
                    weatherFX.lightningAt = timestamp + 2600 + Math.random() * 5200;
                    const flash = $('.atmosphere-lightning');
                    flash?.classList.remove('flash');
                    requestAnimationFrame(() => flash?.classList.add('flash'));
                }
            }
            else if (kind === 'snow') {
                particles.forEach(particle => {
                    particle.phase += .012 * dt;
                    particle.x += (Math.sin(particle.phase) * .7 + particle.drift) * dt;
                    particle.y += (1.2 + particle.speed * 1.9) * dt;
                    if (particle.y > height + 12) {
                        particle.y = -12;
                        particle.x = Math.random() * width;
                    }
                    ctx.fillStyle = `rgba(230,248,255,${.28 + particle.z * .55})`;
                    ctx.beginPath();
                    ctx.arc(particle.x, particle.y, particle.size * particle.z, 0, Math.PI * 2);
                    ctx.fill();
                });
            }
            else if (kind === 'fog' || kind === 'cloud') {
                particles.forEach(particle => {
                    particle.x += (.42 + particle.speed * .46) * dt;
                    if (particle.x > width + 260)
                        particle.x = -260;
                    const w = 120 + particle.z * 250;
                    const gradient = ctx.createLinearGradient(particle.x - w, 0, particle.x + w, 0);
                    gradient.addColorStop(0, 'rgba(195,224,255,0)');
                    gradient.addColorStop(.5, `rgba(205,235,255,${kind === 'fog' ? .07 + particle.z * .1 : .055 + particle.z * .095})`);
                    gradient.addColorStop(1, 'rgba(195,224,255,0)');
                    ctx.fillStyle = gradient;
                    ctx.fillRect(particle.x - w, particle.y, w * 2, 28 + particle.z * 65);
                });
            }
            else {
                particles.forEach(particle => {
                    particle.phase += .015 * dt;
                    particle.x += particle.drift * .06 * dt;
                    const alpha = (.12 + .35 * (Math.sin(particle.phase) * .5 + .5)) * particle.z;
                    ctx.fillStyle = weatherFX.isDay ? `rgba(255,230,145,${alpha})` : `rgba(178,222,255,${alpha})`;
                    ctx.beginPath();
                    ctx.arc(particle.x, particle.y, weatherFX.isDay ? particle.size * .55 : particle.size * .42, 0, Math.PI * 2);
                    ctx.fill();
                });
            }
        }
        function chartEase(value) { return 1 - Math.pow(1 - clamp(value, 0, 1), 3); }
        function drawChartPlayhead(ctx, datasets, labels, x, yForDataset, pad, chartHeight, elapsed, compact = false) {
            if (state.settings.reduceMotion || labels.length < 2)
                return;
            const cycleMs = compact ? 24000 : 30000;
            const progress = ((elapsed % cycleMs) + cycleMs) % cycleMs / cycleMs;
            const floatIndex = progress * (labels.length - 1);
            const leftIndex = Math.floor(floatIndex);
            const rightIndex = Math.min(labels.length - 1, leftIndex + 1);
            const mix = floatIndex - leftIndex;
            const px = x(floatIndex);
            const gradient = ctx.createLinearGradient(px - 34, 0, px + 34, 0);
            gradient.addColorStop(0, 'rgba(83,224,255,0)');
            gradient.addColorStop(.5, 'rgba(83,224,255,.22)');
            gradient.addColorStop(1, 'rgba(83,224,255,0)');
            ctx.save();
            ctx.fillStyle = gradient;
            ctx.fillRect(px - 34, pad.top, 68, chartHeight);
            ctx.strokeStyle = 'rgba(116,235,255,.42)';
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 6]);
            ctx.beginPath();
            ctx.moveTo(px, pad.top);
            ctx.lineTo(px, pad.top + chartHeight);
            ctx.stroke();
            ctx.setLineDash([]);
            datasets.forEach((dataset, datasetIndex) => {
                const a = Number(dataset.values?.[leftIndex] || 0);
                const b = Number(dataset.values?.[rightIndex] || a);
                const value = a + (b - a) * mix;
                const py = yForDataset(datasetIndex, value);
                const pulse = 4.5 + Math.sin(elapsed / 420 + datasetIndex) * 1.2;
                ctx.shadowColor = dataset.color;
                ctx.shadowBlur = 16;
                ctx.fillStyle = dataset.color;
                ctx.beginPath();
                ctx.arc(px, py, pulse, 0, Math.PI * 2);
                ctx.fill();
                ctx.shadowBlur = 0;
                ctx.strokeStyle = 'rgba(255,255,255,.88)';
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.arc(px, py, Math.max(2.5, pulse - 2), 0, Math.PI * 2);
                ctx.stroke();
            });
            ctx.restore();
        }
        function drawMotionChart(canvas, datasets, labels, options = {}) {
            if (!canvas || !Array.isArray(datasets) || !datasets.length || !Array.isArray(labels) || !labels.length)
                return;
            if (canvas._motionRaf)
                cancelAnimationFrame(canvas._motionRaf);
            const cleaned = datasets.map(dataset => ({
                ...dataset,
                values: labels.map((_, index) => {
                    const value = Number(dataset.values?.[index]);
                    return Number.isFinite(value) ? value : 0;
                })
            }));
            // Datasets with the same unit share the same Y scale; different units use
            // a secondary Y axis. This makes line height directly comparable only when
            // the measurement itself is comparable.
            const unitGroups = [];
            cleaned.forEach((dataset, index) => {
                const unit = String(dataset.unit || '').trim();
                let group = unitGroups.find(item => item.unit === unit);
                if (!group) {
                    group = { unit, indices: [] };
                    unitGroups.push(group);
                }
                group.indices.push(index);
            });
            const startedAt = performance.now();
            const duration = state.settings.reduceMotion ? 1 : 820;
            let lastPaintAt = 0;
            const paint = now => {
                if (now - lastPaintAt < 42) {
                    canvas._motionRaf = requestAnimationFrame(paint);
                    return;
                }
                lastPaintAt = now;
                const setup = canvasSetup(canvas);
                if (!setup)
                    return;
                const { ctx, width, height } = setup;
                const elapsed = Math.max(0, now - startedAt);
                const progress = chartEase(elapsed / duration);
                const compact = Boolean(options.compact);
                const dualAxis = unitGroups.length > 1;
                const pad = compact
                    ? { left: dualAxis ? 48 : 44, right: dualAxis ? 64 : 18, top: 18, bottom: 40 }
                    : { left: 58, right: dualAxis ? 58 : 22, top: 28, bottom: 48 };
                const cw = Math.max(1, width - pad.left - pad.right);
                const ch = Math.max(1, height - pad.top - pad.bottom);
                const x = index => pad.left + (index / Math.max(1, labels.length - 1)) * cw;
                const bodyStyles = getComputedStyle(document.body);
                const textColor = bodyStyles.getPropertyValue('--muted').trim() || '#8298b6';
                const gridColor = document.body.dataset.theme === 'light' ? 'rgba(25,78,132,.11)' : 'rgba(126,195,255,.10)';
                ctx.clearRect(0, 0, width, height);
                ctx.lineWidth = 1;
                ctx.strokeStyle = gridColor;
                for (let row = 0; row <= 4; row += 1) {
                    const y = pad.top + ch * row / 4;
                    ctx.beginPath();
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(width - pad.right, y);
                    ctx.stroke();
                }
                const groupScale = unitGroups.map(group => {
                    const members = group.indices.map(index => cleaned[index]);
                    const values = members.flatMap(dataset => dataset.values);
                    const explicitMin = members.map(dataset => Number(dataset.min)).filter(Number.isFinite);
                    const explicitMax = members.map(dataset => Number(dataset.max)).filter(Number.isFinite);
                    let min = explicitMin.length ? Math.min(...explicitMin) : Math.min(...values);
                    let max = explicitMax.length ? Math.max(...explicitMax) : Math.max(...values);
                    if (!Number.isFinite(min)) min = 0;
                    if (!Number.isFinite(max)) max = min + 1;
                    if (max <= min) max = min + 1;
                    if (!explicitMin.length && min > 0) {
                        const range = max - min;
                        min = Math.max(0, min - Math.max(range * .12, max * .03));
                    }
                    if (!explicitMax.length) {
                        const range = max - min;
                        max += Math.max(range * .12, Math.abs(max) * .03, 1);
                    }
                    return { ...group, min, max, y: value => pad.top + ch - ((value - min) / Math.max(.001, max - min)) * ch };
                });
                const chartY = [];
                cleaned.forEach((dataset, datasetIndex) => {
                    const groupIndex = unitGroups.findIndex(group => group.indices.includes(datasetIndex));
                    const scale = groupScale[Math.max(0, groupIndex)];
                    const y = scale.y;
                    chartY[datasetIndex] = y;
                    const values = dataset.values;
                    const visibleEnd = Math.max(1, Math.min(values.length - 1, Math.ceil((values.length - 1) * progress)));
                    const path = new Path2D();
                    for (let index = 0; index <= visibleEnd; index += 1) {
                        const px = x(index);
                        const py = y(values[index]);
                        if (index === 0) path.moveTo(px, py); else path.lineTo(px, py);
                    }
                    ctx.save();
                    if (dataset.dashed) {
                        ctx.setLineDash([7, 6]);
                        ctx.lineDashOffset = -(elapsed / 70);
                    }
                    ctx.strokeStyle = dataset.color;
                    ctx.lineWidth = dataset.width || (datasetIndex ? 2 : 2.8);
                    ctx.lineJoin = 'round';
                    ctx.lineCap = 'round';
                    ctx.shadowColor = dataset.color;
                    ctx.shadowBlur = datasetIndex ? 4 : 10;
                    ctx.stroke(path);
                    ctx.shadowBlur = 0;
                    if (dataset.fill && visibleEnd > 0) {
                        const fillPath = new Path2D(path);
                        fillPath.lineTo(x(visibleEnd), pad.top + ch);
                        fillPath.lineTo(x(0), pad.top + ch);
                        fillPath.closePath();
                        const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + ch);
                        gradient.addColorStop(0, dataset.fill);
                        gradient.addColorStop(1, 'rgba(0,0,0,0)');
                        ctx.fillStyle = gradient;
                        ctx.fill(fillPath);
                    }
                    const pointStep = compact && values.length > 30 ? 2 : 1;
                    for (let pointIndex = 0; pointIndex <= visibleEnd; pointIndex += pointStep) {
                        ctx.fillStyle = dataset.color;
                        ctx.globalAlpha = pointIndex === visibleEnd ? 1 : .74;
                        ctx.beginPath();
                        ctx.arc(x(pointIndex), y(values[pointIndex]), pointIndex === visibleEnd ? (datasetIndex ? 3 : 4) : 2.2, 0, Math.PI * 2);
                        ctx.fill();
                    }
                    ctx.globalAlpha = 1;
                    ctx.restore();
                });
                // Numeric Y ticks + labels on both axes when units differ.
                ctx.font = '600 10px Inter, system-ui, sans-serif';
                ctx.fillStyle = textColor;
                groupScale.slice(0, 2).forEach((scale, groupIndex) => {
                    const sideRight = groupIndex === 1;
                    ctx.textAlign = sideRight ? 'left' : 'right';
                    for (let row = 0; row <= 4; row += 1) {
                        const value = scale.max - row / 4 * (scale.max - scale.min);
                        const digits = Math.abs(scale.max - scale.min) <= 15 ? 1 : 0;
                        const label = value.toLocaleString(appLocale(), { maximumFractionDigits: digits });
                        ctx.fillText(`${label}${scale.unit}`, sideRight ? width - pad.right + 7 : pad.left - 7, pad.top + ch * row / 4);
                    }
                    const representative = cleaned[scale.indices[0]];
                    drawChartAxisTitle(ctx, chartUnitAxisLabel(representative.label, scale.unit), sideRight ? width - 11 : 11, pad.top + ch / 2, { rotate: sideRight ? Math.PI / 2 : -Math.PI / 2 });
                });
                ctx.font = '600 11px Inter, system-ui, sans-serif';
                ctx.fillStyle = textColor;
                const tickCount = compact ? (width < 480 ? 3 : 5) : (width < 620 ? 4 : 6);
                for (let tick = 0; tick < tickCount; tick += 1) {
                    const index = Math.round(tick / Math.max(1, tickCount - 1) * (labels.length - 1));
                    ctx.textAlign = tick === 0 ? 'left' : tick === tickCount - 1 ? 'right' : 'center';
                    ctx.fillText(formatClock(labels[index]), x(index), height - 18);
                }
                drawChartAxisTitle(ctx, t('app.currentsharepayload.time'), pad.left + cw / 2, height - 6);
                if (progress >= 1)
                    drawChartPlayhead(ctx, cleaned, labels, x, (datasetIndex, value) => chartY[datasetIndex](value), pad, ch, elapsed - duration, compact);
                canvas.dataset.chartReady = 'true';
                registerChartInteraction(canvas, {
                    title: options.title || meteonexaText('visualization.x.hourly_trend'),
                    labels,
                    pad,
                    series: cleaned.map(dataset => ({
                        label: dataset.label || meteonexaText('visualization.show.value'), values: dataset.values, unit: dataset.unit || '',
                        color: dataset.color, digits: dataset.digits
                    }))
                });
                const maxMotionDuration = duration + 6200;
                if (canvas.isConnected && elapsed < maxMotionDuration)
                    canvas._motionRaf = requestAnimationFrame(paint);
                else
                    canvas._motionRaf = null;
            };
            canvas._motionRaf = requestAnimationFrame(paint);
        }
        function drawTrendCharts(includeDetails = false) {
            if (!state.weather?.hourly)
                return;
            const hourly = state.weather.hourly;
            const start = Math.max(0, currentHourlyIndex(state.weather));
            const available = Math.max(1, (hourly.time || []).length - start);
            const labels = hours => (hourly.time || []).slice(start, start + Math.min(hours, available));
            const take = (key, hours, fallback = 0) => {
                const source = Array.isArray(hourly[key]) ? hourly[key] : [];
                const count = Math.min(hours, available);
                return Array.from({ length: count }, (_, offset) => {
                    const value = Number(source[start + offset]);
                    return Number.isFinite(value) ? value : (typeof fallback === 'function' ? fallback(offset) : fallback);
                });
            };
            const labels24 = labels(24);
            const wind24 = take('wind_speed_10m', 24);
            const gust24 = take('wind_gusts_10m', 24, offset => wind24[offset] || 0);
            const humidity24 = take('relative_humidity_2m', 24);
            const pressure24 = take('surface_pressure', 24, 1013);
            const uv24 = take('uv_index', 24);
            const cloud24 = take('cloud_cover', 24);
            const currentWind = wind24[0] ?? 0;
            const currentHumidity = humidity24[0] ?? 0;
            const currentUv = uv24[0] ?? 0;
            if ($('#wind-chart-value'))
                $('#wind-chart-value').textContent = `${Math.round(currentWind)} km/h`;
            if ($('#humidity-chart-value'))
                $('#humidity-chart-value').textContent = `${Math.round(currentHumidity)}%`;
            if ($('#uv-chart-value'))
                $('#uv-chart-value').textContent = `UV ${Number(currentUv).toFixed(1)}`;
            drawMotionChart($('#wind-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.wind")), unit: ' km/h', digits: 0, values: wind24, color: '#41d5ff', fill: 'rgba(65,213,255,.25)' },
                { label: "" + escapeHTML(meteonexaText("history.renderhistory.gusts")), unit: ' km/h', digits: 0, values: gust24, color: '#9b7cff', dashed: true }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.wind_gusts_next_24_hours") });
            drawMotionChart($('#humidity-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.humidity")), unit: '%', digits: 0, values: humidity24, color: '#49d7ff', fill: 'rgba(73,215,255,.22)', min: 0, max: 100 },
                { label: "" + escapeHTML(meteonexaText("visualization.take.pressure")), unit: ' hPa', digits: 0, values: pressure24, color: '#54eda4', dashed: true }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.humidity_pressure_next_24_hours") });
            drawMotionChart($('#uv-chart'), [
                { label: "" + meteonexaText("visualization.take.uv_index"), unit: '', digits: 1, values: uv24, color: '#ffca55', fill: 'rgba(255,202,85,.22)', min: 0, max: Math.max(11, ...uv24) },
                { label: "" + meteonexaText("visualization.take.cloud_cover"), unit: '%', digits: 0, values: cloud24, color: '#9bb8e8', dashed: true, min: 0, max: 100 }
            ], labels24, { compact: true, title: "" + meteonexaText("visualization.uv_index_cloud_cover_next_24_hours") });
            if (!includeDetails && state.currentPage !== 'details')
                return;
            const labels48 = labels(48);
            const wind48 = take('wind_speed_10m', 48);
            const gust48 = take('wind_gusts_10m', 48, offset => wind48[offset] || 0);
            const humidity48 = take('relative_humidity_2m', 48);
            const pressure48 = take('surface_pressure', 48, 1013);
            const uv48 = take('uv_index', 48);
            const cloud48 = take('cloud_cover', 48);
            drawMotionChart($('#detail-wind-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.wind")), unit: ' km/h', digits: 0, values: wind48, color: '#35d2ff', fill: 'rgba(53,210,255,.24)' },
                { label: "" + escapeHTML(meteonexaText("history.renderhistory.gusts")), unit: ' km/h', digits: 0, values: gust48, color: '#a779ff', dashed: true }
            ], labels48, { title: "" + meteonexaText("visualization.wind_gusts_48_hours") });
            drawMotionChart($('#detail-comfort-chart'), [
                { label: "" + escapeHTML(meteonexaText("visualization.take.humidity")), unit: '%', digits: 0, values: humidity48, color: '#4bdcff', fill: 'rgba(75,220,255,.20)', min: 0, max: 100 },
                { label: "" + escapeHTML(meteonexaText("visualization.take.pressure")), unit: ' hPa', digits: 0, values: pressure48, color: '#50ed9f', dashed: true }
            ], labels48, { title: "" + meteonexaText("visualization.humidity_pressure_48_hours") });
            drawMotionChart($('#detail-sky-chart'), [
                { label: "" + meteonexaText("visualization.take.uv_index"), unit: '', digits: 1, values: uv48, color: '#ffc94d', fill: 'rgba(255,201,77,.20)', min: 0, max: Math.max(11, ...uv48) },
                { label: "" + meteonexaText("visualization.take.cloud_cover"), unit: '%', digits: 0, values: cloud48, color: '#9ebcff', dashed: true, min: 0, max: 100 }
            ], labels48, { title: "" + meteonexaText("visualization.uv_index_cloud_cover_48_hours") });
            const air = state.air?.hourly;
            if (air?.time?.length) {
                const found = localSeriesIndex(air.time, { weatherData: state.air });
                const airStart = Math.max(0, found - 1);
                const airLabels = air.time.slice(airStart, airStart + 48);
                drawMotionChart($('#detail-air-chart'), [
                    { label: "" + meteonexaText("visualization.take.european_aqi"), unit: '', digits: 0, values: (air.european_aqi || []).slice(airStart, airStart + 48), color: '#59e99a', fill: 'rgba(89,233,154,.20)', min: 0, max: 150 },
                    { label: "" + meteonexaText("visualization.take.pm2_5"), unit: ' µg/m³', digits: 1, values: (air.pm2_5 || []).slice(airStart, airStart + 48), color: '#ffb84c', dashed: true }
                ], airLabels, { title: "" + meteonexaText("visualization.air_quality_48_hours") });
            }
        }
        function initializeChartObservers() {
            if (!('ResizeObserver' in window))
                return;
            let timer = 0;
            const observer = new ResizeObserver(entries => {
                if (!state.weather || !entries.some(entry => entry.contentRect.width > 20 && entry.contentRect.height > 20))
                    return;
                clearTimeout(timer);
                timer = window.setTimeout(() => {
                    drawHomeChart();
                    drawTrendCharts(state.currentPage === 'details');
                    if (state.currentPage === 'details')
                        drawDetailChart();
                    if (state.currentPage === 'history')
                        drawHistoryChart();
                }, 120);
            });
            $$('.chart-wrap, .mini-chart-wrap, .detail-chart-wrap, .detail-motion-chart, .history-chart-wrap').forEach(element => observer.observe(element));
        }
        const { lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion } = deps.radarMotion.create({
            state, storage: STORAGE, $, t, clamp, windDirection, saveJSON,
            isUiFeatureVisible, intelligenceLocationKey, escapeHTML
        });
        const {
            syncRadarVectorLayer, selectRadarLocation, performRadarCitySearch, useGpsFromRadar, setRadarMode,
            renderRadarMap, drawForecastRadarLayer, ensureRadar, setRadarFrame, stopRadarAnimation,
            toggleRadarAnimation, stepRadar, zoomRadar
        } = deps.radarController.create({
            state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
            normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
            loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
            persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
            showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
            lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion
        });
            return Object.freeze({
                canvasSetup,
                hideChartTooltip,
                registerChartInteraction,
                drawChartAxisTitle,
                chartUnitAxisLabel,
                drawHomeChart,
                drawDetailChart,
                drawAllDetailCharts,
                initializeWeatherFX,
                resizeWeatherFX,
                updateWeatherAtmosphere,
                drawMotionChart,
                drawTrendCharts,
                initializeChartObservers,
                lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion,
                syncRadarVectorLayer, selectRadarLocation, performRadarCitySearch, useGpsFromRadar, setRadarMode,
                renderRadarMap, drawForecastRadarLayer, ensureRadar, setRadarFrame, stopRadarAnimation,
                toggleRadarAnimation, stepRadar, zoomRadar
            });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
