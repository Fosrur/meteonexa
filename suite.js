'use strict';
(() => {
    const CONFIG = window.METEONEXA_CONFIG;
    if (!CONFIG)
        throw new Error('METEONEXA_CONFIG_NOT_LOADED');
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const APP_RUNTIME = SERVICES.require('runtimeApi').get();
    const { showToast, withLoader, loadWeather, syncEnhancedSelect, updateThreshold, appLocale, temperature, t } = APP_RUNTIME;
    const state = APP_RUNTIME.getState();
    const SECURITY = SERVICES.get('security');
    if (!SECURITY)
        throw new Error('METEONEXA_SECURITY_NOT_LOADED');
    const {
        BUILD, API, KEYS, q, qa, n, clamp, safe, currentLocale, mean, deviation, localDate, localTime, tempText,
        localizedLocationPart, locationLabel, locationKey, ui, apiMessage, toast, loader, deviceId, isGuest, fetchJson,
        dateInput, addDays, setDateField, nearestTimeIndex, bearing, directionName, haversine
    } = SERVICES.require('suiteSupport').create({
        CONFIG, SERVICES, SECURITY, state, appLocale, temperature, t, showToast, withLoader, meteonexaText
    });
    // Privacy migration: previous builds accidentally wrote assistant
    // history to localStorage again. Remove that legacy copy and keep the active
    // conversation only in the current tab session.
    try { localStorage.removeItem(KEYS.assistant); } catch { }
    const suite = {
        modelRows: [], modelFetchedAt: 0, direction: null, environment: null, officialAlerts: null, officialFetchedAt: 0,
        history: null, route: null, routeNavigation: { watchId: null, marker: null, active: false }, assistantContext: {},
        mapLayer: null, routeMap: null, routeMapResizeObserver: null, aiAvailability: null,
        assistantMode: localStorage.getItem(KEYS.assistantMode) === 'local' ? 'local' : 'ai', aiSwitchPending: null,
        refreshTimer: null, snapshotPrevious: null,
        advancedRequest: null, advancedRefreshedAt: 0, advancedLocationKey: '', canonicalConsensus: null
    };
    const MODEL_DEFINITIONS = [
        ['ecmwf', meteonexaText('provider.ecmwf'), meteonexaText("advanced.loadmodels.european_model")],
        ['aifs', meteonexaText('provider.aifs'), meteonexaText('model.aifs.description')],
        ['icon', meteonexaText('provider.icon'), meteonexaText('model.icon.description')],
        ['gfs', meteonexaText('provider.gfs'), meteonexaText("intelligence.createpreviewintelligencemodels.noaa_global_model")],
        ['meteofrance', meteonexaText('provider.meteofrance'), meteonexaText("suite.european_regional_model")],
        ['ukmo', meteonexaText('provider.ukmo'), meteonexaText("suite.met_office_model")]
    ];
    function summarizeServerModel(model, definition) {
        const rows = Array.isArray(model?.rows) ? model.rows : [];
        if (!rows.length) return null;
        const now = Date.now();
        let start = rows.findIndex(row => new Date(row.time).getTime() >= now - 30 * 60 * 1000);
        if (start < 0) start = 0;
        const windowRows = rows.slice(start, start + 24);
        const temperatures = windowRows.map(row => Number(row.temperature)).filter(Number.isFinite);
        if (!temperatures.length) return null;
        const rain = windowRows.map(row => Number(row.precipitation)).filter(Number.isFinite);
        const wind = windowRows.map(row => Number(row.windGust)).filter(Number.isFinite);
        const firstRainRow = windowRows.find(row => Number(row.precipitation) >= .1);
        const [id, name, description] = definition;
        return {
            id, name: model.label || name, description, serverOwned: true, stale: model.stale === true,
            maxTemp: Math.max(...temperatures), minTemp: Math.min(...temperatures),
            rain: rain.reduce((a, b) => a + b, 0), wind: wind.length ? Math.max(...wind) : 0, pressure: 0,
            firstRain: firstRainRow?.time || '', code: n(windowRows[0]?.weatherCode),
            temperatures: windowRows.slice(0, 12).map(row => Number(row.temperature)).filter(Number.isFinite),
            precipitation: windowRows.slice(0, 12).map(row => n(row.precipitation)),
            times: windowRows.slice(0, 12).map(row => row.time), rows: windowRows
        };
    }
    async function loadModels(force = false) {
        if (!force && suite.modelRows.length && Date.now() - suite.modelFetchedAt < 12 * 60 * 1000)
            return suite.modelRows;
        const params = new URLSearchParams({ lat: String(state.location.latitude), lon: String(state.location.longitude) });
        const payload = await fetchJson(`${API.weatherFusion}?${params}`, { timeout: 18000 });
        const definitions = new Map(MODEL_DEFINITIONS.map(definition => [definition[0], definition]));
        suite.canonicalConsensus = payload?.fusion?.consensus || null;
        suite.modelRows = (payload?.fusion?.models || []).flatMap(model => {
            const definition = definitions.get(String(model?.id || ''));
            if (!definition) return [];
            const row = summarizeServerModel(model, definition);
            return row ? [row] : [];
        });
        suite.modelFetchedAt = Date.now();
        return suite.modelRows;
    }
    function modelConfidence() {
        const rows = suite.modelRows;
        const primaryAgreement = Number(suite.canonicalConsensus?.primary?.agreementPct);
        if (Number.isFinite(primaryAgreement)) {
            const score = Math.round(clamp(primaryAgreement, 35, 98));
            return { score, label: score >= 84 ? meteonexaText("intelligence.confidencefrommodels.very_high_confidence") : score >= 68 ? meteonexaText("intelligence.confidencefrommodels.good_confidence") : score >= 52 ? meteonexaText("suite.modelconfidence.uncertain_forecast") : meteonexaText("suite.modelconfidence.highly_variable_forecast"), agreement: score >= 84 ? meteonexaText("intelligence.confidencefrommodels.very_high") : score >= 68 ? meteonexaText("intelligence.confidencefrommodels.good") : score >= 52 ? meteonexaText('model.agreement.partial') : meteonexaText("weather.uvlabel.low") };
        }
        if (rows.length < 2)
            return { score: rows.length ? 58 : 35, label: rows.length ? meteonexaText("suite.modelconfidence.limited_confidence") : meteonexaText("suite.modelconfidence.models_unavailable"), agreement: "" + meteonexaText("weather.uvlabel.low") };
        const t = Math.max(...rows.map(r => r.maxTemp)) - Math.min(...rows.map(r => r.maxTemp));
        const r = deviation(rows.map(r => r.rain));
        const w = Math.max(...rows.map(r => r.wind)) - Math.min(...rows.map(r => r.wind));
        const expectedModels = Number(suite.canonicalConsensus?.modelsExpected || rows.length);
        const missingModels = Math.max(0, expectedModels - rows.length);
        const score = Math.round(clamp(98 - t * 7 - Math.min(25, r * 5) - Math.min(18, w * .8) - missingModels * 3, 35, 98));
        return { score, label: score >= 84 ? "" + meteonexaText("intelligence.confidencefrommodels.very_high_confidence") : score >= 68 ? "" + meteonexaText("intelligence.confidencefrommodels.good_confidence") : score >= 52 ? meteonexaText("suite.modelconfidence.uncertain_forecast") : meteonexaText("suite.modelconfidence.highly_variable_forecast"), agreement: score >= 84 ? "" + meteonexaText("intelligence.confidencefrommodels.very_high") : score >= 68 ? "" + meteonexaText("intelligence.confidencefrommodels.good") : score >= 52 ? meteonexaText('model.agreement.partial') : "" + meteonexaText("weather.uvlabel.low") };
    }
    function modelForecastStrip(row) {
        const rows=Array.isArray(row?.rows)?row.rows:[],offsets=[0,2,4,6,8,10];
        const cells=offsets.flatMap(offset=>{const point=rows[offset];if(!point?.time)return[];const temperatureValue=Number(point.temperature);const precipitationValue=Math.max(0,Number(point.precipitation||0));const rainLevel=Math.min(1,precipitationValue/2);return [{time:point.time,temperature:Number.isFinite(temperatureValue)?temperatureValue:null,precipitation:precipitationValue,probability:null,rainLevel}];});
        if(!cells.length)return'';
        return `<div class="model-forecast-strip">${cells.map(cell=>`<div class="model-hour-cell" style="--rain:${cell.rainLevel.toFixed(3)}"><small>${safe(localTime(cell.time))}</small><strong>${cell.temperature===null?'--':safe(tempText(cell.temperature))}</strong><span class="model-hour-rain"><i></i></span><em>${cell.precipitation.toFixed(1)} mm${cell.probability===null?'':` · ${Math.round(cell.probability)}%`}</em></div>`).join('')}</div>`;
    }
    function renderModels() {
        const rows = suite.modelRows, rating = modelConfidence(), grid = q('#advanced-model-grid'), status = q('#advanced-model-status');
        if (status)
            status.textContent = rows.length ? meteonexaText("suite.rendermodels.value_real_models_updated", { p0: rows.length }) : meteonexaText("suite.rendermodels.comparison_unavailable");
        if (grid)
            grid.innerHTML = rows.length ? rows.map(row => {
                const art = typeof weatherArt === 'function' ? weatherArt(row.code, 1) : `<svg aria-hidden="true"><use href="#i-sun"/></svg>`;
                return `<article class="advanced-model-card"><div class="model-head"><span class="model-weather-icon">${art}</span><div><strong>${safe(row.name)}</strong><small>${safe(row.description)}</small></div><em>${safe(weatherMeta(row.code, 1).label)}</em></div><div class="model-chart model-chart-readable">${modelForecastStrip(row)}</div><div class="model-values"><span><small>${safe(meteonexaText("advanced.renderadvanced.high"))}</small><strong>${safe(tempText(row.maxTemp))}</strong></span><span><small>${safe(meteonexaText("intelligence.rendermodelcomparison.24h_rain"))}</small><strong>${row.rain.toFixed(1)} mm</strong></span><span><small>${safe(meteonexaText("intelligence.rendermodelcomparison.maximum_wind"))}</small><strong>${Math.round(row.wind)} km/h</strong></span></div><p><svg><use href="#i-umbrella"/></svg>${safe(row.firstRain ? meteonexaText("intelligence.first_possible_rain_at_value", { time: localTime(row.firstRain) }) : meteonexaText("intelligence.no_relevant_rain_next_24_hours"))}</p></article>`;
            }).join('') : `<div class="advanced-empty">${safe(meteonexaText("suite.rendermodels.no_invented_data_model_services_currently_unreachable"))}</div>`;
        const summary = q('#suite-model-summary');
        if (summary) {
            const avgMax = mean(rows.map(r => r.maxTemp)), avgRain = mean(rows.map(r => r.rain));
            const rainStarts = rows.filter(r => r.firstRain).map(r => new Date(r.firstRain).getTime());
            const spread = rainStarts.length > 1 ? (Math.max(...rainStarts) - Math.min(...rainStarts)) / 3600000 : 0;
            summary.innerHTML = rows.length ? "" + "<div><small>" + safe(meteonexaText("suite.rendermodels.average_forecast")) + "</small><strong>" + tempText(avgMax) + " \u00B7 " + avgRain.toFixed(1) + " mm</strong><span>" + safe(meteonexaText("suite.rendermodels.average_available_models")) + "</span></div><div><small>" + safe(meteonexaText('model.agreement.label')) + "</small><strong>" + safe(rating.agreement) + "</strong><span>" + rating.score + "/100</span></div><div><small>" + safe(meteonexaText("suite.rendermodels.rain_arrival")) + "</small><strong>" + (rainStarts.length ? spread <= 1 ? safe(meteonexaText("suite.rendermodels.consistent")) : safe(meteonexaText("suite.rendermodels.shift_value_h", { p0: spread.toFixed(1) })) : "" + safe(meteonexaText("intelligence.renderintelligence.not_expected"))) + "</strong><span>" + safe(meteonexaText('model.rain_count', { count: rainStarts.length })) + "</span></div><div><small>" + safe(meteonexaText("suite.rendermodels.simple_summary")) + "</small><strong>" + (rating.score >= 68 ? safe(meteonexaText("suite.rendermodels.reliable_forecast")) : safe(meteonexaText("suite.modelconfidence.uncertain_forecast"))) + "</strong><span>" + (rating.score >= 68 ? safe(meteonexaText("suite.rendermodels.main_models_aligned")) : safe(meteonexaText("suite.rendermodels.differences_require_new_updates"))) + "</span></div>" : '';
        }
        renderConfidence();
    }
    function nowcastBase() {
        const series = state.weather?.minutely_15;
        if (!series?.time?.length) return null;
        const start = nearestTimeIndex(series.time, new Date());
        const anchors = series.time.slice(start, start + 9).map((time, offset) => ({
            time,
            precipitation: n(series.precipitation?.[start + offset] ?? series.rain?.[start + offset]),
            gust: n(series.wind_gusts_10m?.[start + offset])
        }));
        if (!anchors.length) return null;
        const points = [];
        for (let index = 0; index < anchors.length - 1; index += 1) {
            const a = anchors[index], b = anchors[index + 1];
            const ta = new Date(a.time).getTime(), tb = new Date(b.time).getTime();
            for (let step = 0; step < 3; step += 1) {
                const ratio = step / 3;
                points.push({
                    time: new Date(ta + (tb - ta) * ratio).toISOString(),
                    precipitation: a.precipitation + (b.precipitation - a.precipitation) * ratio,
                    gust: a.gust + (b.gust - a.gust) * ratio,
                    exactQuarter: step === 0
                });
            }
        }
        const lastAnchor = anchors.at(-1);
        points.push({ ...lastAnchor, exactQuarter: true });
        const wet = points.map((point, index) => point.precipitation >= .05 ? index : -1).filter(index => index >= 0);
        const first = wet[0] ?? -1;
        let last = first;
        if (first >= 0) {
            for (let index = first + 1; index < points.length && points[index].precipitation >= .05; index += 1) last = index;
        }
        let startAt = first >= 0 ? new Date(points[first].time) : null;
        if (first > 0) {
            const a = points[first - 1], b = points[first];
            const ratio = clamp((.05 - a.precipitation) / Math.max(.001, b.precipitation - a.precipitation), 0, 1);
            startAt = new Date(new Date(a.time).getTime() + (new Date(b.time) - new Date(a.time)) * ratio);
        }
        let endAt = null;
        if (last >= 0 && points[last + 1]) {
            const a = points[last], b = points[last + 1];
            const ratio = clamp((a.precipitation - .05) / Math.max(.001, a.precipitation - b.precipitation), 0, 1);
            endAt = new Date(new Date(a.time).getTime() + (new Date(b.time) - new Date(a.time)) * ratio);
        }
        return {
            points,
            anchors,
            first,
            last,
            startAt,
            endAt,
            raining: first === 0,
            peak: Math.max(0, ...anchors.map(point => point.precipitation)),
            total: anchors.reduce((sum, point) => sum + point.precipitation, 0)
        };
    }
    async function estimateRainDirection() {
        const lat = n(state.location.latitude), lon = n(state.location.longitude);
        try {
            // Verified Trust: do not hit Open-Meteo's multi-coordinate minutely endpoint
            // directly from every browser. The same-origin proxy rounds public
            // coordinates, caches the last good grid and degrades 429/5xx to an
            // optional unavailable signal instead of a visible failed XHR.
            const p = new URLSearchParams({ lat: lat.toFixed(4), lon: lon.toFixed(4) });
            const data = await fetchJson(`api/intelligence/rain-direction.php?${p}`, { timeout: 16000 });
            if (!data?.available || !Number.isFinite(Number(data.degrees))) return null;
            const degrees = Number(data.degrees);
            return { degrees, direction: directionName(degrees), distanceKm: n(data.distanceKm), strength: n(data.strength), stale: data.stale === true };
        }
        catch {
            return null;
        }
    }
    function radarAgeMinutes() { const frames = state?.radar?.liveFrames || []; const latest = frames[frames.length - 1]; return latest?.time ? Math.max(0, Math.round((Date.now() - n(latest.time) * 1000) / 60000)) : null; }
    function snapshot() {
        const h = state.weather?.hourly;
        if (!h?.time?.length)
            return null;
        const start = nearestTimeIndex(h.time, new Date()), range = key => (h[key] || []).slice(start, start + 24).map(n);
        const rain = range('precipitation'), temps = range("temperature_2m"), gusts = range('wind_gusts_10m'), first = rain.findIndex(v => v >= .1);
        return { at: Date.now(), key: locationKey(), firstRain: first >= 0 ? h.time[start + first] : '', rain: rain.reduce((a, b) => a + b, 0), maxTemp: temps.length ? Math.max(...temps) : 0, wind: gusts.length ? Math.max(...gusts) : 0, pressure: n(state.weather.current?.surface_pressure), windDirection: n(state.weather.current?.wind_direction_10m), cloud: n(state.weather.current?.cloud_cover), modelScore: modelConfidence().score };
    }
    function stabilityScore() {
        const current = snapshot(), previous = suite.snapshotPrevious || JSON.parse(localStorage.getItem(KEYS.snapshot) || 'null');
        if (!current || !previous || previous.key !== current.key)
            return 72;
        const rainShift = current.firstRain && previous.firstRain ? Math.abs(new Date(current.firstRain) - new Date(previous.firstRain)) / 3600000 : current.firstRain === previous.firstRain ? 0 : 4;
        return Math.round(clamp(100 - rainShift * 11 - Math.abs(current.rain - previous.rain) * 3 - Math.abs(current.maxTemp - previous.maxTemp) * 5 - Math.abs(current.wind - previous.wind) * .7, 35, 99));
    }
    function nowcastReliability() { const model = modelConfidence().score, age = radarAgeMinutes(), stability = stabilityScore(), direction = suite.direction ? 85 : 60; return Math.round(clamp(model * .45 + stability * .3 + direction * .15 + (age === null ? 45 : clamp(100 - age * 3, 35, 100)) * .1, 30, 98)); }
    function changeExplanations() {
        const current = snapshot(), previous = suite.snapshotPrevious || JSON.parse(localStorage.getItem(KEYS.snapshot) || 'null');
        if (!current || !previous || previous.key !== current.key)
            return [{ icon: 'i-refresh', title: "" + meteonexaText("intelligence.buildforecastchanges.first_analysis_available"), copy: meteonexaText("suite.changeexplanations.from_next_update_i_will_compare_timing_pressure") }];
        const rows = [];
        if (previous.firstRain && current.firstRain) {
            const minutes = Math.round((new Date(current.firstRain) - new Date(previous.firstRain)) / 60000);
            if (Math.abs(minutes) >= 20) {
                let reason = meteonexaText("suite.changeexplanations.latest_updates_shifted_precipitation_window");
                const pressure = current.pressure - previous.pressure;
                const windShift = Math.abs(((current.windDirection - previous.windDirection + 540) % 360) - 180);
                if (minutes > 0 && pressure > 1.5)
                    reason = meteonexaText("suite.changeexplanations.pressure_has_risen_disturbance_appears_moving_more_slowly");
                else if (minutes < 0 && pressure < -1.5)
                    reason = meteonexaText("suite.changeexplanations.pressure_has_fallen_disturbance_appears_more_active");
                else if (windShift >= 45)
                    reason = meteonexaText("suite.changeexplanations.forecast_wind_changed_direction");
                else if (current.modelScore < previous.modelScore)
                    reason = meteonexaText("suite.changeexplanations.models_show_greater_spread");
                rows.push({ icon: 'i-clock', title: minutes > 0 ? "" + meteonexaText("intelligence.buildforecastchanges.rain_delayed") : "" + meteonexaText("intelligence.buildforecastchanges.rain_brought_forward"), copy: meteonexaText('change.timing.copy', { minutes: Math.abs(minutes), reason }) });
            }
        }
        if (!previous.firstRain && current.firstRain)
            rows.push({ icon: 'i-umbrella', title: meteonexaText("suite.changeexplanations.forecast_rain"), copy: meteonexaText("suite.changeexplanations.new_runs_introduce_precipitation_from_value_pressure_value", { p0: localTime(current.firstRain), p1: current.pressure < previous.pressure ? meteonexaText('pressure.falling') : meteonexaText('pressure.stable') }) });
        if (previous.firstRain && !current.firstRain)
            rows.push({ icon: 'i-sun', title: meteonexaText("suite.changeexplanations.rain_rimossa"), copy: meteonexaText("suite.changeexplanations.latest_models_do_not_show_significant_precipitation_over") });
        if (Math.abs(current.rain - previous.rain) >= .5)
            rows.push({ icon: 'i-droplet', title: current.rain > previous.rain ? meteonexaText('accumulation.increased') : meteonexaText('accumulation.decreased'), copy: meteonexaText("suite.changeexplanations.estimate_changed_by_value_mm_mainly_because_latest", { p0: Math.abs(current.rain - previous.rain).toFixed(1) }) });
        if (Math.abs(current.wind - previous.wind) >= 8)
            rows.push({ icon: 'i-wind', title: current.wind > previous.wind ? meteonexaText("suite.changeexplanations.strong_gusts") : "" + meteonexaText("intelligence.buildforecastchanges.wind_easing"), copy: meteonexaText("suite.changeexplanations.gusts_change_by_about_value_km_h", { p0: Math.round(Math.abs(current.wind - previous.wind)) }) });
        return rows.length ? rows.slice(0, 4) : [{ icon: 'i-shield', title: "" + meteonexaText("intelligence.buildforecastchanges.stable_forecast"), copy: meteonexaText("suite.changeexplanations.timing_totals_wind_consistent_previous_update") }];
    }
    async function saveSnapshot() {
        const current = snapshot();
        if (!current)
            return;
        const previous = JSON.parse(localStorage.getItem(KEYS.snapshot) || 'null');
        if (previous?.key === current.key && Date.now() - n(previous.at) > 60000)
            suite.snapshotPrevious = previous;
        localStorage.setItem(KEYS.snapshot, JSON.stringify(current));
        if (isGuest())
            return;
        try {
            const remote = await fetchJson(`${API.snapshots}?deviceId=${encodeURIComponent(deviceId)}&locationKey=${encodeURIComponent(current.key)}`);
            if (remote.rows?.[0]?.snapshot && remote.rows[0].snapshot.at !== current.at)
                suite.snapshotPrevious = remote.rows[0].snapshot;
            await fetchJson(API.snapshots, { method: 'POST', body: { deviceId, locationKey: current.key, snapshot: current } });
        }
        catch { }
    }
    async function renderNowcast(forceDirection = false) {
        if (!q('#advanced-nowcast-message'))
            return;
        const now = nowcastBase();
        if (!now)
            return;
        if (forceDirection || !suite.direction)
            suite.direction = await estimateRainDirection();
        const minutes = now.startAt ? Math.max(0, Math.round((now.startAt - Date.now()) / 60000)) : null;
        const intensity = now.peak >= 2 ? "" + meteonexaText("intelligence.extractnowcast.heavy") : now.peak >= .7 ? "" + meteonexaText("intelligence.extractnowcast.moderate") : now.peak >= .05 ? "" + meteonexaText("intelligence.extractnowcast.light") : "" + meteonexaText("intelligence.extractnowcast.none");
        const hourlyRisk=twoHourHourlyRisk(),official=currentOfficialWarning(),conflict=now.first<0&&(hourlyRisk.probability>=45||official);
        const title = conflict ? meteonexaText('advanced.nowcast.mixed.title') : now.first < 0 ? "" + meteonexaText("intelligence.nowcastmessage.no_significant_rain") : now.raining ? "" + meteonexaText("intelligence.nowcastmessage.rain_progress") : meteonexaText('nowcast.rain_expected_minutes', { minutes, intensity });
        const detail = conflict ? meteonexaText('advanced.nowcast.mixed.copy',{probability:Math.round(hourlyRisk.probability)}) : now.first < 0 ? meteonexaText("suite.rendernowcast.no_significant_precipitation_expected_over_next_two_hours") : now.endAt ? meteonexaText('nowcast.detail', { time: localTime(now.endAt), direction: suite.direction?.direction || meteonexaText('suite.rendernowcast.variable') }) : meteonexaText("suite.rendernowcast.may_continue_beyond_next_two_hours");
        q('#advanced-nowcast-message').innerHTML = `<span><svg><use href="#${now.first >= 0 || conflict ? 'i-umbrella' : 'i-shield'}"/></svg></span><div><strong>${safe(title)}</strong><p>${safe(detail)}</p></div>`;
        const officialNode=q('#advanced-nowcast-official');if(officialNode){officialNode.hidden=!official;officialNode.innerHTML=official?`<svg><use href="#i-alert"/></svg><div><strong>${safe(meteonexaText('advanced.official.active'))}</strong><span>${safe([officialWarningDisplay(official),officialWarningLifecycleDisplay(official)].filter(Boolean).join(' · '))}</span></div>`:'';}
        q('#advanced-rain-start').textContent = now.first < 0 ? "" + meteonexaText("intelligence.renderintelligence.not_expected") : now.raining ? "" + meteonexaText("intelligence.renderintelligence.progress") : localTime(now.startAt);
        q('#advanced-rain-end').textContent = now.first < 0 ? '--' : now.endAt ? localTime(now.endAt) : meteonexaText('forecast.beyond_two_hours');
        q('#advanced-rain-peak').textContent = intensity;
        q('#advanced-rain-total').textContent = `${now.total.toFixed(1)} mm`;
        q('#suite-rain-direction').textContent = suite.direction ? meteonexaText('direction.toward', { direction: suite.direction.direction }) : "" + meteonexaText("intelligence.confidencefrommodels.variable");
        q('#suite-nowcast-reliability').textContent = `${nowcastReliability()}%`;
        q('#advanced-nowcast-timeline').innerHTML = advancedNowcastTimeline(now);
        updateAdvancedFreshness();
        q('#advanced-change-list').innerHTML = changeExplanations().map(item => `<article><span><svg><use href="#${item.icon}"/></svg></span><div><strong>${safe(item.title)}</strong><p>${safe(item.copy)}</p></div></article>`).join('');
        await maybeNotifyNowcast(now, minutes, intensity);
    }
    function renderConfidence() {
        const rating = modelConfidence(), score = Math.round((rating.score * .7 + nowcastReliability() * .3));
        q('#advanced-confidence-ring')?.style.setProperty('--score', score);
        if (q('#advanced-confidence-score'))
            q('#advanced-confidence-score').textContent = score;
        if (q('#advanced-confidence-label'))
            q('#advanced-confidence-label').textContent = score >= 84 ? meteonexaText('confidence.brand.very_high') : score >= 68 ? meteonexaText("suite.renderconfidence.good_meteonexa_confidence") : meteonexaText("suite.renderconfidence.variable_meteonexa_confidence");
        if (q('#advanced-confidence-badge'))
            q('#advanced-confidence-badge').textContent = score >= 84 ? meteonexaText('confidence.very_high') : score >= 68 ? "" + meteonexaText("weather.metricnotevisibility.good") : "" + meteonexaText("intelligence.confidencefrommodels.variable");
        if (q('#advanced-confidence-models'))
            q('#advanced-confidence-models').textContent = meteonexaText('common.count_of_total', { current: suite.modelRows.length, total: MODEL_DEFINITIONS.length });
        if (q('#advanced-confidence-agreement'))
            q('#advanced-confidence-agreement').textContent = rating.agreement;
        if (q('#advanced-confidence-updated'))
            q('#advanced-confidence-updated').textContent = localTime(new Date());
        if (q('#advanced-confidence-description'))
            q('#advanced-confidence-description').textContent = score >= 84 ? meteonexaText("suite.renderconfidence.models_agree_radar_recent_forecast_stable") : score >= 68 ? meteonexaText("suite.renderconfidence.sources_broadly_aligned_limited_differences") : meteonexaText("suite.renderconfidence.sources_diverge_check_next_updates_more_frequently");
        const age = radarAgeMinutes();
        if (q('#suite-confidence-radar'))
            q('#suite-confidence-radar').textContent = age === null ? meteonexaText('intelligence.confidencefrommodels.unavailable') : meteonexaText('time.minutes_ago', { value: age });
        if (q('#suite-confidence-stability'))
            q('#suite-confidence-stability').textContent = `${stabilityScore()}%`;
    }
    async function maybeNotifyNowcast(now, minutes, intensity) {
        if (isGuest())
            return;
        if (now.first < 0 || now.raining || minutes === null || minutes > 30 || !("Notification" in window) || Notification.permission !== 'granted')
            return;
        const key = `${locationKey()}:${dateInput(new Date())}:${Math.round(new Date(now.startAt).getTime() / 900000)}`, previous = JSON.parse(localStorage.getItem(KEYS.notification) || 'null');
        if (previous?.key === key && Date.now() - previous.at < 3 * 3600000)
            return;
        try {
            await sendDeviceNotification?.(meteonexaText('nowcast.rain_expected_minutes', { minutes, intensity }), meteonexaText("suite.maybenotifynowcast.value_estimated_end_value_confidence_value", { p0: locationLabel(), p1: now.endAt ? localTime(now.endAt) : meteonexaText('nowcast.beyond_two_hours'), p2: nowcastReliability() }), { tag: 'meteonexa-nowcast', url: './#advanced' });
            localStorage.setItem(KEYS.notification, JSON.stringify({ key, at: Date.now() }));
        }
        catch { }
    }
    async function loadOfficialAlerts(force=false) {
        if(!force && suite.officialAlerts && Date.now()-suite.officialFetchedAt<5*60*1000)return suite.officialAlerts;
        const params=new URLSearchParams({lat:String(state.location.latitude),lon:String(state.location.longitude),location:String(state.location.name||''),admin1:String(state.location.admin1||''),lang:String(currentLocale()).slice(0,2)});
        try{const data=await fetchJson(`${API.officialAlerts}?${params}`,{timeout:15000});suite.officialAlerts=data?.alerts||null;suite.officialFetchedAt=Date.now();}
        catch(error){console.warn('OFFICIAL_ALERTS_ADVANCED_FAILED',error);if(force)suite.officialAlerts=null;}
        return suite.officialAlerts;
    }
    function twoHourHourlyRisk() {
        const h=state.weather?.hourly||{},start=nearestTimeIndex(h.time||[],new Date());const probabilities=(h.precipitation_probability||[]).slice(start,start+3).map(n);const precipitation=(h.precipitation||[]).slice(start,start+3).map(n);return {probability:probabilities.length?Math.max(...probabilities):0,precipitation:precipitation.reduce((sum,value)=>sum+Math.max(0,value),0)};
    }
    function currentOfficialWarning() { const rows=suite.officialAlerts?.relevant;return Array.isArray(rows)&&rows.length?rows[0]:null; }
    function officialWarningDisplay(warning) {
        if(!warning)return '';
        const raw=String(warning.title||'').trim();
        const severityRaw=String(warning.severity||'').toLowerCase();
        const colorMatch=raw.match(/\b(yellow|orange|red)\b/i);
        const severity=['yellow','orange','red'].includes(severityRaw)?severityRaw:String(colorMatch?.[1]||'yellow').toLowerCase();
        const eventMap=[
            [/thunder|tempor/i,'thunderstorm'],[/rain|piogg/i,'rain'],[/snow|neve/i,'snow'],[/wind|vento/i,'wind'],[/ice|ghiacci/i,'ice'],[/fog|nebb/i,'fog'],[/heat|high temperature|caldo/i,'heat']
        ];
        const eventId=(eventMap.find(([rx])=>rx.test(raw))||[])[1]||'weather';
        let area='';
        const areaMatch=raw.match(/(?:issued\s+for\s+italy\s*[-–:]\s*|italy\s*[-–:]\s*)(.+)$/i);
        if(areaMatch?.[1])area=String(areaMatch[1]).replace(/\s+warning.*$/i,'').trim();
        const level=meteonexaText(`advanced.official.level.${severity}`);
        const event=meteonexaText(`advanced.official.event.${eventId}`);
        if(area)return meteonexaText('advanced.official.summary.area',{level,event,area});
        if(raw && !/warning\s+issued\s+for/i.test(raw))return raw;
        return meteonexaText('advanced.official.summary',{level,event});
    }
    function officialWarningLifecycleDisplay(warning) {
        const life=warning?.lifecycle||{},current=String(warning?.severity||'yellow').toLowerCase(),previous=String(life.previousSeverity||'').toLowerCase(),parts=[],rank=v=>({green:0,yellow:1,orange:2,red:3})[v]??0;
        if(previous&&previous!==current&&['yellow','orange','red'].includes(previous)&&['yellow','orange','red'].includes(current))parts.push(meteonexaText(rank(current)>rank(previous)?'home.official.lifecycle.escalated':'home.official.lifecycle.downgraded',{from:meteonexaText(`advanced.official.level.${previous}`),to:meteonexaText(`advanced.official.level.${current}`)}));
        const oldEnd=Date.parse(String(life.previousEndsAt||'')),newEnd=Date.parse(String(warning?.endsAt||''));if(Number.isFinite(oldEnd)&&Number.isFinite(newEnd)&&newEnd>oldEnd+60000)parts.push(meteonexaText('home.official.lifecycle.extended',{until:localTime(warning.endsAt)}));
        return parts.join(' · ');
    }
    function advancedNowcastTimeline(now) {
        const anchors=(now?.anchors||[]).slice(0,8);if(!anchors.length)return'';const peak=Math.max(.05,...anchors.map(point=>n(point.precipitation)));
        return anchors.map((point,index)=>{const value=Math.max(0,n(point.precipitation)),previous=index?Math.max(0,n(anchors[index-1].precipitation)):value;const delta=value-previous;const trend=delta>.05?'up':delta<-.05?'down':'flat';const level=Math.max(.04,Math.min(1,value/peak));const trendLabel=trend==='up'?meteonexaText('advanced.nowcast.trend.up'):trend==='down'?meteonexaText('advanced.nowcast.trend.down'):meteonexaText('advanced.nowcast.trend.flat');return `<div class="advanced-nowcast-slot" data-trend="${trend}"><small>${safe(localTime(point.time))}</small><div class="advanced-nowcast-bar"><i style="--level:${level.toFixed(3)}"></i></div><strong>${value.toFixed(1)} mm</strong><em>${safe(trendLabel)}</em></div>`;}).join('');
    }
    function updateAdvancedFreshness() { const node=q('#advanced-nowcast-updated');if(!node)return;const at=Number(suite.advancedRefreshedAt||state.weather?.fetchedAt||Date.now()),minutes=Math.max(0,Math.round((Date.now()-at)/60000));node.textContent=minutes<1?meteonexaText('advanced.updated.now'):meteonexaText('advanced.updated.minutes',{minutes}); }
    async function loadEnvironment() { const lat = state.location.latitude, lon = state.location.longitude; const airParams = new URLSearchParams({ latitude: lat, longitude: lon, hourly: 'european_aqi,pm2_5,alder_pollen,birch_pollen,grass_pollen,mugwort_pollen,ragweed_pollen', timezone: 'auto', forecast_days: '3' }); const marineParams = new URLSearchParams({ latitude: lat, longitude: lon, hourly: 'wave_height,wave_direction,wave_period,sea_surface_temperature', timezone: 'auto', forecast_days: '3' }); const [air, marine] = await Promise.allSettled([fetchJson(`${CONFIG.AIR_QUALITY_API}?${airParams}`, { credentials: 'omit' }), fetchJson(`${CONFIG.MARINE_API}?${marineParams}`, { credentials: 'omit' })]); suite.environment = { air: air.status === 'fulfilled' ? air.value : null, marine: marine.status === 'fulfilled' ? marine.value : null }; return suite.environment; }
    const PROFILES = { home: { rain: 65, wind: 55, heat: 35, cold: 2, pollen: 55, wave: 2.5 }, work: { rain: 55, wind: 50, heat: 36, cold: 1, pollen: 60, wave: 3 }, commute: { rain: 40, wind: 40, heat: 36, cold: 3, pollen: 70, wave: 2.5 }, kids: { rain: 35, wind: 35, heat: 31, cold: 4, pollen: 40, wave: 1.5 }, outdoor: { rain: 30, wind: 35, heat: 31, cold: 3, pollen: 45, wave: 1.5 }, agriculture: { rain: 25, wind: 40, heat: 33, cold: 2, pollen: 65, wave: 3 }, marine: { rain: 45, wind: 30, heat: 38, cold: 0, pollen: 90, wave: 1.5 }, mountain: { rain: 30, wind: 35, heat: 30, cold: 4, pollen: 55, wave: 4 }, pets: { rain: 45, wind: 40, heat: 30, cold: 3, pollen: 45, wave: 4 } };
    function loadAlertProfile() { const stored = JSON.parse(localStorage.getItem(KEYS.alerts) || 'null'); const base = stored && PROFILES[stored.name] ? stored : { name: 'home', ...PROFILES.home }; return { ...base, events: { rain:true, storm:true, hail:true, wind:true, snow:true, ice:true, fog:true, heat:true, aqi:true, official:true, ...(base.events || {}) }, minimumSeverity: base.minimumSeverity || 'yellow', quietHours: { enabled:false, start:'23:00', end:'07:00', ...(base.quietHours || {}) } }; }
    async function applyProfile(name, notify = true) {
        const previous = loadAlertProfile(); const profile = { ...previous, name, ...(PROFILES[name] || PROFILES.home), events: { ...(previous.events || {}) }, quietHours: { ...(previous.quietHours || {}) } };
        localStorage.setItem(KEYS.alerts, JSON.stringify(profile));
        state.thresholds = { ...state.thresholds, rain: profile.rain, wind: profile.wind, heat: profile.heat };
        [['threshold-rain', 'rain'], ['threshold-wind', 'wind'], ['threshold-heat', 'heat'], ['suite-cold', 'cold'], ['suite-pollen', 'pollen'], ['suite-wave', 'wave']].forEach(([id, key]) => {
            const input = q(`#${id}`);
            if (input)
                input.value = profile[key];
        });
        updateExtendedOutputs();
        qa('[data-alert-profile]').forEach(button => button.classList.toggle('active', button.dataset.alertProfile === name));
        try {
            updateThreshold('rain', profile.rain);
            updateThreshold('wind', profile.wind);
            updateThreshold('heat', profile.heat);
        }
        catch { }
        if (!isGuest()) {
            try {
                await fetchJson(API.alertPreferences, { method: 'POST', body: { deviceId, profile } });
            }
            catch { }
        }
        if (notify)
            toast(meteonexaText("advanced.applyalertprofile.alert_profile_updated"), meteonexaText("suite.applyprofile.contextual_thresholds_active_value", { p0: q(`[data-alert-profile="${name}"]`)?.textContent || name }));
        renderExtendedAlerts();
        configureBackgroundChecks();
    }
    function updateExtendedOutputs() {
        const pairs = [['suite-cold', 'suite-cold-output', v => `${v}°C`], ['suite-pollen', 'suite-pollen-output', v => v], ['suite-wave', 'suite-wave-output', v => `${Number(v).toFixed(1)} m`]];
        pairs.forEach(([id, out, format]) => {
            const input = q(`#${id}`), output = q(`#${out}`);
            if (input && output)
                output.textContent = format(input.value);
        });
    }
    function environmentPeaks() { const air = suite.environment?.air?.hourly || {}, marine = suite.environment?.marine?.hourly || {}; const airStart = nearestTimeIndex(air.time || [], new Date()), seaStart = nearestTimeIndex(marine.time || [], new Date()); const peak = (obj, key, start, count = 24) => Math.max(0, ...(obj[key] || []).slice(start, start + count).map(n)); return { aqi: peak(air, 'european_aqi', airStart), pollen: Math.max(peak(air, 'alder_pollen', airStart), peak(air, 'birch_pollen', airStart), peak(air, 'grass_pollen', airStart), peak(air, 'mugwort_pollen', airStart), peak(air, 'ragweed_pollen', airStart)), wave: peak(marine, 'wave_height', seaStart), seaTemp: n(marine.sea_surface_temperature?.[seaStart]) }; }
    function extendedAlertRows() {
        const profile = loadAlertProfile(), h = state.weather?.hourly || {}, start = nearestTimeIndex(h.time || [], new Date()), slice = key => (h[key] || []).slice(start, start + 24).map(n), temperatures = slice("temperature_2m"), snow = slice('snowfall'), codes = slice('weather_code'), env = environmentPeaks(), rows = [];
        const minTemp = temperatures.length ? Math.min(...temperatures) : 99;
        if (minTemp <= profile.cold)
            rows.push({ level: 'warning', icon: 'i-droplet', title: meteonexaText("suite.extendedalertrows.possible_night_frost"), copy: meteonexaText("suite.extendedalertrows.forecast_low_value_below_value_c_threshold", { p0: tempText(minTemp), p1: profile.cold }) });
        if (Math.max(0, ...snow) > 0 || codes.some(code => [71, 73, 75, 77, 85, 86].includes(code)))
            rows.push({ level: 'warning', icon: 'i-droplet', title: meteonexaText("suite.extendedalertrows.snow_ice_possible"), copy: meteonexaText("suite.extendedalertrows.there_signs_snow_temperatures_favourable_ice") });
        if (env.pollen >= profile.pollen)
            rows.push({ level: 'warning', icon: 'i-air', title: meteonexaText("suite.extendedalertrows.high_pollen"), copy: meteonexaText("suite.extendedalertrows.forecast_peak_value_above_value_threshold", { p0: Math.round(env.pollen), p1: profile.pollen }) });
        if (env.aqi >= 100)
            rows.push({ level: 'danger', icon: 'i-air', title: meteonexaText("suite.extendedalertrows.unfavourable_air_quality"), copy: meteonexaText('alert.aqi.copy', { value: Math.round(env.aqi) }) });
        if (env.wave >= profile.wave && env.wave > 0)
            rows.push({ level: 'danger', icon: 'i-droplet', title: meteonexaText('alert.sea.title'), copy: meteonexaText("suite.extendedalertrows.waves_up_value_m_above_value_m_threshold", { p0: env.wave.toFixed(1), p1: profile.wave.toFixed(1) }) });
        return rows;
    }
    function renderExtendedAlerts() {
        if (!q('#alerts-list') || !state.weather)
            return;
        const rows = extendedAlertRows();
        q('#alerts-list').querySelectorAll('[data-suite-alert]').forEach(node => node.remove());
        rows.forEach(row => q('#alerts-list').insertAdjacentHTML('afterbegin', `<article data-suite-alert class="alert-card ${row.level}"><span class="alert-card-icon"><svg><use href="#${row.icon}"/></svg></span><div><h3>${safe(row.title)}</h3><p>${safe(row.copy)}</p></div><span class="alert-when">${safe(meteonexaText('alert.time.24h'))}</span></article>`));
    }
    function removeSuiteMapLayer() {
        const map = state?.radar?.vectorMap;
        if (!map)
            return;
        ['suite-weather-layer', 'suite-weather-labels'].forEach(id => {
            try {
                if (map.getLayer(id))
                    map.removeLayer(id);
            }
            catch { }
        });
        try {
            if (map.getSource('suite-weather-source'))
                map.removeSource('suite-weather-source');
        }
        catch { }
        q('.suite-map-legend')?.remove();
        suite.mapLayer = null;
    }
    function gridCoordinates(size = 5, stepLat = .55) {
        const lat = n(state.location.latitude), lon = n(state.location.longitude), stepLon = stepLat / Math.max(.45, Math.cos(lat * Math.PI / 180)), half = (size - 1) / 2, rows = [];
        for (let y = -half; y <= half; y++)
            for (let x = -half; x <= half; x++)
                rows.push({ latitude: lat + y * stepLat, longitude: lon + x * stepLon });
        return rows;
    }
    async function mapLayerData(type) {
        const coords = gridCoordinates(type === 'marine' ? 3 : 5, type === 'marine' ? 1.1 : .55);
        if (type === 'air') {
            const p = new URLSearchParams({ latitude: coords.map(c => c.latitude.toFixed(4)).join(','), longitude: coords.map(c => c.longitude.toFixed(4)).join(','), current: 'european_aqi,pm2_5', timezone: 'GMT' });
            const raw = await fetchJson(`${CONFIG.AIR_QUALITY_API}?${p}`, { credentials: 'omit' });
            return { coords, rows: Array.isArray(raw) ? raw : [raw], key: 'european_aqi', unit: "" + meteonexaText("suite.maplayerdata.aqi") };
        }
        if (type === 'marine') {
            const p = new URLSearchParams({ latitude: coords.map(c => c.latitude.toFixed(4)).join(','), longitude: coords.map(c => c.longitude.toFixed(4)).join(','), hourly: 'wave_height,sea_surface_temperature', forecast_hours: '1', timezone: 'GMT' });
            const raw = await fetchJson(`${CONFIG.MARINE_API}?${p}`, { credentials: 'omit' });
            return { coords, rows: Array.isArray(raw) ? raw : [raw], key: 'wave_height', unit: 'm', hourly: true };
        }
        const config = { cloud: ['cloud_cover', '%'], temperature: ["temperature_2m", "" + meteonexaText("weather.unitlabel.c")], wind: ['wind_speed_10m', 'km/h'], pressure: ['surface_pressure', 'hPa'], snow: ['snowfall', 'cm'] }[type];
        const p = new URLSearchParams({ latitude: coords.map(c => c.latitude.toFixed(4)).join(','), longitude: coords.map(c => c.longitude.toFixed(4)).join(','), current: config[0], wind_speed_unit: 'kmh', timezone: 'GMT' });
        const raw = await fetchJson(`${CONFIG.WEATHER_API}?${p}`, { credentials: 'omit' });
        return { coords, rows: Array.isArray(raw) ? raw : [raw], key: config[0], unit: config[1] };
    }
    async function showSuiteMapLayer(type) {
        await ensureRadar();
        const map = state?.radar?.vectorMap;
        if (!map || !state.radar.vectorMapReady) {
            toast(meteonexaText("suite.showsuitemaplayer.map_not_ready"), meteonexaText("suite.showsuitemaplayer.try_again_few_minutes"), 'warning');
            return;
        }
        removeSuiteMapLayer();
        try {
            removeRadarVectorLayer?.();
        }
        catch { }
        const data = await loader(meteonexaText("suite.showsuitemaplayer.loading_layer"), meteonexaText("suite.showsuitemaplayer.retrieving_real_weather_grid"), () => mapLayerData(type));
        const features = data.rows.flatMap((row, index) => {
            const coordinate = data.coords[index];
            if (!coordinate)
                return [];
            let value = data.hourly ? n(row.hourly?.[data.key]?.[0]) : n(row.current?.[data.key]);
            if (!Number.isFinite(value) || (type === 'marine' && value === 0 && !row.hourly?.[data.key]?.[0]))
                return [];
            return [{ type: 'Feature', properties: { value, label: `${type === 'temperature' || type === 'pressure' ? Math.round(value) : value.toFixed(type === 'marine' ? 1 : 0)} ${data.unit}` }, geometry: { type: 'Point', coordinates: [coordinate.longitude, coordinate.latitude] } }];
        });
        if (!features.length)
            throw new Error(type === 'marine' ? meteonexaText("suite.showsuitemaplayer.no_marine_data_available_area") : "" + meteonexaText("suite.showsuitemaplayer.no_data_available"));
        map.addSource('suite-weather-source', { type: 'geojson', data: { type: 'FeatureCollection', features } });
        const ranges = { cloud: [0, 100], temperature: [-10, 40], wind: [0, 80], pressure: [980, 1040], snow: [0, 3], air: [0, 200], marine: [0, 5] }[type];
        map.addLayer({ id: 'suite-weather-layer', type: 'circle', source: 'suite-weather-source', paint: { 'circle-radius': ['interpolate', ['linear'], ['zoom'], 3, 16, 8, 32], 'circle-color': ['interpolate', ['linear'], ['get', 'value'], ranges[0], '#3a76d2', (ranges[0] + ranges[1]) / 2, '#4fd8c8', ranges[1], '#ff5f76'], 'circle-opacity': .58, 'circle-stroke-width': 1.5, 'circle-stroke-color': 'rgba(255,255,255,.7)' } });
        map.addLayer({ id: 'suite-weather-labels', type: 'symbol', source: 'suite-weather-source', layout: { 'text-field': ['get', 'label'], 'text-size': 11 }, paint: { 'text-color': '#ffffff', 'text-halo-color': 'rgba(3,16,34,.9)', 'text-halo-width': 2 } });
        suite.mapLayer = type;
        qa('[data-suite-map-layer]').forEach(button => button.classList.toggle('active', button.dataset.suiteMapLayer === type));
        qa('[data-advanced-radar-layer]').forEach(button => button.classList.remove('active'));
        const labels = { cloud: "" + meteonexaText("visualization.take.cloud_cover"), temperature: "" + meteonexaText("history.yrain.temperature"), wind: "" + meteonexaText("visualization.take.wind"), pressure: "" + meteonexaText("visualization.take.pressure"), snow: "" + meteonexaText("history.renderhistory.snow"), air: "" + meteonexaText("suite.showsuitemaplayer.air_quality"), marine: meteonexaText("suite.showsuitemaplayer.waves_sea") };
        q('#radar-source').textContent = `${labels[type]} · ${meteonexaText('map.grid.live')}`;
        q('#radar-map').insertAdjacentHTML('beforeend', "" + "<div class=\"suite-map-legend\"><strong>" + safe(labels[type]) + "</strong><span>" + safe(meteonexaText("suite.showsuitemaplayer.point_data_interpolated_grid_these_not_radar_observations")) + "</span></div>");
    }
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
    async function geocodeCity(name) {
        const p = new URLSearchParams({ name, count: '1', language: state?.settings?.language || 'it', format: 'json' }), data = await fetchJson(`${CONFIG.GEOCODING_API}?${p}`, { credentials: 'omit' }), row = data.results?.[0];
        if (!row)
            throw new Error(ui('route.error.location_not_found', { name }));
        return { name: [row.name, row.admin1].filter(Boolean).join(', '), latitude: n(row.latitude), longitude: n(row.longitude) };
    }
    function sampleGeometry(coordinates, count = 8) {
        if (!coordinates?.length)
            return [];
        const points = coordinates.map(([longitude, latitude]) => ({ latitude, longitude })), segments = [], total = points.slice(1).reduce((sum, point, i) => { const distance = haversine(points[i], point); segments.push(distance); return sum + distance; }, 0), result = [];
        for (let i = 0; i < count; i++) {
            const target = total * i / (count - 1);
            let walked = 0, index = 0;
            while (index < segments.length - 1 && walked + segments[index] < target) {
                walked += segments[index];
                index++;
            }
            const ratio = segments[index] ? clamp((target - walked) / segments[index], 0, 1) : 0, a = points[index], b = points[Math.min(points.length - 1, index + 1)];
            result.push({ latitude: a.latitude + (b.latitude - a.latitude) * ratio, longitude: a.longitude + (b.longitude - a.longitude) * ratio, ratio: i / (count - 1), bearing: bearing(a, b) });
        }
        return result;
    }
    async function routeWeather(points) { const data = await fetchJson(API.routeWeather,{method:'POST',timeout:26000,body:{points:points.map(point=>({latitude:Number(point.latitude),longitude:Number(point.longitude),ratio:Number(point.ratio||0),bearing:Number(point.bearing||0)}))}}); return Array.isArray(data?.weather)?data.weather:[]; }
    function routeRisk(row, index, bearingValue, mode) { const h = row.hourly || {}, temp = n(h.temperature_2m?.[index]), rain = n(h.precipitation_probability?.[index]), mm = n(h.precipitation?.[index]), snow = n(h.snowfall?.[index]), wind = n(h.wind_speed_10m?.[index]), gust = n(h.wind_gusts_10m?.[index]), windDirection = n(h.wind_direction_10m?.[index]), visibility = n(h.visibility?.[index]) / 1000, cape = n(h.cape?.[index]), humidity = n(h.relative_humidity_2m?.[index]), uv = n(h.uv_index?.[index]), crosswind = wind * Math.abs(Math.sin((windDirection - bearingValue) * Math.PI / 180)), ice = temp <= 2 && (mm > .05 || snow > 0), thunder = cape >= 800 || [95, 96, 99].includes(n(h.weather_code?.[index])); let score = Math.max(rain * .65, Math.min(100, gust * 1.2), visibility < 1 ? 95 : visibility < 3 ? 70 : 0, ice ? 90 : 0, thunder ? 90 : 0, Math.min(100, crosswind * (mode === 'bike' || mode === 'motorcycle' ? 2.2 : 1.2)));
        const profileKey = mode === 'motorcycle' ? 'motorcycle' : mode === 'bike' ? 'bike' : mode === 'walk' ? 'trekking' : null;
        const thresholds = profileKey ? SERVICES.get('accountSync')?.getActivityProfile?.(profileKey) : null;
        if (thresholds) {
            const rainMax=n(thresholds.rainMax),gustMax=n(thresholds.gustMax),tempMin=n(thresholds.tempMin),tempMax=n(thresholds.tempMax),visibilityMin=n(thresholds.visibilityMin);
            const personal=Math.max(rain>rainMax?55+Math.min(45,(rain-rainMax)*1.2):0,gust>gustMax?55+Math.min(45,(gust-gustMax)*2):0,temp<tempMin?55+Math.min(45,(tempMin-temp)*6):0,temp>tempMax?55+Math.min(45,(temp-tempMax)*6):0,visibility<visibilityMin?55+Math.min(45,(visibilityMin-visibility)*12):0);
            score=Math.max(score,personal);
        }
        return { score, temp, rain, mm, snow, wind, gust, windDirection, visibility, crosswind, ice, thunder, humidity, uv, code: n(h.weather_code?.[index]) }; }
    async function calculateRouteCore(origin, destination, departure, mode = 'car') {
        const p = new URLSearchParams({ originLat: origin.latitude, originLon: origin.longitude, destinationLat: destination.latitude, destinationLon: destination.longitude, mode: mode === 'motorcycle' ? 'car' : mode }), route = await fetchJson(`${API.route}?${p}`), points = sampleGeometry(route.geometry.coordinates, 24), weatherRows = await routeWeather(points), duration = route.durationSeconds / 3600;
        const evaluate = startDate => points.map((point, i) => { const at = new Date(startDate.getTime() + duration * point.ratio * 3600000), row = weatherRows[i], index = nearestTimeIndex(row.hourly?.time || [], at), risk = routeRisk(row, index, point.bearing, mode); return { ...point, at, name: i === 0 ? origin.name : i === points.length - 1 ? destination.name : ui('route.stop.number', { number: i + 1 }), ...risk }; });
        const candidates = [];
        // Evaluate departures every 30 minutes: this is precise enough to make
        // the recommendation actionable without multiplying upstream requests.
        for (let offset = -2; offset <= 12; offset++) {
            const at = new Date(departure.getTime() + offset * 30 * 60000);
            if (at < Date.now() - 1800000)
                continue;
            const rows = evaluate(at), score = Math.max(...rows.map(r => r.score));
            candidates.push({ at, score, rows });
        }
        const selected = evaluate(departure), selectedScore = Math.max(...selected.map(r => r.score));
        const best = [...candidates].sort((a, b) => a.score - b.score || a.at - b.at)[0] || { at: departure, score: selectedScore, rows: selected };
        return { origin, destination, mode, departure, route, points: selected, selectedScore, best, candidates, distanceKm: route.distanceMeters / 1000, durationHours: duration, steps: route.steps || [] };
    }
    function routeDateValue(date = new Date()) { const value = new Date(date); return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; }
    function syncRouteDeparture() {
        const dateNode = q('#route-departure-date'), timeNode = q('#route-departure-time'), target = q('#route-departure');
        if (!dateNode || !timeNode || !target)
            return;
        target.value = `${dateNode.value || routeDateValue(new Date())}T${timeNode.value || '09:00'}`;
    }
    function routeResultLabel(raw) {
        if (typeof normalizeLocation === 'function') {
            const item = normalizeLocation(raw);
            return [item.name, item.admin1, item.country].filter(Boolean).join(', ');
        }
        return [raw?.name, raw?.admin1, raw?.country].filter(Boolean).join(', ');
    }
    function bindRouteAutocomplete(inputSelector, resultsSelector) {
        const input = q(inputSelector), results = q(resultsSelector);
        if (!input || !results || input.dataset.autocompleteBound)
            return;
        input.dataset.autocompleteBound = 'true';
        let timer = 0;
        const clear = () => { results.innerHTML = ''; results.classList.remove('open'); };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const query = input.value.trim();
            if (query.length < 2) {
                clear();
                return;
            }
            timer = setTimeout(() => {
                if (typeof searchCities !== 'function')
                    return;
                results.classList.add('open');
                searchCities(query, results, raw => { input.value = routeResultLabel(raw); input.dataset.latitude = String(raw.latitude ?? ''); input.dataset.longitude = String(raw.longitude ?? ''); clear(); }, { compact: true });
            }, 240);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                clear();
                input.blur();
            }
        });
        document.addEventListener('pointerdown', event => {
            if (!event.target.closest(inputSelector) && !event.target.closest(resultsSelector))
                clear();
        });
    }
    function initializeRouteControls() {
        const hidden = q('#route-departure'), date = q('#route-departure-date'), time = q('#route-departure-time');
        let initial = hidden?.value ? new Date(hidden.value) : new Date(Date.now() + 60 * 60 * 1000);
        if (Number.isNaN(initial.getTime()))
            initial = new Date(Date.now() + 60 * 60 * 1000);
        initial.setMinutes(initial.getMinutes() < 30 ? 30 : 0, 0, 0);
        if (initial.getMinutes() === 0 && initial.getTime() < Date.now())
            initial.setHours(initial.getHours() + 1);
        if (date && !date.value) {
            date.value = routeDateValue(initial);
            date.dispatchEvent(new Event("meteo-date-sync"));
        }
        if (time) {
            const timeValue = `${String(initial.getHours()).padStart(2, '0')}:${String(initial.getMinutes()).padStart(2, '0')}`;
            time.value = timeValue;
            if (typeof syncEnhancedSelect === 'function')
                syncEnhancedSelect('route-departure-time', timeValue);
        }
        syncRouteDeparture();
        date?.addEventListener("meteo-date-sync", syncRouteDeparture);
        date?.addEventListener('change', syncRouteDeparture);
        time?.addEventListener('change', syncRouteDeparture);
        const originInput = q('#route-origin');
        if (originInput) {
            const currentOrigin = String(originInput.value || '').trim();
            if (!currentOrigin || currentOrigin.includes(CONFIG.DEFAULT_LOCATION.nameKey) || currentOrigin.includes(CONFIG.DEFAULT_LOCATION.admin1Key))
                originInput.value = locationLabel();
        }
        bindRouteAutocomplete('#route-origin', '#route-origin-results');
        bindRouteAutocomplete('#route-destination', '#route-destination-results');
        showRouteState(Boolean(suite.route));
    }
    function showRouteState(hasRoute) {
        const empty = q('#route-empty-state'), results = q('#route-results');
        if (empty)
            empty.hidden = hasRoute;
        if (results)
            results.hidden = !hasRoute;
    }
    function routeRiskLabel(score) { return score >= 75 ? ui('route.risk.high') : score >= 45 ? ui('route.risk.medium') : ui('route.risk.low'); }
    function distanceLabel(meters) { return meters >= 1000 ? ui('route.distance.km', { value: (meters / 1000).toFixed(meters >= 10000 ? 0 : 1) }) : ui('route.distance.m', { value: Math.max(1, Math.round(meters)) }); }
    function routeInstruction(step) {
        const maneuver = step?.maneuver || {}, name = step?.name || ui('route.instruction.road'), params = { street: name };
        if (maneuver.type === 'depart')
            return ui('route.instruction.depart', params);
        if (maneuver.type === 'arrive')
            return ui('route.instruction.arrive', params);
        if (maneuver.type === 'roundabout' || maneuver.type === 'rotary')
            return ui('route.instruction.roundabout', params);
        const modifier = String(maneuver.modifier || '').replaceAll(' ', '_');
        const key = ['left', 'right', 'slight_left', 'slight_right', 'sharp_left', 'sharp_right', 'straight', 'uturn'].includes(modifier) ? `route.instruction.${modifier}` : 'route.instruction.continue';
        return ui(key, params);
    }
    async function calculateRouteUI() {
        const originText = q('#route-origin')?.value.trim(), destinationText = q('#route-destination')?.value.trim();
        if (!originText || !destinationText) {
            toast(ui('route.toast.incomplete.title'), ui('route.toast.incomplete.copy'), 'warning');
            return;
        }
        await loader(ui('route.loader.title'), ui('route.loader.copy'), async () => { const [origin, destination] = await Promise.all([geocodeCity(originText), geocodeCity(destinationText)]), departure = new Date(q('#route-departure')?.value || Date.now()), mode = q('#suite-route-mode')?.value || 'car'; suite.route = await calculateRouteCore(origin, destination, departure, mode); SERVICES.get('metrics')?.track?.('use_route'); SERVICES.get('routeWeather')?.publish?.(suite.route); renderRoute(); renderAssistantSuggestions(); });
    }
    function routeCriticalLabel(row) {
        if (!row) return ui('route.intelligence.condition.clear');
        if (row.thunder) return ui('route.intelligence.condition.storm');
        if (row.ice) return ui('route.intelligence.condition.ice');
        if (row.visibility < 3) return ui('route.intelligence.condition.visibility', { value: row.visibility.toFixed(1) });
        if (row.crosswind >= 25 || row.gust >= 45) return ui('route.intelligence.condition.wind', { value: Math.round(Math.max(row.crosswind, row.gust)) });
        if (row.rain >= 55 || row.mm >= 1) return ui('route.intelligence.condition.rain', { value: Math.round(row.rain) });
        return ui('route.intelligence.condition.clear');
    }
    function renderRouteIntelligence(data) {
        const root = q('#route-intelligence-grid');
        const scoreNode = q('#route-intelligence-score');
        if (!root || !data) return;
        const selectedRisk = Math.round(n(data.selectedScore ?? Math.max(...data.points.map(p => p.score))));
        const bestRisk = Math.round(n(data.best?.score ?? selectedRisk));
        const improvement = Math.max(0, selectedRisk - bestRisk);
        const critical = (data.points || []).reduce((worst, row) => n(row.score) > n(worst?.score) ? row : worst, null);
        const bestCritical = (data.best?.rows || []).reduce((worst, row) => n(row.score) > n(worst?.score) ? row : worst, null);
        const safety = Math.max(0, 100 - bestRisk);
        if (scoreNode) scoreNode.textContent = `${safety}/100`;
        const departureCopy = improvement >= 8
            ? ui('route.intelligence.departure.improves', { time: localTime(data.best.at), from: selectedRisk, to: bestRisk })
            : ui('route.intelligence.departure.stable', { time: localTime(data.best.at), risk: bestRisk });
        const criticalCopy = critical
            ? ui('route.intelligence.critical.value', { stop: critical.name, time: localTime(critical.at), condition: routeCriticalLabel(critical) })
            : ui('route.intelligence.condition.clear');
        const avoidedCopy = improvement >= 8
            ? ui('route.intelligence.avoided.value', { condition: routeCriticalLabel(critical), bestCondition: routeCriticalLabel(bestCritical), points: improvement })
            : ui('route.intelligence.avoided.none');
        const rows = [
            ['i-clock', 'route.intelligence.departure.label', departureCopy],
            ['i-alert', 'route.intelligence.critical.label', criticalCopy],
            ['i-shield', 'route.intelligence.avoided.label', avoidedCopy]
        ];
        root.innerHTML = rows.map(([icon, label, value]) => `<div class="route-intelligence-item"><span><svg><use href="#${icon}"/></svg></span><div><small>${safe(ui(label))}</small><strong>${safe(value)}</strong></div></div>`).join('');
    }
    function renderRouteDepartureComparison(data) {
        const root = q('#route-departure-comparison');
        if (!root || !data) return;
        const candidates = (Array.isArray(data.candidates) ? data.candidates : []).slice().sort((a,b) => new Date(a.at) - new Date(b.at));
        if (!candidates.length) { root.innerHTML = `<div class="route-direction-empty">${safe(ui('route.compare.unavailable'))}</div>`; return; }
        const selectedTs = new Date(data.departure).getTime(), bestTs = new Date(data.best?.at || data.departure).getTime();
        root.innerHTML = candidates.map(candidate => {
            const ts = new Date(candidate.at).getTime(), risk = Math.max(0, Math.min(100, Math.round(n(candidate.score))));
            const isBest = Math.abs(ts - bestTs) < 1000, selected = Math.abs(ts - selectedTs) < 1000;
            const tags = [isBest ? ui('route.compare.best') : '', selected ? ui('route.compare.selected') : ''].filter(Boolean).join(' · ');
            return `<button class="route-departure-option${isBest?' is-best':''}${selected?' is-selected':''}" data-route-departure-at="${safe(new Date(candidate.at).toISOString())}" type="button" style="--risk:${risk}"><div class="route-departure-option-head"><strong>${safe(localTime(candidate.at))}</strong>${tags?`<span>${safe(tags)}</span>`:''}</div><div class="route-departure-riskbar"><i></i></div><small>${safe(ui('route.compare.risk',{value:risk}))}</small></button>`;
        }).join('');
    }
    function applyRouteDepartureCandidate(rawAt) {
        const data = suite.route;
        if (!data) return;
        const target = new Date(rawAt).getTime();
        const candidate = (data.candidates || []).find(row => Math.abs(new Date(row.at).getTime() - target) < 1000);
        if (!candidate) return;
        data.departure = new Date(candidate.at); data.points = candidate.rows; data.selectedScore = candidate.score;
        const date=q('#route-departure-date'), time=q('#route-departure-time');
        if (date) { date.value=routeDateValue(data.departure); date.dispatchEvent(new Event('meteo-date-sync')); }
        if (time) { const value=`${String(data.departure.getHours()).padStart(2,'0')}:${String(data.departure.getMinutes()).padStart(2,'0')}`; time.value=value; if(typeof syncEnhancedSelect==='function')syncEnhancedSelect('route-departure-time',value); }
        syncRouteDeparture(); renderRoute();
        toast(ui('route.compare.applied.title'),ui('route.compare.applied.copy',{time:localTime(data.departure)}),'success');
    }
    function renderRoute() {
        const data = suite.route;
        if (!data) {
            showRouteState(false);
            return;
        }
        showRouteState(true);
        const risk = Math.max(...data.points.map(p => p.score));
        q('#route-title').textContent = `${data.origin.name} → ${data.destination.name}`;
        q('#route-copy').textContent = risk >= 75 ? ui('route.summary.copy.high') : risk >= 45 ? ui('route.summary.copy.medium') : ui('route.summary.copy.low');
        q('#route-distance').textContent = `${Math.round(data.distanceKm)} km`;
        q('#route-duration').textContent = ui('route.duration.value', { hours: Math.floor(data.durationHours), minutes: Math.round(data.durationHours % 1 * 60) });
        q('#route-risk').textContent = routeRiskLabel(risk);
        q('#suite-route-best-time').textContent = data.best ? localTime(data.best.at) : '--';
        renderRouteIntelligence(data);
        renderRouteDepartureComparison(data);
        q('#route-timeline').innerHTML = data.points.map((p, i) => { const tags = [p.ice ? ui('route.tag.ice') : '', p.thunder ? ui('route.tag.storm') : '', p.snow > 0 ? ui('route.tag.snow') : '', p.crosswind >= 25 ? ui('route.tag.crosswind') : '', p.visibility < 3 ? ui('route.tag.visibility') : ''].filter(Boolean); return `<article class="route-stop"><div class="route-stop-line"><i></i><span>${i + 1}</span></div><div class="route-stop-card"><div class="route-stop-head"><div><small>${safe(localTime(p.at))}</small><strong>${safe(p.name)}</strong></div>${weatherArt(p.code, 1)}<b>${tempText(p.temp)}</b></div><div class="route-stop-metrics"><span><svg><use href="#i-umbrella"/></svg><strong>${Math.round(p.rain)}%</strong><small>${p.mm.toFixed(1)} mm</small></span><span><svg><use href="#i-wind"/></svg><strong>${Math.round(p.wind)} km/h</strong><small>${safe(ui('route.metric.crosswind', { value: Math.round(p.crosswind) }))}</small></span><span><svg><use href="#i-eye"/></svg><strong>${p.visibility.toFixed(1)} km</strong><small>${safe(ui('route.metric.visibility'))}</small></span></div>${tags.length ? `<div class="suite-risk-tags">${tags.map(tag => `<em>${safe(tag)}</em>`).join('')}</div>` : ''}</div></article>`; }).join('');
        renderRouteDirections();
        renderRouteMap();
    }
    function renderRouteDirections() {
        const root = q('#route-directions');
        if (!root)
            return;
        const steps = suite.route?.steps || [];
        root.innerHTML = steps.length ? steps.map((step, index) => `<article class="route-direction-step" data-route-step="${index}"><span>${index + 1}</span><div><strong>${safe(routeInstruction(step))}</strong><small>${safe(distanceLabel(n(step.distance)))} · ${safe(ui('route.duration.minutes', { minutes: Math.max(1, Math.round(n(step.duration) / 60)) }))}</small></div></article>`).join('') : `<div class="route-direction-empty">${safe(ui('route.directions.empty'))}</div>`;
    }
    function renderRouteMap() {
        const panel = q('#suite-route-map-panel'), root = q('#suite-route-map');
        if (!panel || !root || !window.maplibregl || !suite.route)
            return;
        suite.routeMapResizeObserver?.disconnect();
        suite.routeMapResizeObserver = null;
        if (suite.routeMap) {
            suite.routeMap.remove();
            suite.routeMap = null;
            suite.routeNavigation.marker = null;
        }
        const map = new maplibregl.Map({ container: root, style: CONFIG.OPENFREEMAP_STYLE, center: [suite.route.origin.longitude, suite.route.origin.latitude], zoom: 6, attributionControl: false });
        suite.routeMap = map;
        if (typeof ResizeObserver === 'function') {
            suite.routeMapResizeObserver = new ResizeObserver(() => {
                if (suite.routeMap === map)
                    map.resize();
            });
            suite.routeMapResizeObserver.observe(root);
        }
        map.on('load', () => {
            map.addSource('route-line', { type: 'geojson', data: { type: 'Feature', geometry: suite.route.route.geometry, properties: {} } });
            map.addLayer({ id: 'route-line', type: 'line', source: 'route-line', paint: { 'line-color': '#4fd8c8', 'line-width': 5, 'line-opacity': .88 } });
            suite.route.points.forEach((point, i) => new maplibregl.Marker({ color: i === 0 ? '#4fd8c8' : i === suite.route.points.length - 1 ? '#ff8b5f' : '#ffd456' }).setLngLat([point.longitude, point.latitude]).setPopup(new maplibregl.Popup({ offset: 18 }).setHTML(`<div class="suite-map-popup"><strong>${safe(point.name)}</strong><span>${safe(localTime(point.at))} · ${safe(ui('route.map.risk', { value: Math.round(point.score) }))}</span></div>`)).addTo(map));
            const bounds = new maplibregl.LngLatBounds();
            suite.route.route.geometry.coordinates.forEach(c => bounds.extend(c));
            const fitRoute = () => {
                if (suite.routeMap !== map)
                    return;
                map.resize();
                map.fitBounds(bounds, { padding: 45, duration: 0 });
            };
            requestAnimationFrame(fitRoute);
            setTimeout(fitRoute, 80);
        });
    }
    function nearestRoutePoint(position) { return (suite.route?.points || []).reduce((best, row) => { const distance = haversine(position, row); return !best || distance < best.distance ? { row, distance } : best; }, null); }
    function nearestRouteStep(position) {
        return (suite.route?.steps || []).reduce((best, row, index) => {
            const location = row?.maneuver?.location;
            if (!Array.isArray(location))
                return best;
            const distance = haversine(position, { latitude: n(location[1]), longitude: n(location[0]) });
            return !best || distance < best.distance ? { row, index, distance } : best;
        }, null);
    }
    function updateNavigation(position) {
        if (!suite.routeMap || !suite.route)
            return;
        const coords = [position.longitude, position.latitude];
        if (!suite.routeNavigation.marker)
            suite.routeNavigation.marker = new maplibregl.Marker({ color: '#37a7ff' }).setLngLat(coords).addTo(suite.routeMap);
        else
            suite.routeNavigation.marker.setLngLat(coords);
        suite.routeMap.easeTo({ center: coords, zoom: 13, duration: 650 });
        const nearest = nearestRoutePoint(position), step = nearestRouteStep(position);
        if (step) {
            q('#route-navigation-instruction').textContent = routeInstruction(step.row);
            q('#route-navigation-distance').textContent = distanceLabel(step.distance * 1000);
            qa('[data-route-step]').forEach((node, index) => node.classList.toggle('active', index === step.index));
        }
        if (nearest) {
            q('#route-navigation-weather').textContent = ui('route.navigation.weather', { temperature: tempText(nearest.row.temp), rain: Math.round(nearest.row.rain), wind: Math.round(nearest.row.wind) });
        }
    }
    function startRouteNavigation() {
        if (!suite.route)
            return;
        if (!navigator.geolocation) {
            toast(ui('route.navigation.unavailable.title'), ui('route.navigation.unavailable.copy'), 'warning');
            return;
        }
        q('#route-navigation-panel').hidden = false;
        suite.routeNavigation.active = true;
        suite.routeNavigation.watchId = navigator.geolocation.watchPosition(event => updateNavigation({ latitude: event.coords.latitude, longitude: event.coords.longitude }), error => { console.warn('ROUTE_GEOLOCATION_FAILED', error); toast(ui('route.navigation.error.title'), ui('route.navigation.error.copy'), 'error'); }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
        q('#route-navigation-instruction').textContent = ui('route.navigation.waiting');
        q('#route-navigation-weather').textContent = ui('route.navigation.waiting.copy');
    }
    function stopRouteNavigation() {
        if (suite.routeNavigation.watchId !== null)
            navigator.geolocation.clearWatch(suite.routeNavigation.watchId);
        suite.routeNavigation.watchId = null;
        suite.routeNavigation.active = false;
        suite.routeNavigation.marker?.remove();
        suite.routeNavigation.marker = null;
        const panel = q('#route-navigation-panel');
        if (panel)
            panel.hidden = true;
        qa('[data-route-step]').forEach(node => node.classList.remove('active'));
    }
    function clearRoute() { stopRouteNavigation(); suite.routeMapResizeObserver?.disconnect(); suite.routeMapResizeObserver = null; suite.routeMap?.remove(); suite.routeMap = null; suite.route = null; SERVICES.get('routeWeather')?.publish?.(null); q('#route-timeline').innerHTML = ''; q('#route-directions').innerHTML = ''; showRouteState(false); renderAssistantSuggestions(); q('#route-empty-state')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    const {
        renderAssistantSuggestions, setAssistantProviderStatus, setAssistantMode, openAssistantWithAi,
        openAssistantDialog, closeAssistantDialog, assistantRows, setAssistantBusy, addAssistant,
        resetAssistant, restoreAssistant, askAssistant, answerWithAi
    } = SERVICES.require('suiteAssistant').create({
        API, KEYS, addDays, apiMessage, calculateRouteCore, clamp, currentLocale, environmentPeaks,
        geocodeCity, isGuest, loadEnvironment, loader, localTime, locationLabel, modelConfidence, n, nearestTimeIndex,
        nowcastBase, nowcastReliability, q, qa, safe, snapshot, stabilityScore, state, suite, tempText, toast, ui, temperature
    });

    async function configureBackgroundChecks() {
        if (isGuest())
            return;
        if (!('serviceWorker' in navigator))
            return;
        try {
            const registration = await navigator.serviceWorker.ready, profile = loadAlertProfile();
            const remotePush = Boolean(await registration.pushManager?.getSubscription?.());
            registration.active?.postMessage({ type: 'METEONEXA_CONFIGURE_BACKGROUND', payload: { location: { latitude: n(state.location.latitude), longitude: n(state.location.longitude), name: locationLabel() }, thresholds: profile, weatherApi: CONFIG.WEATHER_API, language: state.settings.language, deviceId, deviceKey: SECURITY.deviceKey, remotePush, serverAuthoritative: true } });
            if ('periodicSync' in registration && Notification.permission === 'granted') {
                const tags = await registration.periodicSync.getTags();
                if (!tags.includes('meteonexa-weather-check'))
                    await registration.periodicSync.register('meteonexa-weather-check', { minInterval: 15 * 60 * 1000 });
            }
        }
        catch { }
    }
    function cloneAndBind(selector, handler, event = 'click') {
        const old = q(selector);
        if (!old)
            return null;
        const fresh = old.cloneNode(true);
        old.replaceWith(fresh);
        fresh.addEventListener(event, handler);
        return fresh;
    }
    function bind() {
        initializeRouteControls();
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-alert-profile]');
            if (!button)
                return;
            event.preventDefault();
            event.stopImmediatePropagation();
            applyProfile(button.dataset.alertProfile);
        }, true);
        qa('[data-suite-map-layer]').forEach(button => button.addEventListener('click', () => {
            const details = button.closest('.radar-more-layers'), label = q('.radar-more-layers-label');
            if (label)
                label.textContent = button.textContent.trim();
            if (details)
                details.open = false;
            showSuiteMapLayer(button.dataset.suiteMapLayer).catch(error => toast(meteonexaText("advanced.setadvancedradarlayer.unavailable"), error.message, 'warning'));
        }));
        qa('[data-advanced-radar-layer]').forEach(button => button.addEventListener('click', () => {
            removeSuiteMapLayer();
            qa('[data-suite-map-layer]').forEach(item => item.classList.remove('active'));
            const label = q('.radar-more-layers-label');
            if (label)
                label.textContent = meteonexaText('radar.layers.more');
            q('.radar-more-layers')?.removeAttribute('open');
        }, true));
        ['suite-cold', 'suite-pollen', 'suite-wave'].forEach(id => q(`#${id}`)?.addEventListener('input', () => { const profile = loadAlertProfile(); profile[id.replace('suite-', '')] = n(q(`#${id}`).value); localStorage.setItem(KEYS.alerts, JSON.stringify(profile)); updateExtendedOutputs(); renderExtendedAlerts(); configureBackgroundChecks(); }));
        q('#history-form')?.addEventListener('submit', () => setTimeout(() => loadEnhancedHistory().catch(error => toast(meteonexaText("suite.bind.historical_comparison_unavailable"), error.message, 'warning')), 80));
        qa('[data-history-days]').forEach(button => button.addEventListener('click', () => setTimeout(() => loadEnhancedHistory().catch(() => { }), 80)));
        q('#suite-history-csv')?.addEventListener('click', exportHistoryCsv);
        q('#suite-history-pdf')?.addEventListener('click', () => window.print());
        cloneAndBind('#route-calculate', () => loader(ui('route.action.calculate'), ui('assistant.analyzing'), calculateRouteUI, 480));
        cloneAndBind('#route-clear', clearRoute);
        cloneAndBind('#route-start-navigation', startRouteNavigation);
        cloneAndBind('#route-stop-navigation', stopRouteNavigation);
        q('#route-departure-comparison')?.addEventListener('click', event => { const button=event.target.closest?.('[data-route-departure-at]'); if(button)applyRouteDepartureCandidate(button.dataset.routeDepartureAt); });
        cloneAndBind('#assistant-center-button', openAssistantDialog);
        cloneAndBind('#assistant-close', closeAssistantDialog);
        qa('[data-assistant-mode-choice]').forEach(button => button.addEventListener('click', () => {
            if (suite.assistantBusy) return;
            setAssistantMode(button.dataset.assistantModeChoice === 'local' ? 'local' : 'ai', true);
        }));
        const assistantDialog = q('#assistant-dialog');
        assistantDialog?.addEventListener('cancel', event => { event.preventDefault(); closeAssistantDialog(); });
        assistantDialog?.addEventListener('click', event => { if (event.target === assistantDialog) closeAssistantDialog(); });
        const form = q('#assistant-form');
        if (form) {
            const fresh = form.cloneNode(true);
            form.replaceWith(fresh);
            fresh.addEventListener('submit', event => { event.preventDefault(); const input = q('#assistant-input'); askAssistant(input.value); input.value = ''; });
        }
        const assistantSuggestions = q('#assistant-suggestions');
        assistantSuggestions?.addEventListener('click', event => {
            const button = event.target.closest('[data-assistant-question]');
            if (button)
                askAssistant(button.dataset.assistantQuestion);
        });
        q('#assistant-messages')?.addEventListener('click', async event => {
            const button = event.target.closest('[data-assistant-switch]');
            if (!button)
                return;
            const choice = button.dataset.assistantSwitch;
            const question = String(suite.aiSwitchPending || '').trim();
            button.closest('.assistant-switch')?.remove();
            suite.aiSwitchPending = null;
            if (choice === 'ai' && question) {
                setAssistantMode('ai', true);
                await answerWithAi(question);
                return;
            }
            setAssistantMode('local');
            setAssistantBusy(false);
            addAssistant('assistant', ui('assistant.ai.offer.stay_local'), true, { source: 'local' });
            setTimeout(() => q('#assistant-input')?.focus(), 30);
        });
        renderAssistantSuggestions();
        cloneAndBind('#assistant-clear', () => {
            const rows = assistantRows();
            if (!rows.length) {
                toast(ui('assistant.clear.empty.toast.title'), ui('assistant.clear.empty.toast.copy'), 'warning');
                q('#assistant-input')?.focus();
                return;
            }
            resetAssistant();
            toast(ui('assistant.clear.toast.title'), ui('assistant.clear.toast.copy'));
        });
        restoreAssistant();
        const profile = loadAlertProfile();
        applyProfile(profile.name, false);
        updateExtendedOutputs();
        configureBackgroundChecks();
        suite.refreshTimer = setInterval(() => {
            if (document.visibilityState === 'visible' && state.weather) {
                loadWeather?.({ force: true, silent: true }).then(() => refreshAdvanced(true)).catch(() => { });
            }
        }, 5 * 60 * 1000);
        suite.assistantSuggestionTimer = setInterval(() => {
            if (document.visibilityState === 'visible' && state.weather)
                renderAssistantSuggestions();
        }, 60 * 1000);
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === 'visible' && state.weather && Date.now() - suite.modelFetchedAt > 5 * 60 * 1000)
                refreshAdvanced(false);
        });
    }
    async function refreshAdvanced(force = false) {
        if (!state.weather)
            return;
        const key = locationKey();
        if (suite.advancedRequest)
            return suite.advancedRequest;
        // advanced.js delegates to this engine and the lifecycle extension also
        // receives onPage. Suppress the immediate second pass so one navigation
        // produces one model/environment refresh and one final paint.
        if (!force && suite.advancedLocationKey === key && Date.now() - suite.advancedRefreshedAt < 3000)
            return;
        const loadingPanels=[...document.querySelectorAll('#page-advanced article.glass-panel')];loadingPanels.forEach(panel=>SERVICES.get('panelLoader')?.set?.(panel,true,meteonexaText('panel.loading')));
        suite.advancedRequest = (async () => {
            await Promise.allSettled([loadModels(force), loadEnvironment(), loadOfficialAlerts(force)]);
            suite.advancedRefreshedAt = Date.now();
            renderModels();
            await renderNowcast(force);
            renderExtendedAlerts();
            renderAssistantSuggestions();
            await saveSnapshot();
            suite.advancedLocationKey = key;
            suite.advancedRefreshedAt = Date.now();
        })();
        try {
            return await suite.advancedRequest;
        }
        finally {
            loadingPanels.forEach(panel=>SERVICES.get('panelLoader')?.set?.(panel,false));
            suite.advancedRequest = null;
        }
    }
    async function onPage(page) {
        // Re-evaluate dynamic private panels on every navigation so a guest can
        // never retain Radar archive markup after a session/visibility change.
        // ensureUI belongs to the diagnostics/radar module below: use its public
        // bridge instead of crossing the IIFE scope boundary.
        SERVICES.get('suite')?.syncVisibility?.();
        if (page === 'advanced')
            await refreshAdvanced(false);
        if (page === 'history' && !suite.history)
            setTimeout(() => loadEnhancedHistory().catch(() => { }), 120);
        if (page === 'alerts') {
            await loadEnvironment();
            renderExtendedAlerts();
        }
        if (page === "radar" && suite.mapLayer)
            setTimeout(() => showSuiteMapLayer(suite.mapLayer).catch(() => { }), 650);
        if (page === 'devices')
            configureBackgroundChecks();
    }
    let unregisterAdvancedLifecycle = null;
    function patchLifecycle() {
        const advanced = SERVICES.get('advanced');
        if (!advanced || unregisterAdvancedLifecycle)
            return;
        if (typeof advanced.registerLifecycleHook !== 'function')
            throw new Error('METEONEXA_ADVANCED_LIFECYCLE_API_NOT_READY');
        unregisterAdvancedLifecycle = advanced.registerLifecycleHook(Object.freeze({
            renderAll() {
                if (state.currentPage !== 'advanced' || !state.weather)
                    return;
                if (!suite.modelRows.length) {
                    refreshAdvanced(false).catch(error => console.warn('SUITE_ADVANCED_BACKGROUND_LOAD_FAILED', error));
                    return;
                }
                // The app deliberately paints a safe placeholder forecast before the live
                // request completes. Re-run the weather-dependent panels when the full
                // payload (including minutely_15) arrives, without re-fetching the models.
                renderNowcast(false).catch(error => console.warn('SUITE_NOWCAST_BACKGROUND_RENDER_FAILED', error));
                renderExtendedAlerts();
                renderAssistantSuggestions();
                saveSnapshot().catch(() => { });
            },
            onPage,
            locationChanged() {
                suite.modelRows = []; suite.modelFetchedAt = 0; suite.canonicalConsensus = null; suite.advancedRefreshedAt = 0; suite.advancedLocationKey = '';
                suite.direction = null; suite.environment = null; suite.history = null; suite.route = null;
                removeSuiteMapLayer();
                configureBackgroundChecks();
            }
        }));
    }
    document.addEventListener('meteonexa:notifications-opened', () => {
        if (isGuest() || !state.weather) return;
        loadEnvironment().then(() => renderExtendedAlerts()).catch(() => renderExtendedAlerts());
    });
    document.addEventListener('meteonexa:ready', () => {
        bind();
        patchLifecycle();
        setTimeout(() => {
            if (state.currentPage === 'advanced' && state.weather && !suite.advancedRefreshedAt)
                refreshAdvanced(false).catch(() => { });
        }, 250);
    }, { once: true });
    const suiteService = Object.assign(SERVICES.get('suite') || {}, { build: BUILD, refresh: refreshAdvanced, calculateRoute: calculateRouteUI, openAssistant: openAssistantDialog, openAssistantWithAi });
    SERVICES.publish('suite', suiteService);
})();

