export const serviceNames = Object.freeze(['suiteWeatherIntelligence']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.suiteWeatherIntelligence = Object.freeze({
        create(context) {
            const {
                CONFIG, SERVICES, API, KEYS, suite, state, q, qa, n, clamp, safe, currentLocale,
                mean, deviation, localTime, tempText, locationLabel, locationKey, ui, toast, loader,
                deviceId, isGuest, fetchJson, dateInput, nearestTimeIndex, directionName, updateThreshold,
                temperature, weatherMeta, weatherArt, meteonexaText, sendDeviceNotification
            } = context;
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

            return Object.freeze({
                loadModels, modelConfidence, renderModels, nowcastBase, snapshot, stabilityScore,
                nowcastReliability, saveSnapshot, renderNowcast, loadOfficialAlerts, loadEnvironment,
                loadAlertProfile, applyProfile, updateExtendedOutputs, environmentPeaks, renderExtendedAlerts
            });
        }
    });
}

export function install(services, host = globalThis) {
    return services.installModule({ services, host, provides: serviceNames, dependencies, factory });
}
