export function createHistoryExplorer(context) {
    const {
        state, $, $$, clamp, localSeriesIndex, localDateHourKey, weatherMeta, t,
        PREVIEW_MODE, hasUsableLocation, renderAll, renderHeader, temperature, windDirection,
        meteonexaText, isGuestSession, loadJSON, STORAGE, severeWeatherLocationKey,
        severeWeatherEventRank, optionalFiniteNumber, formatOfficialAlertTime, appLocale, formatClock,
        CONFIG, fetchJSON, showToast, withLoader, capitalize, fullLocationLabel, escapeHTML, weatherArt,
        translateDOM, canvasSetup, convertTemp, drawChartAxisTitle, chartUnitAxisLabel, unitLabel,
        registerChartInteraction
    } = context;
    if (!state || typeof $ !== 'function' || typeof t !== 'function') {
        throw new Error('METEONEXA_FORECAST_HISTORY_CONTEXT_INVALID');
    }
function historyDateValue(date) {
    const value = new Date(date);
    return `${value.getUTCFullYear()}-${String(value.getUTCMonth() + 1).padStart(2, '0')}-${String(value.getUTCDate()).padStart(2, '0')}`;
}
function historyDateOffset(days, from = new Date()) {
    const value = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth(), from.getUTCDate()));
    value.setUTCDate(value.getUTCDate() + Number(days || 0));
    return historyDateValue(value);
}
function historyDateObject(value) {
    return new Date(`${String(value)}T12:00:00Z`);
}
function historyLocationKey() {
    return `${Number(state.location.latitude).toFixed(5)}:${Number(state.location.longitude).toFixed(5)}`;
}
function ensureHistoryRange() {
    const maximum = historyDateOffset(-5);
    if (!state.history.end || state.history.end > maximum)
        state.history.end = maximum;
    if (!state.history.start || state.history.start > state.history.end) {
        state.history.start = historyDateOffset(-29, historyDateObject(state.history.end));
    }
    const start = $('#history-start');
    const end = $('#history-end');
    if (start) {
        start.min = '1940-01-01';
        start.max = maximum;
        start.value = state.history.start;
    }
    if (end) {
        end.min = '1940-01-01';
        end.max = maximum;
        end.value = state.history.end;
    }
}
function setHistoryRange(days) {
    const count = clamp(Math.round(Number(days || 30)), 2, 366);
    state.history.end = historyDateOffset(-5);
    state.history.start = historyDateOffset(-(count - 1), historyDateObject(state.history.end));
    ensureHistoryRange();
    $$('[data-history-days]').forEach(button => button.classList.toggle('active', Number(button.dataset.historyDays) === count));
}
function historyRangeDays(start, end) {
    const first = historyDateObject(start);
    const last = historyDateObject(end);
    return Math.floor((last - first) / 86400000) + 1;
}
function validateHistoryRange(start, end) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end)) {
        throw new Error(t("history.select_start_date_end_date"));
    }
    const days = historyRangeDays(start, end);
    if (!Number.isFinite(days) || days < 1)
        throw new Error(t("history.start_date_must_before_end_date"));
    if (days > 366)
        throw new Error(t("history.select_period_no_more_than_366_days"));
    return days;
}
function buildHistoricalWeatherURL(start, end) {
    const params = new URLSearchParams({
        latitude: String(state.location.latitude),
        longitude: String(state.location.longitude),
        start_date: start,
        end_date: end,
        daily: 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,precipitation_sum,rain_sum,snowfall_sum,precipitation_hours,wind_speed_10m_max,wind_gusts_10m_max',
        timezone: 'auto',
        wind_speed_unit: 'kmh'
    });
    return `${CONFIG.HISTORICAL_WEATHER_API}?${params}`;
}
function createPreviewHistory(start, end) {
    const count = validateHistoryRange(start, end);
    const daily = {
        time: [], weather_code: [], temperature_2m_max: [], temperature_2m_min: [],
        apparent_temperature_max: [], apparent_temperature_min: [], precipitation_sum: [], rain_sum: [], snowfall_sum: [],
        precipitation_hours: [], wind_speed_10m_max: [], wind_gusts_10m_max: []
    };
    for (let index = 0; index < count; index += 1) {
        const date = historyDateOffset(index, historyDateObject(start));
        const wave = Math.sin(index / 4.8);
        const rain = index % 9 === 2 ? 12.4 : index % 6 === 0 ? 3.2 : 0;
        daily.time.push(date);
        daily.weather_code.push(rain > 8 ? 61 : rain > 0 ? 51 : index % 5 === 0 ? 2 : 1);
        daily.temperature_2m_max.push(22 + wave * 4 + (index % 7) * .25);
        daily.temperature_2m_min.push(13 + wave * 2.2 + (index % 4) * .2);
        daily.apparent_temperature_max.push(22.5 + wave * 4.1);
        daily.apparent_temperature_min.push(12.5 + wave * 2.1);
        daily.precipitation_sum.push(rain);
        daily.rain_sum.push(rain);
        daily.snowfall_sum.push(0);
        daily.precipitation_hours.push(rain ? 4 : 0);
        daily.wind_speed_10m_max.push(12 + (index % 8) * 2);
        daily.wind_gusts_10m_max.push(22 + (index % 10) * 3);
    }
    return { latitude: state.location.latitude, longitude: state.location.longitude, timezone: state.location.timezone || 'auto', daily };
}
async function loadHistory({ force = false, silent = false } = {}) {
    ensureHistoryRange();
    const start = $('#history-start')?.value || state.history.start;
    const end = $('#history-end')?.value || state.history.end;
    let days;
    try {
        days = validateHistoryRange(start, end);
    }
    catch (error) {
        showToast(t("history.loadhistory.invalid_date_range"), error.message, 'warning');
        return null;
    }
    state.history.start = start;
    state.history.end = end;
    const locationKey = historyLocationKey();
    const rangeKey = `${start}:${end}`;
    if (!force && state.history.data && state.history.locationKey === locationKey && state.history.rangeKey === rangeKey) {
        renderHistory();
        return state.history.data;
    }
    if (state.history.request)
        return state.history.request;
    const task = async () => {
        try {
            const data = PREVIEW_MODE ? createPreviewHistory(start, end) : await fetchJSON(buildHistoricalWeatherURL(start, end), { timeout: 18000 });
            if (!data?.daily?.time?.length)
                throw new Error(t("history.no_data_available_selected_period"));
            state.history.data = { ...data, fetchedAt: Date.now(), source: PREVIEW_MODE ? 'preview' : "open-meteo" };
            state.history.locationKey = locationKey;
            state.history.rangeKey = rangeKey;
            renderHistory();
            if (!silent)
                showToast(t("history.task.history_updated"), t("history.value_days_loaded_value", { count: days, location: state.location.name }), 'success', 2400);
            return state.history.data;
        }
        catch (error) {
            state.history.data = null;
            renderHistory();
            showToast(t("history.task.historical_data_unavailable"), error?.message || t("history.try_again_few_minutes"), 'error');
            return null;
        }
    };
    state.history.request = silent ? task() : withLoader(t("history.task.loading_history"), t("history.retrieving_historical_data_value", { location: state.location.name }), task, 520);
    try {
        return await state.history.request;
    }
    finally {
        state.history.request = null;
    }
}
function historyAverage(values) {
    const numbers = (values || []).map(Number).filter(Number.isFinite);
    return numbers.length ? numbers.reduce((sum, value) => sum + value, 0) / numbers.length : 0;
}
function historyMaximum(values) {
    const numbers = (values || []).map(Number).filter(Number.isFinite);
    return numbers.length ? Math.max(...numbers) : 0;
}
function historyTotal(values) {
    return (values || []).map(Number).filter(Number.isFinite).reduce((sum, value) => sum + value, 0);
}
function formatHistoryDay(value, options = {}) {
    const format = options.short
        ? { day: '2-digit', month: 'short' }
        : { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' };
    return capitalize(new Intl.DateTimeFormat(appLocale(), { ...format, timeZone: 'UTC' }).format(historyDateObject(value)));
}
function renderHistory() {
    ensureHistoryRange();
    const locationName = $('#history-location-name');
    const locationZone = $('#history-location-zone');
    if (locationName)
        locationName.textContent = fullLocationLabel(state.location);
    if (locationZone)
        locationZone.textContent = state.location.timezone && state.location.timezone !== 'auto' ? state.location.timezone : t("history.renderhistory.local_time_zone");
    const data = state.history.data;
    const daily = data?.daily;
    const empty = $('#history-empty');
    const table = $('#history-table');
    if (!daily?.time?.length) {
        if (empty)
            empty.hidden = false;
        if (table)
            table.innerHTML = '';
        ['history-avg-max', 'history-avg-min'].forEach(id => {
            const node = $('#' + id);
            if (node)
                node.textContent = '--°';
        });
        const rain = $('#history-rain-total');
        if (rain)
            rain.textContent = "" + meteonexaText("history.renderhistory.mm");
        const gust = $('#history-gust-max');
        if (gust)
            gust.textContent = "" + meteonexaText("history.renderhistory.km_h");
        ['history-avg-max-note', 'history-avg-min-note', 'history-rain-note', 'history-gust-note', 'history-period-badge', 'history-days-count']
            .forEach(id => {
            const node = $('#' + id);
            if (node)
                node.textContent = '—';
        });
        const canvas = $('#history-chart');
        const setup = canvasSetup(canvas);
        if (setup)
            setup.ctx.clearRect(0, 0, setup.width, setup.height);
        return;
    }
    if (empty)
        empty.hidden = true;
    const count = daily.time.length;
    const avgMax = historyAverage(daily.temperature_2m_max);
    const avgMin = historyAverage(daily.temperature_2m_min);
    const totalRain = historyTotal(daily.precipitation_sum);
    const maxGust = historyMaximum(daily.wind_gusts_10m_max);
    $('#history-avg-max').textContent = temperature(avgMax);
    $('#history-avg-min').textContent = temperature(avgMin);
    $('#history-rain-total').textContent = `${totalRain.toLocaleString(appLocale(), { maximumFractionDigits: 1 })} mm`;
    $('#history-gust-max').textContent = `${Math.round(maxGust)} km/h`;
    const period = `${formatHistoryDay(daily.time[0], { short: true })} – ${formatHistoryDay(daily.time[count - 1], { short: true })}`;
    $('#history-avg-max-note').textContent = period;
    $('#history-avg-min-note').textContent = period;
    $('#history-rain-note').textContent = t("history.renderhistory.value_days_analyzed", { count });
    $('#history-gust-note').textContent = t("history.renderhistory.highest_value_period");
    $('#history-period-badge').textContent = period;
    $('#history-days-count').textContent = t("history.renderhistory.value_days", { count });
    table.innerHTML = `<div class="history-table-row history-table-head" role="row">
      <span role="columnheader">${escapeHTML(t("history.renderhistory.date"))}</span><span role="columnheader">${escapeHTML(t("history.renderhistory.condition"))}</span><span role="columnheader">${escapeHTML(t("history.renderhistory.low_high"))}</span><span role="columnheader">${escapeHTML(t("history.renderhistory.rain"))}</span><span role="columnheader">${escapeHTML(t("history.renderhistory.wind_gusts"))}</span><span aria-hidden="true"></span>
    </div>` + daily.time.map((date, index) => {
        const meta = weatherMeta(daily.weather_code[index], 1);
        const rain = Number(daily.precipitation_sum?.[index] || 0);
        const rainOnly = Number(daily.rain_sum?.[index] || rain);
        const snow = Number(daily.snowfall_sum?.[index] || 0);
        const precipitationHours = Number(daily.precipitation_hours?.[index] || 0);
        const wind = Number(daily.wind_speed_10m_max?.[index] || 0);
        const gust = Number(daily.wind_gusts_10m_max?.[index] || 0);
        const rowId = `history-details-${index}`;
        const detailAria = `${t("history.renderhistory.details")} · ${formatHistoryDay(date)}`;
        return `<div class="history-table-row" role="row" data-history-row>
        <span class="history-date-cell" role="cell" data-label="${escapeHTML(t("history.renderhistory.date"))}"><strong>${escapeHTML(formatHistoryDay(date))}</strong><small>${escapeHTML(date)}</small></span>
        <span class="history-condition-cell" role="cell" data-label="${escapeHTML(t("history.renderhistory.condition"))}">${weatherArt(daily.weather_code[index], 1)}<strong>${escapeHTML(meta.label)}</strong></span>
        <span class="history-temperature-cell" role="cell" data-label="${escapeHTML(t("history.renderhistory.low_high"))}"><strong>${escapeHTML(temperature(daily.temperature_2m_min[index]))}</strong><i>→</i><strong>${escapeHTML(temperature(daily.temperature_2m_max[index]))}</strong></span>
        <span class="history-rain-cell" role="cell" data-label="${escapeHTML(t("history.renderhistory.rain"))}"><svg><use href="#i-droplet"/></svg><strong>${rain.toLocaleString(appLocale(), { maximumFractionDigits: 1 })} mm</strong></span>
        <span class="history-wind-cell" role="cell" data-label="${escapeHTML(t("history.renderhistory.wind_gusts"))}"><strong>${Math.round(wind)} km/h</strong><small>${escapeHTML(t("history.gusts_value_km_h", { value: Math.round(gust) }))}</small></span>
        <button class="history-row-toggle" type="button" aria-expanded="false" aria-controls="${rowId}" aria-label="${escapeHTML(detailAria)}"><svg><use href="#i-chevron"/></svg></button>
        <div class="history-row-details" id="${rowId}" hidden>
          <span><small>${escapeHTML(t("history.renderhistory.feels_like"))}</small><strong>${escapeHTML(temperature(daily.apparent_temperature_min?.[index]))} → ${escapeHTML(temperature(daily.apparent_temperature_max?.[index]))}</strong></span>
          <span><small>${escapeHTML(t("history.renderhistory.rain"))}</small><strong>${rainOnly.toLocaleString(appLocale(), { maximumFractionDigits: 1 })} mm</strong></span>
          <span><small>${escapeHTML(t("history.renderhistory.snow"))}</small><strong>${snow.toLocaleString(appLocale(), { maximumFractionDigits: 1 })} cm</strong></span>
          <span><small>${escapeHTML(t("history.renderhistory.duration"))}</small><strong>${precipitationHours.toLocaleString(appLocale(), { maximumFractionDigits: 1 })} h</strong></span>
          <span><small>${escapeHTML(t("history.renderhistory.gusts"))}</small><strong>${Math.round(gust)} km/h</strong></span>
        </div>
      </div>`;
    }).join('');
    $$('.history-row-toggle', table).forEach(button => button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!expanded));
        const details = document.getElementById(button.getAttribute('aria-controls'));
        if (details) details.hidden = expanded;
        button.closest('[data-history-row]')?.classList.toggle('expanded', !expanded);
    }));
    requestAnimationFrame(drawHistoryChart);
    translateDOM($('#page-history'));
}
function drawHistoryChart() {
    const canvas = $('#history-chart');
    const daily = state.history.data?.daily;
    const setup = canvasSetup(canvas);
    if (!setup || !daily?.time?.length)
        return;
    const { ctx, width, height } = setup;
    const maxValues = daily.temperature_2m_max.map(convertTemp);
    const minValues = daily.temperature_2m_min.map(convertTemp);
    const rainValues = daily.precipitation_sum.map(value => Number(value || 0));
    const labels = daily.time;
    const pad = { left: width < 620 ? 52 : 62, right: width < 620 ? 50 : 58, top: 26, bottom: 48 };
    const chartWidth = Math.max(1, width - pad.left - pad.right);
    const chartHeight = Math.max(1, height - pad.top - pad.bottom);
    const minTemp = Math.floor(Math.min(...minValues, ...maxValues) - 2);
    const maxTemp = Math.ceil(Math.max(...minValues, ...maxValues) + 2);
    const maxRain = Math.max(1, ...rainValues);
    const x = index => pad.left + index / Math.max(1, labels.length - 1) * chartWidth;
    const yTemp = value => pad.top + (maxTemp - value) / Math.max(1, maxTemp - minTemp) * chartHeight;
    const yRain = value => pad.top + chartHeight - (value / maxRain) * chartHeight;
    const css = getComputedStyle(document.body);
    const textColor = css.getPropertyValue('--muted').trim() || '#7890ad';
    const gridColor = document.body.dataset.theme === 'light' ? 'rgba(25,78,132,.11)' : 'rgba(126,195,255,.10)';
    ctx.clearRect(0, 0, width, height);
    ctx.font = '600 11px Inter, system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    for (let row = 0; row <= 4; row += 1) {
        const y = pad.top + row / 4 * chartHeight;
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(pad.left, y);
        ctx.lineTo(width - pad.right, y);
        ctx.stroke();
        ctx.fillStyle = textColor;
        ctx.textAlign = 'right';
        ctx.fillText(`${Math.round(maxTemp - row / 4 * (maxTemp - minTemp))}°`, pad.left - 8, y);
    }
    ctx.font = '600 10px Inter, system-ui, sans-serif';
    ctx.fillStyle = textColor;
    ctx.textAlign = 'left';
    for (let row = 0; row <= 4; row += 1) {
        const y = pad.top + row / 4 * chartHeight;
        const value = maxRain - row / 4 * maxRain;
        ctx.fillText(`${value.toLocaleString(appLocale(), { maximumFractionDigits: maxRain < 10 ? 1 : 0 })} mm`, width - pad.right + 7, y);
    }
    drawChartAxisTitle(ctx, chartUnitAxisLabel(t('history.yrain.temperature'), unitLabel()), 11, pad.top + chartHeight / 2, { rotate: -Math.PI / 2 });
    drawChartAxisTitle(ctx, chartUnitAxisLabel(t('history.yrain.precipitation'), 'mm'), width - 11, pad.top + chartHeight / 2, { rotate: Math.PI / 2 });
    drawChartAxisTitle(ctx, t('history.renderhistory.date'), pad.left + chartWidth / 2, height - 7);
    const barWidth = Math.max(2, Math.min(14, chartWidth / Math.max(1, labels.length) * .62));
    rainValues.forEach((value, index) => {
        const top = yRain(value);
        const gradient = ctx.createLinearGradient(0, top, 0, pad.top + chartHeight);
        gradient.addColorStop(0, 'rgba(126,105,255,.72)');
        gradient.addColorStop(1, 'rgba(55,141,255,.08)');
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.roundRect(x(index) - barWidth / 2, top, barWidth, Math.max(1, pad.top + chartHeight - top), [4, 4, 0, 0]);
        ctx.fill();
    });
    const plot = (values, color, widthValue, dashed = false) => {
        ctx.save();
        if (dashed)
            ctx.setLineDash([6, 5]);
        ctx.beginPath();
        values.forEach((value, index) => {
            if (index === 0)
                ctx.moveTo(x(index), yTemp(value));
            else
                ctx.lineTo(x(index), yTemp(value));
        });
        ctx.strokeStyle = color;
        ctx.lineWidth = widthValue;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.restore();
    };
    plot(maxValues, '#48d2ff', 2.8);
    plot(minValues, '#ffbd65', 2.2, true);
    const labelStep = Math.max(1, Math.ceil(labels.length / (width < 620 ? 5 : 9)));
    labels.forEach((label, index) => {
        if (index % labelStep !== 0 && index !== labels.length - 1)
            return;
        ctx.fillStyle = textColor;
        ctx.textAlign = index === 0 ? 'left' : index === labels.length - 1 ? 'right' : 'center';
        ctx.fillText(formatHistoryDay(label, { short: true }), x(index), height - 12);
    });
    registerChartInteraction(canvas, {
        title: t("history.plot.historical_trend"), labels, pad,
        labelFormatter: value => formatHistoryDay(value),
        series: [
            { label: t("history.plot.maximum_temperature"), values: maxValues, unit: unitLabel(), color: '#48d2ff', digits: 1 },
            { label: t("history.plot.low_temperature"), values: minValues, unit: unitLabel(), color: '#ffbd65', digits: 1 },
            { label: t("history.yrain.precipitation"), values: rainValues, unit: ' mm', color: '#8779ff', digits: 1 }
        ]
    });
}

    return Object.freeze({
        historyDateValue, historyDateOffset, historyDateObject, historyLocationKey, ensureHistoryRange, setHistoryRange, historyRangeDays, validateHistoryRange, buildHistoricalWeatherURL, createPreviewHistory, loadHistory, historyAverage, historyMaximum, historyTotal, formatHistoryDay, renderHistory, drawHistoryChart
    });
}
