export function createVisualizationMotionCharts(context) {
    const {
        window, state, $, clamp, canvasSetup, appLocale, drawChartAxisTitle,
        chartUnitAxisLabel, registerChartInteraction
    } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_VISUALIZATION_MOTION_CONTEXT_INVALID');
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
    return Object.freeze({ drawMotionChart });
}
