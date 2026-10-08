export function createVisualizationChartCore(context) {
    const { window, state, $, clamp, appLocale, meteonexaText } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_VISUALIZATION_CHART_CORE_CONTEXT_INVALID');
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
    return Object.freeze({
        canvasSetup, hideChartTooltip, registerChartInteraction, drawChartAxisTitle,
        chartUnitAxisLabel, schedulePrimaryChartMotion
    });
}
