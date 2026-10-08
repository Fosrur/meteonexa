export const serviceNames = Object.freeze(['suiteHistory']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.suiteHistory = Object.freeze({
        create(context) {
            const { CONFIG, suite, state, q, n, clamp, safe, mean, loader, fetchJson, dateInput, addDays, toast, meteonexaText, locationLabel, tempText } = context;
    function historicalUrl(start, end) { const p = new URLSearchParams({ latitude: state.location.latitude, longitude: state.location.longitude, start_date: start, end_date: end, daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,snowfall_sum,wind_gusts_10m_max', timezone: 'auto', wind_speed_unit: 'kmh' }); return `${CONFIG.HISTORICAL_API}?${p}`; }
    function previousRunsUrl(start, end) { const p = new URLSearchParams({ latitude: state.location.latitude, longitude: state.location.longitude, start_date: start, end_date: end, hourly: 'temperature_2m,temperature_2m_previous_day1,precipitation,precipitation_previous_day1', timezone: 'auto' }); return `${CONFIG.PREVIOUS_RUNS_API}?${p}`; }
    async function loadEnhancedHistory() {
        const start = q('#history-start').value, end = q('#history-end').value;
        if (!start || !end)
            return;
        const days = Math.round((new Date(end) - new Date(start)) / 86400000) + 1;
        if (days < 1 || days > 366) {
            toast("" + meteonexaText("history.loadhistory.invalid_date_range"), meteonexaText("suite.loadenhancedhistory.select_da_1_366_days"), 'warning');
            return;
        }
        await loader("" + meteonexaText("history.task.loading_history"), meteonexaText("suite.loadenhancedhistory.comparing_observations_previous_years_forecasts"), async () => {
            const priorStart = dateInput(addDays(new Date(`${start}T12:00:00`), -365)), priorEnd = dateInput(addDays(new Date(`${end}T12:00:00`), -365));
            const climateYears = [];
            for (let year = 2; year <= Math.min(6, Math.max(3, Math.floor(366 / Math.max(days, 1)) + 2)); year++)
                climateYears.push([dateInput(addDays(new Date(`${start}T12:00:00`), -365 * year)), dateInput(addDays(new Date(`${end}T12:00:00`), -365 * year))]);
            const accuracyEnd = dateInput(addDays(new Date(), -5)), accuracyStart = dateInput(addDays(new Date(), -15));
            const requests = [fetchJson(historicalUrl(start, end), { credentials: 'omit', timeout: 24000 }), fetchJson(historicalUrl(priorStart, priorEnd), { credentials: 'omit', timeout: 24000 }), fetchJson(previousRunsUrl(accuracyStart, accuracyEnd), { credentials: 'omit', timeout: 24000 }), ...climateYears.map(([a, b]) => fetchJson(historicalUrl(a, b), { credentials: 'omit', timeout: 24000 }))];
            const results = await Promise.allSettled(requests);
            if (results[0].status !== 'fulfilled')
                throw new Error("" + meteonexaText("history.task.historical_data_unavailable"));
            suite.history = { current: results[0].value, prior: results[1].status === 'fulfilled' ? results[1].value : null, accuracy: results[2].status === 'fulfilled' ? results[2].value : null, climate: results.slice(3).flatMap(r => r.status === 'fulfilled' ? [r.value] : []), start, end };
            renderHistory();
        });
        toast("" + meteonexaText("history.task.history_updated"), meteonexaText("suite.loadenhancedhistory.analysed_value_days_comparison_periods", { p0: days }));
    }
    function dailyStats(data) {
        const d = data?.daily;
        if (!d?.time?.length)
            return null;
        const max = (d.temperature_2m_max || []).map(n), min = (d.temperature_2m_min || []).map(n), rain = (d.precipitation_sum || []).map(n), gust = (d.wind_gusts_10m_max || []).map(n);
        let dry = 0, longest = 0;
        rain.forEach(value => {
            if (value < .1) {
                dry++;
                longest = Math.max(longest, dry);
            }
            else
                dry = 0;
        });
        return { days: d.time.length, maxAverage: mean(max), minAverage: mean(min), meanTemperature: mean(max.map((v, i) => (v + min[i]) / 2)), rainTotal: rain.reduce((a, b) => a + b, 0), maxRecord: Math.max(...max), minRecord: Math.min(...min), maxGust: Math.max(0, ...gust), drySpell: longest };
    }
    function accuracyStats(data) {
        const h = data?.hourly;
        if (!h?.time?.length)
            return null;
        const tempErrors = (h.temperature_2m || []).map((v, i) => Math.abs(n(v) - n(h.temperature_2m_previous_day1?.[i]))).filter(Number.isFinite), rainErrors = (h.precipitation || []).map((v, i) => Math.abs(n(v) - n(h.precipitation_previous_day1?.[i]))).filter(Number.isFinite);
        if (!tempErrors.length)
            return null;
        return { tempMae: mean(tempErrors), rainMae: mean(rainErrors), score: Math.round(clamp(100 - mean(tempErrors) * 14 - mean(rainErrors) * 2, 30, 99)) };
    }
    function historyChart(d) { const max = d.temperature_2m_max.map(n), min = d.temperature_2m_min.map(n), rain = d.precipitation_sum.map(n), all = [...max, ...min], lo = Math.min(...all) - 2, hi = Math.max(...all) + 2, range = Math.max(1, hi - lo), count = d.time.length, x = i => 40 + i / Math.max(1, count - 1) * 920, y = v => 30 + (hi - v) / range * 220, pts = values => values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '), bars = rain.map((v, i) => { const height = Math.min(90, v * 7); return `<rect x="${(x(i) - 3).toFixed(1)}" y="${(260 - height).toFixed(1)}" width="6" height="${height.toFixed(1)}" rx="2"/>`; }).join(''); return `<svg viewBox="0 0 1000 300" preserveAspectRatio="none"><g class="history-rain-bars">${bars}</g><polyline class="history-line-min" points="${pts(min)}"/><polyline class="history-line-max" points="${pts(max)}"/></svg>`; }
    function renderHistory() {
        const data = suite.history?.current, stats = dailyStats(data);
        if (!stats)
            return;
        const prior = dailyStats(suite.history.prior);
        const climateRows = (suite.history.climate || []).map(dailyStats).filter(Boolean);
        const climateMean = climateRows.length ? mean(climateRows.map(row => row.meanTemperature)) : (prior?.meanTemperature ?? stats.meanTemperature);
        const anomaly = stats.meanTemperature - climateMean;
        const accuracy = accuracyStats(suite.history.accuracy);
        const set = (selector, value) => {
            const node = q(selector);
            if (node)
                node.textContent = value;
        };
        set('#history-location-name', locationLabel());
        set('#history-accuracy-score', accuracy ? `${accuracy.score}%` : '--%');
        set('#history-accuracy-note', accuracy ? meteonexaText("advanced.renderhistory.mean_temperature_error_value", { p0: accuracy.tempMae.toFixed(1) }) : meteonexaText("suite.set.historical_data_unavailable"));
        set('#suite-history-anomaly', `${anomaly >= 0 ? '+' : ''}${anomaly.toFixed(1)}°`);
        set('#suite-history-anomaly-note', meteonexaText("suite.set.compared_average_value_periods", { p0: climateRows.length + (prior ? 1 : 0) }));
        set('#suite-history-record', `${tempText(stats.maxRecord)} / ${tempText(stats.minRecord)}`);
        set('#suite-history-record-note', meteonexaText("suite.set.high_low_analysed_period"));
        set('#suite-history-dryspell', meteonexaText("history.renderhistory.value_days", { count: stats.drySpell }));
        set('#suite-history-rain-mae', accuracy ? `${accuracy.rainMae.toFixed(1)} mm` : "" + meteonexaText("history.renderhistory.mm"));
        const comparison = q('#suite-history-comparison');
        if (comparison)
            comparison.innerHTML = `<div class="suite-comparison-copy"><span class="suite-comparison-icon"><svg><use href="#i-chart"/></svg></span><div><span class="section-kicker">${safe(meteonexaText('suite.set.period_comparison'))}</span><h2>${safe(prior ? meteonexaText('suite.set.compared_same_period_previous_year') : meteonexaText('suite.set.climate_comparison_available'))}</h2><p>${safe(anomaly > 1 ? meteonexaText('suite.set.period_value_warmer_than_average', { p0: anomaly.toFixed(1) }) : anomaly < -1 ? meteonexaText('suite.set.period_value_cooler_than_average', { p0: Math.abs(anomaly).toFixed(1) }) : meteonexaText('suite.set.temperatures_remained_close_comparison_average'))}</p></div></div><div class="suite-comparison-grid"><span><small>${safe(meteonexaText('history.yrain.temperature'))} ${safe(meteonexaText('history.mean').toLowerCase())}</small><strong>${safe(tempText(stats.meanTemperature))}</strong></span><span><small>${safe(meteonexaText('suite.set.comparison_average'))}</small><strong>${safe(tempText(climateMean))}</strong></span><span><small>${safe(meteonexaText('history.renderhistory.rain'))} ${safe(meteonexaText('history.period').toLowerCase())}</small><strong>${stats.rainTotal.toFixed(1)} mm</strong></span><span><small>${safe(meteonexaText('history.renderhistory.rain'))} ${safe(meteonexaText('archive.previous_year').toLowerCase())}</small><strong>${prior ? `${prior.rainTotal.toFixed(1)} mm` : '--'}</strong></span></div>`;
    }
    function exportHistoryCsv() {
        const d = suite.history?.current?.daily;
        if (!d?.time?.length) {
            toast(meteonexaText("suite.exporthistorycsv.history_not_loaded"), meteonexaText("suite.exporthistorycsv.load_period_first"), 'warning');
            return;
        }
        const rows = [["" + meteonexaText("history.renderhistory.date"), meteonexaText("suite.exporthistorycsv.weather_code"), meteonexaText("suite.exporthistorycsv.minimum_temperature_c"), meteonexaText("suite.exporthistorycsv.maximum_temperature_c"), meteonexaText("suite.exporthistorycsv.rain_mm"), meteonexaText("suite.exporthistorycsv.snow_cm"), meteonexaText("suite.exporthistorycsv.gusts_kmh")], ...d.time.map((date, i) => [date, d.weather_code[i], d.temperature_2m_min[i], d.temperature_2m_max[i], d.precipitation_sum[i], d.snowfall_sum?.[i] ?? 0, d.wind_gusts_10m_max[i]])];
        const csv = rows.map(row => row.map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
        const blob = new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' }), url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url;
        a.download = `meteonexa-history-${suite.history.start}-${suite.history.end}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }


            return Object.freeze({ loadEnhancedHistory, exportHistoryCsv });
        }
    });
}

export function install(services, host = globalThis) {
    return services.installModule({ services, host, provides: serviceNames, dependencies, factory });
}