(() => {
    'use strict';
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const APP_RUNTIME = SERVICES.require('runtimeApi').get();
    const { appLocale } = APP_RUNTIME;
    const currentLocale = () => appLocale();
    const dialog = () => document.querySelector('#meteo-date-dialog');
    const state = { targetId: '', view: new Date(), selected: '' };
    const pad = value => String(value).padStart(2, '0');
    const iso = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const parse = value => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match)
            return null;
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    };
    const format = value => {
        const date = value instanceof Date ? value : parse(value);
        return date ? new Intl.DateTimeFormat(currentLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date) : meteonexaText("suite.format.select_date");
    };
    const calendarLocaleData = () => {
        const locale = currentLocale();
        let weekInfo = null;
        try {
            weekInfo = new Intl.Locale(locale).weekInfo || new Intl.Locale(locale).getWeekInfo?.();
        }
        catch { }
        const firstDay = Number(weekInfo?.firstDay || 1);
        const firstNative = firstDay % 7;
        const weekdayOrder = Array.from({ length: 7 }, (_, index) => ((firstNative + index) % 7));
        const weekdayLabels = weekdayOrder.map(dayIndex => {
            const reference = new Date(2024, 0, 7 + dayIndex, 12);
            return new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(reference).replace('.', '').slice(0, 2);
        });
        return { firstNative, weekdayLabels };
    };
    function sync(targetId) {
        const input = document.getElementById(targetId);
        if (!input)
            return;
        document.querySelectorAll(`[data-meteo-date-target="${CSS.escape(targetId)}"]`).forEach(trigger => {
            const label = trigger.querySelector('[data-meteo-date-label]');
            if (label)
                label.textContent = format(input.value);
            trigger.setAttribute('aria-label', meteonexaText('date.control.aria', { label: trigger.closest('label')?.querySelector(':scope > span')?.textContent || "" + meteonexaText("history.renderhistory.date"), date: format(input.value) }));
        });
    }
    function render() {
        const root = dialog();
        if (!root)
            return;
        const monthNode = root.querySelector('#meteo-date-month');
        const weekdays = root.querySelector('#meteo-date-weekdays');
        const grid = root.querySelector('#meteo-date-grid');
        const selectedDate = parse(state.selected);
        const year = state.view.getFullYear();
        const month = state.view.getMonth();
        const { firstNative, weekdayLabels } = calendarLocaleData();
        if (monthNode)
            monthNode.textContent = new Intl.DateTimeFormat(currentLocale(), { month: 'long', year: 'numeric' }).format(new Date(year, month, 1, 12));
        if (weekdays)
            weekdays.innerHTML = weekdayLabels.map(label => `<span>${safe(label)}</span>`).join('');
        if (!grid)
            return;
        const monthStart = new Date(year, month, 1, 12);
        const nativeDay = monthStart.getDay();
        const offset = (nativeDay - firstNative + 7) % 7;
        const days = new Date(year, month + 1, 0).getDate();
        const today = iso(new Date());
        const cells = [];
        for (let index = 0; index < offset; index += 1)
            cells.push('<span class="meteo-date-empty" aria-hidden="true"></span>');
        for (let day = 1; day <= days; day += 1) {
            const value = iso(new Date(year, month, day, 12));
            const selected = selectedDate && value === state.selected;
            const isToday = value === today;
            cells.push(`<button type="button" role="gridcell" data-meteo-date-value="${value}" class="${selected ? 'selected ' : ''}${isToday ? 'today' : ''}" aria-selected="${selected ? 'true' : 'false'}"><span>${day}</span></button>`);
        }
        grid.innerHTML = cells.join('');
    }
    function open(targetId) {
        const root = dialog();
        const input = document.getElementById(targetId);
        if (!root || !input)
            return;
        state.targetId = targetId;
        state.selected = input.value || iso(new Date());
        state.view = parse(state.selected) || new Date();
        render();
        if (typeof root.showModal === 'function')
            root.showModal();
        else
            root.setAttribute('open', '');
    }
    function close() {
        const root = dialog();
        if (!root)
            return;
        if (root.open && typeof root.close === 'function')
            root.close();
        else
            root.removeAttribute('open');
    }
    function select(value) {
        const input = document.getElementById(state.targetId);
        if (!input)
            return;
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event("meteo-date-sync"));
        sync(state.targetId);
        close();
    }
    function setValue(targetId, value, emit = false) {
        const input = document.getElementById(targetId);
        if (!input)
            return;
        input.value = value;
        sync(targetId);
        if (emit)
            input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.addEventListener('click', event => {
        const trigger = event.target.closest?.('[data-meteo-date-target]');
        if (trigger) {
            event.preventDefault();
            open(trigger.dataset.meteoDateTarget);
            return;
        }
        const valueButton = event.target.closest?.('[data-meteo-date-value]');
        if (valueButton) {
            event.preventDefault();
            select(valueButton.dataset.meteoDateValue);
        }
    });
    document.addEventListener("meteo-date-sync", event => {
        if (event.target?.id)
            sync(event.target.id);
    }, true);
    const bindDatePickerDom = () => {
        const root = dialog();
        if (!root)
            return;
        root.querySelector('#meteo-date-prev')?.addEventListener('click', () => { state.view = new Date(state.view.getFullYear(), state.view.getMonth() - 1, 1, 12); render(); });
        root.querySelector('#meteo-date-next')?.addEventListener('click', () => { state.view = new Date(state.view.getFullYear(), state.view.getMonth() + 1, 1, 12); render(); });
        root.querySelector('#meteo-date-today')?.addEventListener('click', () => select(iso(new Date())));
        root.querySelector('#meteo-date-cancel')?.addEventListener('click', close);
        root.querySelector('.meteo-date-close')?.addEventListener('click', close);
        root.addEventListener('click', event => {
            if (event.target === root)
                close();
        });
        document.querySelectorAll('[data-meteo-date-target]').forEach(trigger => sync(trigger.dataset.meteoDateTarget));
        const observer = new MutationObserver(records => {
            records.forEach(record => record.addedNodes.forEach(node => {
                if (!(node instanceof Element))
                    return;
                const triggers = node.matches?.('[data-meteo-date-target]') ? [node] : [...node.querySelectorAll?.('[data-meteo-date-target]') || []];
                triggers.forEach(trigger => sync(trigger.dataset.meteoDateTarget));
            }));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindDatePickerDom, { once: true });
    else queueMicrotask(bindDatePickerDom);
    const datePickerService = Object.assign(SERVICES.get('datePicker') || {}, { sync, setValue, format, open });
    SERVICES.publish('datePicker', datePickerService);
})();
