'use strict';
(() => {
    const CONFIG = window.METEONEXA_CONFIG;
    if (!CONFIG)
        throw new Error('METEONEXA_CONFIG_NOT_LOADED');
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const APP_RUNTIME = SERVICES.require('runtimeApi').get();
    const {
        loadJSON, saveJSON, showToast, withLoader, loadWeather, loadForecastFusion,
        updateThreshold, currentHourlyIndex, temperature, weatherSummary, appLocale,
        average, renderAll, formatClock, weatherMeta, weatherArt
    } = APP_RUNTIME;
    const PREVIEW_MODE = APP_RUNTIME.previewMode === true;
    const ADVANCED_BUILD = '20.1';
    const SNAPSHOT_KEY = 'meteonexa_v15_forecast_snapshot';
    const ALERT_PROFILE_KEY = 'meteonexa_v15_alert_profile';
    const MODEL_MAX_AGE = 15 * 60 * 1000;
    const HISTORY_MAX_DAYS = 366;
    const modelState = { rows: [], locationKey: '', fetchedAt: 0, request: null, modelsExpected: 0 };
    const historyState = { data: null, accuracy: null, request: null };
    const routeState = { points: [], request: null };
    let activeRadarLayer = "radar";
    let radarLayerSelectionVersion = 0;
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
    // Advanced is loaded after js/app.js, but production must fail closed if a
    // critical bundle is unavailable. Avoid a secondary ReferenceError cascade
    // ("state is not defined") and let the page/error telemetry expose the
    // actual missing asset instead.
    const appState = () => APP_RUNTIME.getState();
    const safe = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
    const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;
    const avg = values => {
        const clean = values.map(Number).filter(Number.isFinite);
        return clean.length ? clean.reduce((sum, value) => sum + value, 0) / clean.length : 0;
    };
    const std = values => {
        const clean = values.map(Number).filter(Number.isFinite);
        if (clean.length < 2)
            return 0;
        const mean = avg(clean);
        return Math.sqrt(clean.reduce((sum, value) => sum + ((value - mean) ** 2), 0) / clean.length);
    };
    const locationKey = () => `${num(appState().location?.latitude).toFixed(4)}:${num(appState().location?.longitude).toFixed(4)}`;
    const localizedLocationPart = (value, key) => {
        const current = String(value || '').trim();
        if (current && current !== key)
            return current;
        const translated = String(window.meteonexaText?.(key) || '').trim();
        return translated && translated !== key ? translated : '';
    };
    const localLabel = () => [
        localizedLocationPart(appState().location?.name, CONFIG.DEFAULT_LOCATION.nameKey),
        localizedLocationPart(appState().location?.admin1, CONFIG.DEFAULT_LOCATION.admin1Key)
    ].filter(Boolean).join(', ') || "" + meteonexaText("locations.selectonboardinglocation.location_selected");
    const dateValue = date => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const addDays = (date, days) => { const value = new Date(date); value.setDate(value.getDate() + days); return value; };
    const currentLocale = () => typeof appLocale === 'function' ? appLocale() : (navigator.languages?.[0] || navigator.language || 'it-IT');
    const formatDate = value => new Intl.DateTimeFormat(currentLocale(), { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`));
    const formatShortDate = value => new Intl.DateTimeFormat(currentLocale(), { day: '2-digit', month: 'short' }).format(new Date(`${value}T12:00:00`));
    const formatTime = value => {
        if (!value)
            return '--:--';
        if (typeof formatClock === 'function')
            return formatClock(value);
        const date = value instanceof Date ? value : new Date(value);
        return new Intl.DateTimeFormat(currentLocale(), { hour: '2-digit', minute: '2-digit' }).format(date);
    };
    const km = value => `${Math.round(num(value))} km/h`;
    const temp = value => typeof temperature === 'function' ? temperature(value) : `${Math.round(num(value))}°`;
    const fetchFresh = async (url, timeout = 16000) => {
        const parsed = new URL(url, location.href);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const sameOrigin = parsed.origin === location.origin;
            const response = await fetch(parsed.toString(), {
                cache: 'no-store', credentials: sameOrigin ? 'same-origin' : 'omit', signal: controller.signal,
                headers: { Accept: 'application/json', ...(sameOrigin ? (SERVICES.get('security')?.headers?.() || {}) : {}) }
            });
            if (!response.ok)
                throw new Error(meteonexaText("suite.apimessage.service_unavailable_value", { p0: response.status }));
            return await response.json();
        }
        catch (error) {
            if (error?.name === 'AbortError')
                throw new Error(meteonexaText('network.request.timeout'));
            if (error instanceof TypeError)
                throw new Error(meteonexaText('network.request.failed'));
            throw error;
        }
        finally {
            clearTimeout(timer);
        }
    };
    const toast = (title, copy, type = 'success') => typeof showToast === 'function' ? showToast(title, copy, type) : console.log(title, copy);
    const runLoader = (title, copy, action) => typeof withLoader === 'function' ? withLoader(title, copy, action, 420) : action();
    const suiteOwnsAdvanced = () => typeof SERVICES.get('suite')?.refresh === 'function';
    function hourlyIndex(data = appState().weather, date = new Date()) {
        const times = data?.hourly?.time || [];
        if (!times.length)
            return 0;
        const target = date.getTime();
        let best = 0;
        let distance = Infinity;
        times.forEach((time, index) => {
            const next = Math.abs(new Date(time).getTime() - target);
            if (next < distance) {
                distance = next;
                best = index;
            }
        });
        return best;
    }
    function extractNowcast() {
        const weather = appState().weather;
        const series = weather?.minutely_15;
        let points = [];
        if (series?.time?.length) {
            const start = hourlyIndex({ hourly: { time: series.time } });
            points = series.time.slice(start, start + 9).map((time, offset) => ({
                time,
                precipitation: num(series.precipitation?.[start + offset] ?? series.rain?.[start + offset]),
                code: num(series.weather_code?.[start + offset] ?? weather.current?.weather_code)
            }));
        }
        else if (weather?.hourly?.time?.length) {
            const start = typeof currentHourlyIndex === 'function' ? currentHourlyIndex(weather) : hourlyIndex(weather);
            points = weather.hourly.time.slice(start, start + 3).map((time, offset) => ({
                time,
                precipitation: num(weather.hourly.precipitation?.[start + offset]),
                code: num(weather.hourly.weather_code?.[start + offset] ?? weather.current?.weather_code)
            }));
        }
        const wet = points.map((point, index) => point.precipitation >= .05 ? index : -1).filter(index => index >= 0);
        const first = wet.length ? wet[0] : -1;
        let last = first;
        if (first >= 0) {
            for (let index = first + 1; index < points.length; index += 1) {
                if (points[index].precipitation < .05)
                    break;
                last = index;
            }
        }
        const peak = points.length ? Math.max(...points.map(point => point.precipitation)) : 0;
        const total = points.reduce((sum, point) => sum + point.precipitation, 0);
        return {
            points, first, last, peak, total, raining: first === 0,
            start: first >= 0 ? points[first]?.time : '',
            end: last >= 0 && last + 1 < points.length ? points[last + 1]?.time : '',
            intensity: peak >= 2 ? "" + meteonexaText("intelligence.extractnowcast.heavy") : peak >= .7 ? "" + meteonexaText("intelligence.extractnowcast.moderate") : peak >= .05 ? "" + meteonexaText("intelligence.extractnowcast.light") : "" + meteonexaText("intelligence.extractnowcast.none")
        };
    }
    function summarizeServerModel(model, definition) {
        const rows = Array.isArray(model?.rows) ? model.rows : [];
        if (!rows.length)
            return null;
        const now = Date.now();
        let start = rows.findIndex(row => new Date(row.time).getTime() >= now - 30 * 60 * 1000);
        if (start < 0)
            start = 0;
        const windowRows = rows.slice(start, start + 24);
        const temperatures = windowRows.map(row => Number(row.temperature)).filter(Number.isFinite);
        if (!temperatures.length)
            return null;
        const precipitation = windowRows.map(row => Number(row.precipitation)).filter(Number.isFinite);
        const gusts = windowRows.map(row => Number(row.windGust)).filter(Number.isFinite);
        const firstRain = windowRows.find(row => Number(row.precipitation) >= .1);
        return {
            ...definition,
            serverOwned: true,
            stale: model?.stale === true,
            maxTemp: Math.max(...temperatures), minTemp: Math.min(...temperatures),
            rain: precipitation.reduce((sum, value) => sum + value, 0),
            wind: gusts.length ? Math.max(...gusts) : 0,
            firstRain: firstRain?.time || '',
            code: num(windowRows[0]?.weatherCode ?? appState().weather?.current?.weather_code),
            temperatures: windowRows.slice(0, 12).map(row => Number(row.temperature)).filter(Number.isFinite),
            precipitation: windowRows.slice(0, 12).map(row => Math.max(0, Number(row.precipitation || 0)))
        };
    }
    function previewModels() {
        return [];
    }
    async function loadModels(force = false) {
        const key = locationKey();
        if (!force && modelState.locationKey === key && modelState.rows.length && Date.now() - modelState.fetchedAt < MODEL_MAX_AGE)
            return modelState.rows;
        if (modelState.request)
            return modelState.request;
        modelState.request = (async () => {
            if (typeof PREVIEW_MODE !== 'undefined' && PREVIEW_MODE) {
                modelState.rows = previewModels();
            }
            else {
                const definitions = new Map([
                    ['ecmwf', { id: 'ecmwf', name: meteonexaText('provider.ecmwf'), description: meteonexaText("advanced.loadmodels.european_model") }],
                    ['aifs', { id: 'aifs', name: meteonexaText('provider.aifs'), description: meteonexaText('model.aifs.description') }],
                    ['icon', { id: 'icon', name: meteonexaText('provider.icon'), description: meteonexaText('model.icon.description') }],
                    ['gfs', { id: 'gfs', name: meteonexaText('provider.gfs'), description: meteonexaText("intelligence.createpreviewintelligencemodels.noaa_global_model") }],
                    ['meteofrance', { id: 'meteofrance', name: meteonexaText('provider.meteofrance'), description: meteonexaText('accuracy.preview.meteofrance') }],
                    ['ukmo', { id: 'ukmo', name: meteonexaText('provider.ukmo'), description: meteonexaText('accuracy.preview.ukmo') }]
                ]);
                const fusion = typeof loadForecastFusion === 'function' ? await loadForecastFusion({ force }) : null;
                const serverModels = Array.isArray(fusion?.models) ? fusion.models : [];
                modelState.modelsExpected = Number(fusion?.modelsExpected || serverModels.length || 0);
                modelState.rows = serverModels.flatMap(model => {
                    const id = String(model?.id || '');
                    const definition = definitions.get(id) || { id, name: String(model?.label || id.toUpperCase()), description: meteonexaText('model.description.server_owned') };
                    const summary = summarizeServerModel(model, definition);
                    return summary ? [summary] : [];
                });
            }
            modelState.locationKey = key;
            modelState.fetchedAt = Date.now();
            return modelState.rows;
        })();
        try {
            return await modelState.request;
        }
        finally {
            modelState.request = null;
        }
    }
    function confidence() {
        const models = modelState.rows;
        if (!models.length)
            return { score: 60, label: "" + meteonexaText("intelligence.confidencefrommodels.waiting_models"), agreement: "" + meteonexaText("intelligence.confidencefrommodels.unavailable") };
        const tempSpread = Math.max(...models.map(m => m.maxTemp)) - Math.min(...models.map(m => m.maxTemp));
        const rainSpread = std(models.map(m => m.rain));
        const windSpread = Math.max(...models.map(m => m.wind)) - Math.min(...models.map(m => m.wind));
        const score = Math.round(Math.max(38, Math.min(98, 97 - tempSpread * 6 - Math.min(22, rainSpread * 5) - Math.min(18, windSpread * .8) - Math.max(0, Number(modelState.modelsExpected || models.length) - models.length) * 3)));
        if (score >= 82)
            return { score, label: "" + meteonexaText("intelligence.confidencefrommodels.very_high_confidence"), agreement: "" + meteonexaText("intelligence.confidencefrommodels.very_high") };
        if (score >= 66)
            return { score, label: "" + meteonexaText("intelligence.confidencefrommodels.good_confidence"), agreement: "" + meteonexaText("intelligence.confidencefrommodels.good") };
        return { score, label: "" + meteonexaText("intelligence.confidencefrommodels.variable_forecast"), agreement: "" + meteonexaText("intelligence.confidencefrommodels.variable") };
    }
    function createSnapshot() {
        const hourly = appState().weather?.hourly;
        if (!hourly?.time?.length)
            return null;
        const start = typeof currentHourlyIndex === 'function' ? currentHourlyIndex(appState().weather) : hourlyIndex(appState().weather);
        const end = Math.min(hourly.time.length, start + 24);
        const temperatures = (hourly.temperature_2m || []).slice(start, end).map(Number);
        const rain = (hourly.precipitation || []).slice(start, end).map(Number);
        const gusts = (hourly.wind_gusts_10m || []).slice(start, end).map(Number);
        const first = rain.findIndex(v => v >= .1);
        return { key: locationKey(), at: Date.now(), maxTemp: Math.max(...temperatures.filter(Number.isFinite)), rain: rain.reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0), wind: Math.max(0, ...gusts.filter(Number.isFinite)), firstRain: first >= 0 ? hourly.time[start + first] : '' };
    }
    function updateSnapshot() {
        const next = createSnapshot();
        if (!next)
            return;
        const previous = loadJSON(SNAPSHOT_KEY, null);
        if (previous?.key === next.key && Date.now() - num(previous.at) > 60 * 1000)
            window.__meteoPreviousSnapshot = previous;
        saveJSON(SNAPSHOT_KEY, next);
    }
    function changes() {
        const current = createSnapshot();
        const previous = window.__meteoPreviousSnapshot || loadJSON(SNAPSHOT_KEY, null);
        if (!current || !previous || previous.key !== current.key || Math.abs(current.at - previous.at) < 60000)
            return [{ icon: 'i-refresh', title: "" + meteonexaText("intelligence.buildforecastchanges.first_analysis_available"), copy: meteonexaText("advanced.changes.i_will_compare_next_updates_explain_what_changes") }];
        const rows = [];
        const rain = current.rain - previous.rain;
        const temperature = current.maxTemp - previous.maxTemp;
        const wind = current.wind - previous.wind;
        if (previous.firstRain && current.firstRain) {
            const minutes = Math.round((new Date(current.firstRain) - new Date(previous.firstRain)) / 60000);
            if (Math.abs(minutes) >= 30)
                rows.push({ icon: 'i-clock', title: minutes < 0 ? "" + meteonexaText("intelligence.buildforecastchanges.rain_brought_forward") : "" + meteonexaText("intelligence.buildforecastchanges.rain_delayed"), copy: meteonexaText("advanced.changes.forecast_time_changed_by_about_value_minutes", { p0: Math.abs(minutes) }) });
        }
        if (!previous.firstRain && current.firstRain)
            rows.push({ icon: 'i-umbrella', title: "" + meteonexaText("intelligence.buildforecastchanges.new_chance_rain"), copy: meteonexaText("intelligence.rain_now_expected_from_value", { time: formatTime(current.firstRain) }) });
        if (previous.firstRain && !current.firstRain)
            rows.push({ icon: 'i-sun', title: "" + meteonexaText("intelligence.rain_no_longer_expected"), copy: meteonexaText("advanced.changes.rain_no_longer_expected_within_next_24_hours") });
        if (Math.abs(rain) >= .5)
            rows.push({ icon: 'i-droplet', title: rain > 0 ? "" + meteonexaText("intelligence.buildforecastchanges.rain_total_increasing") : "" + meteonexaText("intelligence.buildforecastchanges.rain_total_decreasing"), copy: meteonexaText('change.amount.mm', { value: Math.abs(rain).toFixed(1) }) });
        if (Math.abs(temperature) >= 1)
            rows.push({ icon: 'i-sun', title: temperature > 0 ? "" + meteonexaText("intelligence.buildforecastchanges.high_temperature_rising") : "" + meteonexaText("intelligence.buildforecastchanges.high_temperature_falling"), copy: meteonexaText('change.amount.temp', { value: Math.abs(temperature).toFixed(1) }) });
        if (Math.abs(wind) >= 8)
            rows.push({ icon: 'i-wind', title: wind > 0 ? "" + meteonexaText("intelligence.buildforecastchanges.stronger_wind") : "" + meteonexaText("intelligence.buildforecastchanges.wind_easing"), copy: meteonexaText('change.amount.wind', { value: Math.round(Math.abs(wind)) }) });
        return rows.length ? rows.slice(0, 3) : [{ icon: 'i-shield', title: "" + meteonexaText("intelligence.buildforecastchanges.stable_forecast"), copy: "" + meteonexaText("intelligence.no_important_change_since_previous_update") }];
    }
    function sparkline(model) {
        const values = model.temperatures || [];
        if (!values.length)
            return '';
        const min = Math.min(...values), max = Math.max(...values), range = Math.max(1, max - min);
        const points = values.map((v, i) => `${(i / Math.max(1, values.length - 1) * 100).toFixed(1)},${(40 - (v - min) / range * 28).toFixed(1)}`).join(' ');
        const bars = (model.precipitation || []).map((v, i) => { const x = i / Math.max(1, values.length) * 100, h = Math.max(1, Math.min(15, num(v) * 7)); return `<rect x="${x.toFixed(1)}" y="${(48 - h).toFixed(1)}" width="${Math.max(2, 80 / values.length).toFixed(1)}" height="${h.toFixed(1)}" rx="1"/>`; }).join('');
        return `<svg viewBox="0 0 100 50" preserveAspectRatio="none"><g>${bars}</g><polyline points="${points}"/></svg>`;
    }
    function renderDecisionSupport() {
        if (!q('#advanced-decision-list') || !appState().weather?.hourly)
            return;
        const now = extractNowcast();
        const h = appState().weather.hourly;
        const start = typeof currentHourlyIndex === 'function' ? currentHourlyIndex(appState().weather) : hourlyIndex(appState().weather);
        const rain = Math.max(0, ...(h.precipitation_probability || []).slice(start, start + 12).map(num));
        const wind = Math.max(0, ...(h.wind_gusts_10m || []).slice(start, start + 12).map(num));
        const uv = Math.max(0, ...(h.uv_index || []).slice(start, start + 12).map(num));
        const domScore = Number.parseInt(q('#advanced-confidence-score')?.textContent || '', 10);
        const fallbackRating = confidence();
        const score = Number.isFinite(domScore) ? domScore : fallbackRating.score;
        const label = String(q('#advanced-confidence-label')?.textContent || fallbackRating.label || '').trim();
        const decisions = [
            ['i-umbrella', meteonexaText("intelligence.renderintelligencedecisions.umbrella"), now.first >= 0 || rain >= 55 ? meteonexaText("intelligence.renderintelligencedecisions.recommended.variant_2") : meteonexaText("intelligence.renderintelligencedecisions.not_needed"), now.first >= 0 ? meteonexaText("intelligence.rain_possible_within_2_hours") : meteonexaText("intelligence.renderintelligencedecisions.maximum_probability_value", { value: Math.round(rain) })],
            ['i-wind', meteonexaText("intelligence.renderintelligencedecisions.outdoor_activities"), wind >= 55 ? meteonexaText("intelligence.renderintelligencedecisions.use_caution") : meteonexaText("intelligence.renderintelligencedecisions.favourable_conditions"), meteonexaText("intelligence.gusts_up_value_km_h", { value: Math.round(wind) })],
            ['i-sun', meteonexaText("intelligence.renderintelligencedecisions.sun_protection"), uv >= 6 ? meteonexaText("intelligence.renderintelligencedecisions.recommended") : meteonexaText("intelligence.renderintelligencedecisions.limited_risk"), meteonexaText("intelligence.maximum_uv_index_value", { value: uv.toFixed(1) })],
            ['i-shield', meteonexaText("intelligence.renderintelligencedecisions.confidence"), `${score}/100`, label]
        ];
        q('#advanced-decision-list').innerHTML = decisions.map(item => `<article><span><svg><use href="#${item[0]}"/></svg></span><div><strong>${safe(item[1])}</strong><small>${safe(item[3])}</small></div><b>${safe(item[2])}</b></article>`).join('');
    }
    function renderAdvanced() {
        if (!q('#page-advanced') || !appState().weather)
            return;
        const now = extractNowcast();
        const message = now.first < 0 ? ["" + meteonexaText("intelligence.nowcastmessage.no_significant_rain"), meteonexaText("advanced.renderadvanced.no_significant_precipitation_expected_over_next_2_hours")] : now.raining ? ["" + meteonexaText("intelligence.nowcastmessage.rain_progress"), now.end ? meteonexaText("intelligence.rain_should_ease_around_value", { time: formatTime(now.end) }) : meteonexaText("advanced.renderadvanced.may_continue_beyond_next_2_hours")] : ["" + meteonexaText("intelligence.nowcastmessage.rain_possible_soon"), now.end ? meteonexaText("intelligence.rain_possible_from_value_value", { start: formatTime(now.start), end: formatTime(now.end) }) : meteonexaText("advanced.renderadvanced.possible_rain_from_value", { p0: formatTime(now.start) })];
        q('#advanced-nowcast-message').innerHTML = `<span><svg><use href="#${now.first >= 0 ? 'i-umbrella' : 'i-shield'}"/></svg></span><div><strong>${safe(message[0])}</strong><p>${safe(message[1])}</p></div>`;
        q('#advanced-rain-start').textContent = now.first >= 0 ? (now.raining ? "" + meteonexaText("intelligence.renderintelligence.progress") : formatTime(now.start)) : "" + meteonexaText("intelligence.renderintelligence.not_expected");
        q('#advanced-rain-end').textContent = now.first < 0 ? '--' : (now.end ? formatTime(now.end) : meteonexaText('forecast.beyond_two_hours'));
        q('#advanced-rain-peak').textContent = now.intensity;
        q('#advanced-rain-total').textContent = `${now.total.toFixed(1)} mm`;
        q('#advanced-nowcast-timeline').innerHTML = now.points.map(point => `<div style="--level:${Math.min(1, point.precipitation / 2).toFixed(3)}"><span><i></i></span><strong>${safe(formatTime(point.time))}</strong><small>${point.precipitation.toFixed(1)} mm</small></div>`).join('');
        const rating = confidence();
        q('#advanced-confidence-ring').style.setProperty('--score', rating.score);
        q('#advanced-confidence-score').textContent = rating.score;
        q('#advanced-confidence-label').textContent = rating.label;
        q('#advanced-confidence-badge').textContent = rating.score >= 82 ? meteonexaText('confidence.very_high') : rating.score >= 66 ? "" + meteonexaText("weather.metricnotevisibility.good") : "" + meteonexaText("intelligence.confidencefrommodels.variable");
        q('#advanced-confidence-models').textContent = meteonexaText("intelligence.renderintelligence.value_3", { count: modelState.rows.length });
        q('#advanced-confidence-agreement').textContent = rating.agreement;
        q('#advanced-confidence-updated').textContent = modelState.fetchedAt ? formatTime(new Date(modelState.fetchedAt)) : '--:--';
        q('#advanced-confidence-description').textContent = rating.score >= 82 ? meteonexaText("advanced.renderadvanced.models_agree_next_24_hour_forecast_stable") : rating.score >= 66 ? "" + meteonexaText("intelligence.forecasts_broadly_aligned_limited_differences") : "" + meteonexaText("intelligence.models_diverge_check_updates_more_frequently");
        q('#advanced-change-list').innerHTML = changes().map(item => `<article><span><svg><use href="#${item.icon}"/></svg></span><div><strong>${safe(item.title)}</strong><p>${safe(item.copy)}</p></div></article>`).join('');
        renderDecisionSupport();
        const expectedModels = Math.max(1, Number(modelState.modelsExpected || modelState.rows.length || 1));
        const completeModels = modelState.rows.length >= expectedModels;
        q('#advanced-model-status').textContent = completeModels ? "" + meteonexaText("intelligence.rendermodelcomparison.3_models_updated") : meteonexaText("intelligence.rendermodelcomparison.value_models_available", { count: modelState.rows.length });
        q('#advanced-model-grid').innerHTML = modelState.rows.length ? modelState.rows.map(model => "" + "<article class=\"advanced-model-card\"><div class=\"model-head\"><span>" + safe(model.name.slice(0, 2)) + "</span><div><strong>" + safe(model.name) + "</strong><small>" + safe(model.description) + "</small></div><em>" + safe(weatherMeta(model.code, 1).label) + "</em></div><div class=\"model-chart\">" + sparkline(model) + ("" + "</div><div class=\"model-values\"><span><small>" + safe(meteonexaText("advanced.renderadvanced.high")) +"</small><strong>") + safe(temp(model.maxTemp)) + "</strong></span><span><small>" + safe(meteonexaText("intelligence.rendermodelcomparison.24h_rain")) +"</small><strong>" + model.rain.toFixed(1) + " mm</strong></span><span><small>" + safe(meteonexaText("intelligence.rendermodelcomparison.maximum_wind")) +"</small><strong>" + safe(km(model.wind)) + "</strong></span></div><p><svg><use href=\"#i-umbrella\"/></svg>" + safe(model.firstRain ? meteonexaText("intelligence.first_possible_rain_at_value", { time: formatTime(model.firstRain) }) : "" + meteonexaText("intelligence.no_relevant_rain_next_24_hours")) + "</p></article>").join('') : "" + "<div class=\"advanced-empty\">" + safe(meteonexaText("advanced.renderadvanced.comparison_unavailable_at_moment")) + "</div>";
    }
    async function loadAdvanced(force = false, quiet = false) {
        if (!appState().weather)
            return;
        // js/suite.js is the canonical advanced engine in the production bundle.
        // Delegating here prevents the fallback renderer from painting first
        // and then being overwritten by the canonical server-owned renderer.
        if (suiteOwnsAdvanced()) {
            await SERVICES.get('suite').refresh(force);
            renderDecisionSupport();
            updateSnapshot();
            if (!quiet) {
                const count = q('#advanced-model-grid')?.querySelectorAll('.advanced-model-card').length || 0;
                toast(meteonexaText("intelligence.task.analysis_updated"), meteonexaText("advanced.loadadvanced.compared_value_models_value", { p0: count, p1: localLabel() }), count >= 3 ? 'success' : 'warning');
            }
            return;
        }
        await loadModels(force);
        renderAdvanced();
        updateSnapshot();
        if (!quiet)
            {
            const expectedModels = Math.max(1, Number(modelState.modelsExpected || modelState.rows.length || 1));
            toast("" + meteonexaText("intelligence.task.analysis_updated"), meteonexaText("advanced.loadadvanced.compared_value_models_value", { p0: modelState.rows.length, p1: localLabel() }), modelState.rows.length >= expectedModels ? 'success' : 'warning');
        }
    }
    function setHistoryRange(days = 30) { const end = addDays(new Date(), -5), start = addDays(end, -(Math.max(2, days) - 1)); q('#history-start').value = dateValue(start); q('#history-end').value = dateValue(end); qa('[data-history-days]').forEach(button => button.classList.toggle('active', num(button.dataset.historyDays) === days)); }
    function historicalUrl(start, end) { const params = new URLSearchParams({ latitude: String(appState().location.latitude), longitude: String(appState().location.longitude), start_date: start, end_date: end, daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_gusts_10m_max', timezone: 'auto', wind_speed_unit: 'kmh' }); return `${CONFIG.HISTORICAL_API}?${params}`; }
    function previousRunsUrl(start, end) { const params = new URLSearchParams({ latitude: String(appState().location.latitude), longitude: String(appState().location.longitude), start_date: start, end_date: end, hourly: 'temperature_2m,temperature_2m_previous_day1,precipitation,precipitation_previous_day1', timezone: 'auto' }); return `${CONFIG.PREVIOUS_RUNS_API}?${params}`; }
    async function loadHistory(force = false) {
        const start = q('#history-start').value, end = q('#history-end').value;
        if (!start || !end)
            return;
        const days = Math.round((new Date(end) - new Date(start)) / 86400000) + 1;
        if (days < 1 || days > HISTORY_MAX_DAYS) {
            toast("" + meteonexaText("history.loadhistory.invalid_date_range"), meteonexaText("advanced.loadhistory.select_da_1_value_days", { p0: HISTORY_MAX_DAYS }), 'warning');
            return;
        }
        if (historyState.request)
            return;
        historyState.request = runLoader("" + meteonexaText("history.task.loading_history"), meteonexaText("advanced.loadhistory.retrieving_data_checking_accuracy"), async () => {
            const [history, accuracy] = await Promise.allSettled([fetchFresh(historicalUrl(start, end), 20000), fetchFresh(previousRunsUrl(dateValue(addDays(new Date(), -12)), dateValue(addDays(new Date(), -5))), 20000)]);
            if (history.status !== 'fulfilled' || !history.value?.daily?.time?.length)
                throw new Error("" + meteonexaText("history.task.historical_data_unavailable"));
            historyState.data = history.value;
            historyState.accuracy = accuracy.status === 'fulfilled' ? accuracy.value : null;
            renderHistory();
        });
        try {
            await historyState.request;
            toast("" + meteonexaText("history.task.history_updated"), meteonexaText("history.value_days_loaded_value", { count: days, location: localLabel() }));
        }
        catch (error) {
            toast(meteonexaText("advanced.loadhistory.historical_data_unavailable"), error.message || meteonexaText("advanced.loadhistory.try_again_shortly"), 'error');
        }
        finally {
            historyState.request = null;
        }
    }
    function accuracyScore() {
        const h = historyState.accuracy?.hourly;
        if (!h?.time?.length)
            return null;
        const actual = h.temperature_2m || [], forecast = h.temperature_2m_previous_day1 || [];
        const errors = actual.map((v, i) => Math.abs(num(v) - num(forecast[i]))).filter(Number.isFinite);
        if (!errors.length)
            return null;
        const mae = avg(errors);
        return { score: Math.round(Math.max(35, Math.min(99, 100 - mae * 14))), mae };
    }
    function historySvg(daily) { const max = daily.temperature_2m_max.map(num), min = daily.temperature_2m_min.map(num), rain = daily.precipitation_sum.map(num), all = [...max, ...min], lo = Math.min(...all) - 2, hi = Math.max(...all) + 2, range = Math.max(1, hi - lo), count = daily.time.length, x = i => 40 + i / Math.max(1, count - 1) * 920, y = v => 30 + (hi - v) / range * 220, pts = values => values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '), bars = rain.map((v, i) => { const h = Math.min(90, v * 7); return `<rect x="${(x(i) - 3).toFixed(1)}" y="${(260 - h).toFixed(1)}" width="6" height="${h.toFixed(1)}" rx="2"/>`; }).join(''); return `<svg viewBox="0 0 1000 300" preserveAspectRatio="none"><g class="history-grid-lines"><line x1="40" y1="30" x2="960" y2="30"/><line x1="40" y1="140" x2="960" y2="140"/><line x1="40" y1="260" x2="960" y2="260"/></g><g class="history-rain-bars">${bars}</g><polyline class="history-line max" points="${pts(max)}"/><polyline class="history-line min" points="${pts(min)}"/></svg>`; }
    function renderHistory() {
        const daily = historyState.data?.daily;
        if (!daily?.time?.length)
            return;
        q('#history-location').textContent = localLabel();
        q('#history-max-average').textContent = temp(avg(daily.temperature_2m_max.map(num)));
        q('#history-min-average').textContent = temp(avg(daily.temperature_2m_min.map(num)));
        q('#history-rain-total').textContent = `${daily.precipitation_sum.map(num).reduce((a, b) => a + b, 0).toFixed(1)} mm`;
        const acc = accuracyScore();
        q('#history-accuracy-score').textContent = acc ? `${acc.score}%` : '--';
        q('#history-accuracy-note').textContent = acc ? meteonexaText("advanced.renderhistory.mean_temperature_error_value", { p0: acc.mae.toFixed(1) }) : meteonexaText("advanced.renderhistory.check_unavailable_period");
        q('#history-period-badge').textContent = `${formatShortDate(daily.time[0])} – ${formatShortDate(daily.time.at(-1))}`;
        q('#history-row-count').textContent = meteonexaText("history.renderhistory.value_days", { count: daily.time.length });
        q('#history-chart').innerHTML = historySvg(daily);
        q('#history-table').innerHTML = "" + "<div class=\"history-table-row head\"><span>" + safe(meteonexaText("history.renderhistory.date")) +"</span><span>" + safe(meteonexaText("history.renderhistory.condition")) +"</span><span>" + safe(meteonexaText("history.renderhistory.low_high")) +"</span><span>" + safe(meteonexaText("history.renderhistory.rain")) +"</span><span>" + safe(meteonexaText("history.renderhistory.gusts")) +"</span></div>" + daily.time.map((date, i) => `<div class="history-table-row"><span><strong>${safe(formatDate(date))}</strong><small>${safe(date)}</small></span><span class="condition">${weatherArt(daily.weather_code[i], 1)}<strong>${safe(weatherMeta(daily.weather_code[i], 1).label)}</strong></span><span><strong>${safe(temp(daily.temperature_2m_min[i]))} / ${safe(temp(daily.temperature_2m_max[i]))}</strong></span><span><strong>${num(daily.precipitation_sum[i]).toFixed(1)} mm</strong></span><span><strong>${safe(km(daily.wind_gusts_10m_max[i]))}</strong></span></div>`).join('');
    }
    function renderDevices() {
        if (!appState().weather)
            return;
        const current = appState().weather.current;
        q('#widget-location').textContent = localLabel();
        q('#widget-temp').textContent = temp(current.temperature_2m);
        q('#widget-condition').textContent = weatherMeta(current.weather_code, current.is_day).label;
        q('#widget-icon').innerHTML = weatherArt(current.weather_code, current.is_day);
        q('#widget-summary').textContent = weatherSummary(appState().weather);
        const h = appState().weather.hourly, start = typeof currentHourlyIndex === 'function' ? currentHourlyIndex(appState().weather) : hourlyIndex(appState().weather);
        q('#widget-hours').innerHTML = (h.time || []).slice(start, start + 4).map((time, i) => `<span><small>${safe(formatTime(time))}</small><strong>${safe(temp(h.temperature_2m[start + i]))}</strong></span>`).join('');
    }
    function removeAdvancedRadarLayers() {
        const map = appState().radar?.vectorMap;
        if (!map)
            return;
        ['meteonexa-satellite-layer', 'meteonexa-lightning-layer'].forEach(id => {
            try {
                if (map.getLayer(id))
                    map.removeLayer(id);
            }
            catch { }
        });
        ['meteonexa-satellite-source', 'meteonexa-lightning-source'].forEach(id => {
            try {
                if (map.getSource(id))
                    map.removeSource(id);
            }
            catch { }
        });
    }
    function satelliteDate() { return dateValue(addDays(new Date(), -1)); }
    async function setAdvancedRadarLayer(layer, selectionVersion = null, retryCount = 0) {
        if (selectionVersion === null)
            selectionVersion = ++radarLayerSelectionVersion;
        if (selectionVersion !== radarLayerSelectionVersion)
            return;
        activeRadarLayer = layer;
        qa('[data-advanced-radar-layer]').forEach(button => button.classList.toggle('active', button.dataset.advancedRadarLayer === layer));
        await ensureRadar();
        if (selectionVersion !== radarLayerSelectionVersion)
            return;
        const map = appState().radar?.vectorMap;
        if (!map || !appState().radar.vectorMapReady) {
            if (retryCount < 8)
                setTimeout(() => setAdvancedRadarLayer(layer, selectionVersion, retryCount + 1), 650);
            else
                toast(meteonexaText("advanced.setadvancedradarlayer.unavailable"), meteonexaText("advanced.setadvancedradarlayer.map_not_ready_yet_try_again_shortly"), 'warning');
            return;
        }
        removeAdvancedRadarLayers();
        if (layer === "radar") {
            setRadarMode('live', { notify: false, persist: false });
            q('#radar-source').textContent = meteonexaText("advanced.setadvancedradarlayer.observed_librewxr_radar");
            return;
        }
        if (layer === 'forecast') {
            setRadarMode('forecast', { notify: false, persist: false });
            q('#radar-source').textContent = meteonexaText("advanced.setadvancedradarlayer.precipitation_forecast_active");
            return;
        }
        removeRadarVectorLayer();
        appState().radar.mode = 'live';
        if (layer === 'satellite') {
            const date = satelliteDate(), tiles = `https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/VIIRS_SNPP_CorrectedReflectance_TrueColor/default/${date}/GoogleMapsCompatible_Level9/{z}/{y}/{x}.jpg`;
            map.addSource('meteonexa-satellite-source', { type: 'raster', tiles: [tiles], tileSize: 256, maxzoom: 9, attribution: meteonexaText('provider.nasa_gibs') });
            map.addLayer({ id: 'meteonexa-satellite-layer', type: 'raster', source: 'meteonexa-satellite-source', paint: { 'raster-opacity': .82, 'raster-fade-duration': 0 } });
            q('#radar-source').textContent = meteonexaText("advanced.setadvancedradarlayer.nasa_satellite_previous_day");
            return;
        }
        if (layer === 'lightning') {
            const lightningService = SERVICES.get('suiteIntegrations') || SERVICES.get('suite');
            if (lightningService?.showLiveLightning) {
                return lightningService.showLiveLightning();
            }
            q('#radar-source').textContent = meteonexaText("suite.bind.live_lightning_unavailable");
            toast(meteonexaText('lightning.live'), meteonexaText("advanced.setadvancedradarlayer.smtp_server_has_not_been_configured_yet"), 'warning');
        }
    }

    // Radar layer controls live inside content that can be repainted/rebound
    // during bootstrap. Delegate the click from document so Forecast/Satellite/
    // Lightning always keep a stable action path independent of DOM timing.
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-advanced-radar-layer]');
        if (!button)
            return;
        setAdvancedRadarLayer(button.dataset.advancedRadarLayer).catch(error => {
            console.warn('ADVANCED_RADAR_LAYER_FAILED', error);
            toast(meteonexaText("advanced.setadvancedradarlayer.unavailable"), error?.message || meteonexaText("advanced.setadvancedradarlayer.map_not_ready_yet_try_again_shortly"), 'warning');
        });
    });

    function openDay(index) {
        const d = appState().weather?.daily;
        if (!d?.time?.[index])
            return;
        let dialog = q('#advanced-day-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'advanced-day-dialog';
            dialog.className = 'app-dialog advanced-day-dialog';
            dialog.innerHTML = "" + "<form method=\"dialog\"><header><div><span class=\"section-kicker\">" + safe(meteonexaText("advanced.openday.daily_details")) + "</span><h2 id=\"advanced-day-title\"></h2></div><button class=\"round-button\" value=\"cancel\" aria-label=\"" + safe(meteonexaText("advanced.openday.close")) + "\"><svg><use href=\"#i-close\"/></svg></button></header><div id=\"advanced-day-content\" class=\"advanced-day-content\"></div><footer><button class=\"button outline-button\" value=\"cancel\">" + safe(meteonexaText("advanced.openday.close")) + "</button></footer></form>";
            document.body.append(dialog);
        }
        const meta = weatherMeta(d.weather_code[index], 1);
        q('#advanced-day-title').textContent = formatDate(d.time[index]);
        q('#advanced-day-content').innerHTML = "" + "<div class=\"day-detail-main\">" + weatherArt(d.weather_code[index], 1) + "<div><strong>" + safe(meta.label) + "</strong><span>" + safe(temp(d.temperature_2m_min[index])) + " / " + safe(temp(d.temperature_2m_max[index])) + "</span></div></div><div class=\"day-detail-grid\"><span><small>" + safe(meteonexaText("history.renderhistory.feels_like")) +"</small><strong>" + safe(temp(d.apparent_temperature_min[index])) + " / " + safe(temp(d.apparent_temperature_max[index])) + "</strong></span><span><small>" + safe(meteonexaText("history.renderhistory.rain")) +"</small><strong>" + Math.round(num(d.precipitation_probability_max[index])) + "% \u00B7 " + num(d.precipitation_sum[index]).toFixed(1) + " mm</strong></span><span><small>" + safe(meteonexaText("history.renderhistory.gusts")) +"</small><strong>" + safe(km(d.wind_gusts_10m_max[index])) + "</strong></span><span><small>" + safe(meteonexaText("visualization.take.uv_index")) +"</small><strong>" + num(d.uv_index_max[index]).toFixed(1) + "</strong></span></div>";
        dialog.showModal();
    }
    function applyAlertProfile(profile) { const profiles = { standard: { rain: 70, wind: 60, heat: 35 }, commute: { rain: 45, wind: 45, heat: 36 }, outdoor: { rain: 35, wind: 35, heat: 32 }, agriculture: { rain: 30, wind: 40, heat: 33 }, marine: { rain: 50, wind: 30, heat: 38 } }; const values = profiles[profile] || profiles.standard; saveJSON(ALERT_PROFILE_KEY, profile); appState().thresholds = { ...appState().thresholds, ...values }; q('#threshold-rain').value = values.rain; q('#threshold-wind').value = values.wind; q('#threshold-heat').value = values.heat; updateThreshold('rain', values.rain); updateThreshold('wind', values.wind); updateThreshold('heat', values.heat); qa('[data-alert-profile]').forEach(button => button.classList.toggle('active', button.dataset.alertProfile === profile)); toast(meteonexaText("advanced.applyalertprofile.alert_profile_updated"), meteonexaText("advanced.applyalertprofile.thresholds_adapted_selected_profile")); }
    function initDefaults() {
        if (q('#history-start') && !q('#history-start').value)
            setHistoryRange(30);
        const routeOrigin = q('#route-origin');
        if (routeOrigin) {
            const currentOrigin = String(routeOrigin.value || '').trim();
            const hasLegacyDefaultKey = currentOrigin.includes(CONFIG.DEFAULT_LOCATION.nameKey)
                || currentOrigin.includes(CONFIG.DEFAULT_LOCATION.admin1Key);
            if (!currentOrigin || hasLegacyDefaultKey)
                routeOrigin.value = localLabel();
        }
        if (q('#route-departure') && !q('#route-departure').value) {
            const date = new Date(Date.now() + 3600000);
            date.setMinutes(Math.ceil(date.getMinutes() / 15) * 15, 0, 0);
            q('#route-departure').value = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        }
        const profile = loadJSON(ALERT_PROFILE_KEY, 'standard');
        qa('[data-alert-profile]').forEach(button => button.classList.toggle('active', button.dataset.alertProfile === profile));
    }
    function bind() {
        q('#advanced-refresh')?.addEventListener('click', () => runLoader("" + meteonexaText("intelligence.task.updating_analysis"), meteonexaText("advanced.bind.comparing_latest_data"), async () => { await loadWeather({ force: true, silent: true }); await loadAdvanced(true, false); }));
        q('#route-swap')?.addEventListener('click', () => {
            const a = q('#route-origin'), b = q('#route-destination');
            if (!a || !b)
                return;
            const v = a.value;
            a.value = b.value;
            b.value = v;
            a.dispatchEvent(new Event('input', { bubbles: true }));
            b.dispatchEvent(new Event('input', { bubbles: true }));
        });
        qa('[data-alert-profile]').forEach(button => button.addEventListener('click', () => applyAlertProfile(button.dataset.alertProfile)));
        initDefaults();
    }
    async function onPage(page) {
        if (page === 'advanced') {
            // Navigation must commit immediately. Slow fusion/provider work is
            // represented by the article-level preloaders, never the global loader.
            if (!suiteOwnsAdvanced()) renderAdvanced();
            else renderDecisionSupport();
            loadAdvanced(false, true).then(() => {
                if (appState().currentPage !== 'advanced') return;
                if (!suiteOwnsAdvanced()) renderAdvanced();
                else renderDecisionSupport();
            }).catch(error => console.warn('ADVANCED_BACKGROUND_LOAD_FAILED', error));
        }
        if (page === 'history') {
            const node = q('#history-location-name') || q('#history-location');
            if (node)
                node.textContent = localLabel();
        }
        if (page === 'route')
            initDefaults();
        if (page === 'devices')
            renderDevices();
        if (page === "radar" && activeRadarLayer !== "radar")
            setTimeout(() => setAdvancedRadarLayer(activeRadarLayer), 500);
    }
    const lifecycleHooks = new Set();
    function registerLifecycleHook(hook) {
        if (!hook || typeof hook !== 'object') throw new TypeError('METEONEXA_ADVANCED_LIFECYCLE_HOOK_INVALID');
        const normalized = Object.freeze({
            onPage: typeof hook.onPage === 'function' ? hook.onPage : null,
            renderAll: typeof hook.renderAll === 'function' ? hook.renderAll : null,
            locationChanged: typeof hook.locationChanged === 'function' ? hook.locationChanged : null,
            afterRefresh: typeof hook.afterRefresh === 'function' ? hook.afterRefresh : null
        });
        lifecycleHooks.add(normalized);
        return () => lifecycleHooks.delete(normalized);
    }
    function runLifecycleHooks(name, ...args) {
        for (const hook of [...lifecycleHooks]) {
            try { hook[name]?.(...args); }
            catch (error) { console.warn(`ADVANCED_LIFECYCLE_HOOK_${String(name).toUpperCase()}_FAILED`, error); }
        }
    }
    async function runLifecycleHooksAsync(name, ...args) {
        for (const hook of [...lifecycleHooks]) {
            try { await hook[name]?.(...args); }
            catch (error) { console.warn(`ADVANCED_LIFECYCLE_HOOK_${String(name).toUpperCase()}_FAILED`, error); }
        }
    }
    function renderAllAdvanced() {
        // Hidden advanced panels are not painted during boot. This keeps a cached
        // payload from producing a visible first result before the live hydration.
        if (appState().currentPage === 'advanced') {
            if (suiteOwnsAdvanced())
                renderDecisionSupport();
            else
                renderAdvanced();
        }
        renderDevices();
        const node = q('#history-location-name') || q('#history-location');
        if (node)
            node.textContent = localLabel();
        if (!suiteOwnsAdvanced() && appState().currentPage === 'advanced' && appState().weather && !modelState.rows.length && !modelState.request)
            loadAdvanced(false, true).catch(error => console.warn('ADVANCED_MODEL_BACKGROUND_LOAD_FAILED', error));
        runLifecycleHooks('renderAll');
    }
    function locationChanged() {
        modelState.rows = []; modelState.modelsExpected = 0; modelState.locationKey = ''; historyState.data = null; historyState.accuracy = null;
        window.__meteoPreviousSnapshot = null;
        initDefaults();
        renderAllAdvanced();
        runLifecycleHooks('locationChanged');
    }
    async function afterRefresh() {
        const result = await loadAdvanced(true, true);
        await runLifecycleHooksAsync('afterRefresh');
        return result;
    }
    const baseOnPage = onPage;
    async function onPageWithHooks(page) {
        await baseOnPage(page);
        await runLifecycleHooksAsync('onPage', page);
    }
    const advancedService = Object.freeze({ build: ADVANCED_BUILD, bind, onPage: onPageWithHooks, renderAll: renderAllAdvanced, locationChanged, afterRefresh, openDay, setRadarLayer: setAdvancedRadarLayer, registerLifecycleHook });
    SERVICES.publish('advanced', advancedService);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
    else queueMicrotask(bind);
})();
