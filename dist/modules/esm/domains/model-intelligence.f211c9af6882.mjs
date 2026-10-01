export const serviceNames = Object.freeze(['modelIntelligence']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.modelIntelligence = Object.freeze({
        create(context) {
            const {
                state, localSeriesIndex, PREVIEW_MODE, loadForecastFusion, showToast, t, shortLocationLabel, withLoader,
                currentHourlyIndex, loadJSON, STORAGE, saveJSON, $, $$, clamp, formatClock, temperature, weatherMeta,
                escapeHTML, translateDOM, meteonexaText, isGuestSession, buildAutoCalibratedEnsemble, ensembleWeightLabel,
                renderVerifiedModelAccuracy, loadPublicLocalAccuracy, renderPersonalImpact, renderStoredBriefing, renderProactiveInsight,
                loadPersonalWeatherPreferences, maybeGenerateProactiveInsight, syncVerifiedModelAccuracy, getPersonalWeatherPrefs
            } = context;
            if (!state || typeof $ !== 'function' || typeof t !== 'function') {
                throw new Error('METEONEXA_MODEL_INTELLIGENCE_CONTEXT_INVALID');
            }
            let accuracyBackgroundScheduled = false;

            function intelligenceLocationKey(locationData = state.location) {
                return `${Number(locationData?.latitude || 0).toFixed(4)}:${Number(locationData?.longitude || 0).toFixed(4)}`;
            }
            function numericValues(values = []) {
                return values.map(Number).filter(Number.isFinite);
            }
            function average(values = []) {
                const clean = numericValues(values);
                return clean.length ? clean.reduce((sum, value) => sum + value, 0) / clean.length : 0;
            }
            function standardDeviation(values = []) {
                const clean = numericValues(values);
                if (clean.length < 2)
                    return 0;
                const mean = average(clean);
                return Math.sqrt(clean.reduce((sum, value) => sum + ((value - mean) ** 2), 0) / clean.length);
            }
            function summarizeModelForecast(raw, definition) {
                const hourly = raw?.hourly;
                if (!hourly?.time?.length)
                    return null;
                const start = localSeriesIndex(hourly.time, { currentTime: state.weather?.current?.time || '', weatherData: raw });
                const end = Math.min(hourly.time.length, start + 24);
                const range = (key) => (hourly[key] || []).slice(start, end).map(Number);
                const temperatures = range("temperature_2m");
                const precipitation = range('precipitation');
                const winds = range('wind_speed_10m');
                const gusts = range('wind_gusts_10m');
                const firstWetOffset = precipitation.findIndex(value => Number(value) >= 0.1);
                const currentCode = Number(hourly.weather_code?.[start] ?? state.weather?.current?.weather_code ?? 0);
                return {
                    ...definition,
                    raw,
                    start,
                    temperatureMax: temperatures.length ? Math.max(...temperatures) : 0,
                    temperatureMin: temperatures.length ? Math.min(...temperatures) : 0,
                    temperatureAverage: average(temperatures),
                    precipitationTotal: precipitation.reduce((sum, value) => sum + (Number.isFinite(value) ? value : 0), 0),
                    rainHours: precipitation.filter(value => value >= 0.1).length,
                    windMax: Math.max(0, ...numericValues(winds), ...numericValues(gusts)),
                    firstRain: firstWetOffset >= 0 ? hourly.time[start + firstWetOffset] : '',
                    code: currentCode,
                    temperatureSeries: temperatures.slice(0, 12),
                    precipitationSeries: precipitation.slice(0, 12)
                };
            }
            function summarizeServerIntelligenceModel(model, definition) {
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
                const firstWet = windowRows.find(row => Number(row.precipitation) >= 0.1);
                return {
                    ...definition,
                    serverOwned: true,
                    stale: model?.stale === true,
                    retrievedAt: model?.retrievedAt || null,
                    temperatureMax: Math.max(...temperatures),
                    temperatureMin: Math.min(...temperatures),
                    temperatureAverage: average(temperatures),
                    precipitationTotal: precipitation.reduce((sum, value) => sum + value, 0),
                    rainHours: precipitation.filter(value => value >= 0.1).length,
                    windMax: gusts.length ? Math.max(...gusts) : 0,
                    firstRain: firstWet?.time || '',
                    code: Number(windowRows[0]?.weatherCode ?? state.weather?.current?.weather_code ?? 0),
                    temperatureSeries: windowRows.slice(0, 12).map(row => Number(row.temperature)).filter(Number.isFinite),
                    precipitationSeries: windowRows.slice(0, 12).map(row => Math.max(0, Number(row.precipitation || 0)))
                };
            }
            function createPreviewIntelligenceModels() {
                if (!state.weather?.hourly)
                    return [];
                const base = summarizeModelForecast(state.weather, { id: 'auto', label: meteonexaText('app.name'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.blended_forecast") });
                if (!base)
                    return [];
                const definitions = [
                    { id: 'ecmwf', label: meteonexaText('provider.ecmwf'), description: "" + meteonexaText("intelligence.high_resolution_european_model"), temp: 0.4, rain: 0.88, wind: 1.02 },
                    { id: 'aifs', label: meteonexaText('provider.aifs'), description: meteonexaText('model.aifs.description'), temp: 0.25, rain: 0.93, wind: 1.01 },
                    { id: 'icon', label: meteonexaText('provider.icon'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.european_icon_model"), temp: -0.3, rain: 1.08, wind: 0.96 },
                    { id: 'gfs', label: meteonexaText('provider.gfs'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.noaa_global_model"), temp: 0.1, rain: 0.98, wind: 1.06 },
                    { id: 'meteofrance', label: meteonexaText('provider.meteofrance'), description: meteonexaText('accuracy.preview.meteofrance'), temp: 0.2, rain: 1.03, wind: 0.99 },
                    { id: 'ukmo', label: meteonexaText('provider.ukmo'), description: meteonexaText('accuracy.preview.ukmo'), temp: -0.1, rain: 0.94, wind: 1.03 }
                ];
                return definitions.map(item => ({
                    ...base,
                    ...item,
                    temperatureMax: base.temperatureMax + item.temp,
                    temperatureMin: base.temperatureMin + item.temp,
                    temperatureAverage: base.temperatureAverage + item.temp,
                    precipitationTotal: base.precipitationTotal * item.rain,
                    windMax: base.windMax * item.wind,
                    temperatureSeries: base.temperatureSeries.map(value => value + item.temp),
                    precipitationSeries: base.precipitationSeries.map(value => value * item.rain)
                }));
            }
            async function loadIntelligence({ force = false, silent = false } = {}) {
                if (state.intelligence.request)
                    return state.intelligence.request;
                const key = intelligenceLocationKey();
                const fresh = state.intelligence.locationKey === key && Date.now() - Number(state.intelligence.fetchedAt || 0) < 15 * 60 * 1000;
                if (!force && fresh && state.intelligence.models.length) {
                    renderIntelligence();
                    return state.intelligence.models;
                }
                const task = async () => {
                    if (PREVIEW_MODE || !navigator.onLine) {
                        state.intelligence.models = createPreviewIntelligenceModels();
                        state.intelligence.fetchedAt = Date.now();
                        state.intelligence.locationKey = key;
                        renderIntelligence();
                        return state.intelligence.models;
                    }
                    const definitions = new Map([
                        ['ecmwf', { id: 'ecmwf', label: meteonexaText('provider.ecmwf'), description: "" + meteonexaText("intelligence.high_resolution_european_model") }],
                        ['aifs', { id: 'aifs', label: meteonexaText('provider.aifs'), description: meteonexaText('model.aifs.description') }],
                        ['icon', { id: 'icon', label: meteonexaText('provider.icon'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.european_icon_model") }],
                        ['gfs', { id: 'gfs', label: meteonexaText('provider.gfs'), description: "" + meteonexaText("intelligence.createpreviewintelligencemodels.noaa_global_model") }],
                        ['meteofrance', { id: 'meteofrance', label: meteonexaText('provider.meteofrance'), description: meteonexaText('accuracy.preview.meteofrance') }],
                        ['ukmo', { id: 'ukmo', label: meteonexaText('provider.ukmo'), description: meteonexaText('accuracy.preview.ukmo') }]
                    ]);
                    const fusion = await loadForecastFusion({ force });
                    const serverModels = Array.isArray(fusion?.models) ? fusion.models : [];
                    state.intelligence.models = serverModels.flatMap(model => {
                        const id = String(model?.id || '');
                        const definition = definitions.get(id) || { id, label: String(model?.label || id.toUpperCase()), description: meteonexaText('model.description.server_owned') };
                        const summary = summarizeServerIntelligenceModel(model, definition);
                        return summary ? [summary] : [];
                    });
                    state.intelligence.fetchedAt = Date.now();
                    state.intelligence.locationKey = key;
                    updateIntelligenceSnapshot(state.weather);
                    renderIntelligence();
                    if (!silent) {
                        if (state.intelligence.models.length === Number(fusion?.modelsExpected || state.intelligence.models.length))
                            showToast(t("intelligence.task.analysis_updated"), t("intelligence.three_models_compared_value", { location: shortLocationLabel(state.location) }), 'success');
                        else if (state.intelligence.models.length)
                            showToast(t("intelligence.task.partial_analysis"), t("intelligence.some_models_did_not_respond_available_data_shown"), 'warning');
                        else
                            showToast(t("intelligence.task.analysis_unavailable"), t("intelligence.no_model_responded_try_again_few_minutes"), 'error');
                    }
                    return state.intelligence.models;
                };
                state.intelligence.request = silent
                    ? task()
                    : withLoader(t("intelligence.task.updating_analysis"), t("intelligence.retrieving_forecasts_from_main_models"), task, 520);
                try {
                    return await state.intelligence.request;
                }
                finally {
                    state.intelligence.request = null;
                }
            }
            function createIntelligenceSnapshot(data = state.weather) {
                if (!data?.hourly?.time?.length)
                    return null;
                const start = currentHourlyIndex(data);
                const end24 = Math.min(data.hourly.time.length, start + 24);
                const temperatures = (data.hourly.temperature_2m || []).slice(start, end24).map(Number);
                const precipitation = (data.hourly.precipitation || []).slice(start, end24).map(Number);
                const winds = (data.hourly.wind_gusts_10m || data.hourly.wind_speed_10m || []).slice(start, end24).map(Number);
                const firstWet = precipitation.findIndex(value => value >= 0.1);
                const modelRainTimes = (state.intelligence.models || []).filter(model => model.firstRain).map(model => new Date(model.firstRain).getTime()).filter(Number.isFinite);
                const modelRainSpreadMinutes = modelRainTimes.length >= 2 ? Math.round((Math.max(...modelRainTimes) - Math.min(...modelRainTimes)) / 60000) : null;
                return {
                    locationKey: intelligenceLocationKey(),
                    fetchedAt: Number(data.fetchedAt || Date.now()),
                    rain24: precipitation.reduce((sum, value) => sum + (Number.isFinite(value) ? value : 0), 0),
                    tempMax24: temperatures.length ? Math.max(...numericValues(temperatures)) : 0,
                    windMax24: winds.length ? Math.max(...numericValues(winds)) : 0,
                    firstRain: firstWet >= 0 ? data.hourly.time[start + firstWet] : '',
                    pressure: Number(data.current?.surface_pressure ?? 0),
                    windDirection: Number(data.current?.wind_direction_10m ?? 0),
                    modelRainSpreadMinutes,
                    modelCount: Number((state.intelligence.models || []).length)
                };
            }
            function updateIntelligenceSnapshot(data = state.weather) {
                const next = createIntelligenceSnapshot(data);
                if (!next)
                    return;
                const stored = loadJSON(STORAGE.intelligenceSnapshot, null);
                if (stored?.locationKey === next.locationKey && Number(stored.fetchedAt || 0) !== Number(next.fetchedAt || 0)) {
                    state.intelligence.previousSnapshot = stored;
                }
                state.intelligence.currentSnapshot = next;
                saveJSON(STORAGE.intelligenceSnapshot, next);
            }
            function extractNowcast() {
                const data = state.weather;
                const series = data?.minutely_15;
                let anchors = [];
                let points = [];
                if (series?.time?.length) {
                    const start = localSeriesIndex(series.time, { currentTime: data.current?.time || '', weatherData: data });
                    anchors = series.time.slice(start, start + 9).map((time, offset) => ({
                        time,
                        precipitation: Number(series.precipitation?.[start + offset] || series.rain?.[start + offset] || 0),
                        code: Number(series.weather_code?.[start + offset] ?? data.current?.weather_code ?? 0),
                        exactQuarter: true
                    }));
                    // Open-Meteo exposes 15-minute source points. Interpolate the visual
                    // timeline every 5 minutes without pretending that the source itself is
                    // minute-resolution. Exact quarter-hour source points stay marked.
                    anchors.slice(0, -1).forEach((anchor, index) => {
                        const next = anchors[index + 1];
                        const aTime = new Date(anchor.time).getTime();
                        const bTime = new Date(next.time).getTime();
                        [0, 1, 2].forEach(step => {
                            const ratio = step / 3;
                            points.push({
                                time: new Date(aTime + (bTime - aTime) * ratio).toISOString(),
                                precipitation: anchor.precipitation + (next.precipitation - anchor.precipitation) * ratio,
                                code: ratio < 0.5 ? anchor.code : next.code,
                                exactQuarter: step === 0
                            });
                        });
                    });
                    if (anchors.length)
                        points.push({ ...anchors.at(-1), time: new Date(anchors.at(-1).time).toISOString(), exactQuarter: true });
                }
                else if (data?.hourly?.time?.length) {
                    const start = currentHourlyIndex(data);
                    anchors = data.hourly.time.slice(start, start + 3).map((time, offset) => ({
                        time,
                        precipitation: Number(data.hourly.precipitation?.[start + offset] || 0),
                        code: Number(data.hourly.weather_code?.[start + offset] ?? data.current?.weather_code ?? 0),
                        exactQuarter: true
                    }));
                    points = anchors;
                }
                const wetIndexes = points.map((point, index) => point.precipitation >= 0.05 ? index : -1).filter(index => index >= 0);
                const rainingNow = Boolean(wetIndexes.length && wetIndexes[0] === 0);
                const firstWet = wetIndexes[0] ?? -1;
                let lastWet = firstWet;
                if (firstWet >= 0) {
                    for (let index = firstWet + 1; index < points.length; index += 1) {
                        if (points[index].precipitation < 0.05)
                            break;
                        lastWet = index;
                    }
                }
                let estimatedStart = firstWet >= 0 ? new Date(points[firstWet].time) : null;
                // Refine threshold crossing between the real 15-minute source points. This
                // is explicitly an estimate and gives useful countdowns such as ~18 min.
                const firstWetAnchor = anchors.findIndex(point => point.precipitation >= 0.05);
                if (firstWetAnchor > 0) {
                    const a = anchors[firstWetAnchor - 1], b = anchors[firstWetAnchor];
                    const ratio = clamp((0.05 - a.precipitation) / Math.max(0.001, b.precipitation - a.precipitation), 0, 1);
                    const aTime = new Date(a.time).getTime(), bTime = new Date(b.time).getTime();
                    estimatedStart = new Date(aTime + (bTime - aTime) * ratio);
                }
                const peak = anchors.length ? Math.max(...anchors.map(point => point.precipitation)) : 0;
                const total = anchors.reduce((sum, point) => sum + point.precipitation, 0);
                const intensity = peak >= 2 ? t("intelligence.extractnowcast.heavy") : peak >= 0.7 ? t("intelligence.extractnowcast.moderate") : peak >= 0.05 ? t("intelligence.extractnowcast.light") : t("intelligence.extractnowcast.none");
                return {
                    points, anchors, rainingNow, firstWet, lastWet, peak, total, intensity,
                    start: estimatedStart ? estimatedStart.toISOString() : '',
                    end: lastWet >= 0 && lastWet + 1 < points.length ? points[lastWet + 1]?.time : (lastWet >= 0 ? '' : '')
                };
            }
            function nowcastProReliability(nowcast) {
                const model = confidenceFromModels().score;
                const snapshotCurrent = state.intelligence.currentSnapshot;
                const snapshotPrevious = state.intelligence.previousSnapshot;
                let stability = 72;
                if (snapshotCurrent && snapshotPrevious && snapshotCurrent.locationKey === snapshotPrevious.locationKey) {
                    const rainDelta = Math.abs(Number(snapshotCurrent.rain24 || 0) - Number(snapshotPrevious.rain24 || 0));
                    const windDelta = Math.abs(Number(snapshotCurrent.windMax24 || 0) - Number(snapshotPrevious.windMax24 || 0));
                    stability = clamp(Math.round(96 - rainDelta * 5 - windDelta * 0.7), 38, 98);
                }
                const sourceBonus = nowcast?.anchors?.length >= 7 ? 92 : 55;
                return clamp(Math.round(model * 0.55 + stability * 0.3 + sourceBonus * 0.15), 35, 98);
            }
            function confidenceFromModels(models = state.intelligence.models) {
                if (!models.length)
                    return { score: 62, label: t("intelligence.confidencefrommodels.waiting_models"), agreement: t("intelligence.confidencefrommodels.unavailable") };
                const tempSpread = Math.max(...models.map(model => model.temperatureMax)) - Math.min(...models.map(model => model.temperatureMax));
                const rainSpread = standardDeviation(models.map(model => model.precipitationTotal));
                const windSpread = Math.max(...models.map(model => model.windMax)) - Math.min(...models.map(model => model.windMax));
                const expectedModels = Number(state.forecastFusion?.modelsExpected || models.length);
                const coveragePenalty = Math.max(0, (expectedModels - models.length) * 7);
                const score = clamp(Math.round(97 - tempSpread * 6 - Math.min(24, rainSpread * 5) - Math.min(18, windSpread * 0.8) - coveragePenalty), 38, 98);
                if (score >= 82)
                    return { score, label: t("intelligence.confidencefrommodels.very_high_confidence"), agreement: t("intelligence.confidencefrommodels.very_high") };
                if (score >= 66)
                    return { score, label: t("intelligence.confidencefrommodels.good_confidence"), agreement: t("intelligence.confidencefrommodels.good") };
                return { score, label: t("intelligence.confidencefrommodels.variable_forecast"), agreement: t("intelligence.confidencefrommodels.variable") };
            }
            function nowcastMessage(nowcast) {
                if (!nowcast.points.length)
                    return { title: t("intelligence.nowcastmessage.nowcasting_unavailable"), copy: t("intelligence.15_minute_data_not_available_location") };
                if (nowcast.firstWet < 0)
                    return { title: t("intelligence.nowcastmessage.no_significant_rain"), copy: t("intelligence.no_significant_rain_expected_next_2_hours") };
                if (nowcast.rainingNow)
                    return {
                        title: t("intelligence.nowcastmessage.rain_progress"),
                        copy: nowcast.end ? t("intelligence.rain_should_ease_around_value", { time: formatClock(nowcast.end) }) : t("intelligence.precipitation_may_continue_beyond_next_2_hours")
                    };
                return {
                    title: t("intelligence.nowcastmessage.rain_possible_soon"),
                    copy: nowcast.end
                        ? t("intelligence.rain_possible_from_value_value", { start: formatClock(nowcast.start), end: formatClock(nowcast.end) })
                        : t("intelligence.rain_possible_from_value_may_last_beyond_2", { start: formatClock(nowcast.start) })
                };
            }
            function modelForecastStrip(model) {
                const hourly=model?.raw?.hourly||{},start=Math.max(0,Number(model?.start||0));const offsets=[0,2,4,6,8,10];
                const cells=offsets.flatMap(offset=>{const time=hourly.time?.[start+offset];if(!time)return[];const tempValue=Number(hourly.temperature_2m?.[start+offset]);const rainValue=Math.max(0,Number(hourly.precipitation?.[start+offset]||0));const probability=Number(hourly.precipitation_probability?.[start+offset]);return[{time,temp:Number.isFinite(tempValue)?tempValue:null,rain:rainValue,probability:Number.isFinite(probability)?probability:null}];});
                if(!cells.length)return'';const peak=Math.max(.05,...cells.map(cell=>cell.rain));
                return `<div class="model-hour-strip">${cells.map(cell=>`<div class="model-hour-point" style="--rain:${Math.max(.04,cell.rain/peak).toFixed(3)}"><small>${escapeHTML(formatClock(cell.time))}</small><strong>${cell.temp===null?'--':escapeHTML(temperature(cell.temp))}</strong><span><i></i></span><em>${cell.rain.toFixed(1)} mm${cell.probability===null?'':` · ${Math.round(cell.probability)}%`}</em></div>`).join('')}</div>`;
            }
            function ensembleForecastStrip(ensemble) {
                const rows=(ensemble?.rows||[]).filter((_,index)=>index%2===0).slice(0,6);if(!rows.length)return'';const peak=Math.max(.05,...rows.map(row=>Math.max(0,Number(row.precipitation||0))));
                return `<div class="model-hour-strip ensemble-hour-strip">${rows.map(row=>{const rain=Math.max(0,Number(row.precipitation||0));return `<div class="model-hour-point" style="--rain:${Math.max(.04,rain/peak).toFixed(3)}"><small>${escapeHTML(formatClock(row.time))}</small><strong>${escapeHTML(temperature(Number(row.temperature||0)))}</strong><span><i></i></span><em>${rain.toFixed(1)} mm</em></div>`;}).join('')}</div>`;
            }
            function renderModelComparison() {
                const root = $('#model-comparison');
                const status = $('#model-comparison-status');
                if (!root || !status)
                    return;
                const models = state.intelligence.models || [];
                status.textContent = models.length >= 5 ? t("intelligence.rendermodelcomparison.3_models_updated") : models.length ? t("intelligence.rendermodelcomparison.value_models_available", { count: models.length }) : t("intelligence.confidencefrommodels.waiting_models");
                if (!models.length) {
                    root.innerHTML = `<div class="model-empty"><svg><use href="#i-spark"/></svg><strong>${escapeHTML(t("intelligence.rendermodelcomparison.preparing_comparison"))}</strong><p>${escapeHTML(t("intelligence.open_section_active_connection_compare_models"))}</p></div>`;
                    return;
                }
                state.intelligence.ensemble = buildAutoCalibratedEnsemble();
                const ensemble = state.intelligence.ensemble;
                const ensembleHtml = ensemble ? `
                <article class="model-card ensemble-model-card">
                  <div class="model-card-head"><span class="model-mark"><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('ensemble.card.title'))}</strong><small>${escapeHTML(ensemble.calibrated ? t('ensemble.card.calibrated') : t('ensemble.card.learning'))}</small></div><span class="model-condition">${escapeHTML(ensemble.calibrated ? t('ensemble.status.adaptive') : t('ensemble.status.equal'))}</span></div>
                  <div class="ensemble-weight-copy">${escapeHTML(ensembleWeightLabel(ensemble.weights.overall) || t('ensemble.weights.equal'))}</div>
                  <div class="model-sparkline model-readable-chart">${ensembleForecastStrip(ensemble)}</div>
                  <div class="model-metrics">
                    <div><small>${escapeHTML(t("history.plot.maximum_temperature"))}</small><strong>${escapeHTML(temperature(ensemble.temperatureMax))}</strong></div>
                    <div><small>${escapeHTML(t("intelligence.rendermodelcomparison.24h_rain"))}</small><strong>${ensemble.precipitationTotal.toFixed(1)} mm</strong></div>
                    <div><small>${escapeHTML(t("intelligence.rendermodelcomparison.maximum_wind"))}</small><strong>${Math.round(ensemble.windMax)} km/h</strong></div>
                  </div>
                  <div class="model-rain-time"><svg><use href="#i-shield"/></svg><span>${escapeHTML(ensemble.firstRain ? t('ensemble.rain.first', { time: formatClock(ensemble.firstRain) }) : t('ensemble.rain.none'))}</span></div>
                </article>` : '';
                root.innerHTML = ensembleHtml + models.map(model => `
                <article class="model-card model-${escapeHTML(model.id)}">
                  <div class="model-card-head"><span class="model-mark">${escapeHTML(model.label.slice(0, 2))}</span><div><strong>${escapeHTML(model.label)}</strong><small>${escapeHTML(t(model.description))}</small></div><span class="model-condition">${escapeHTML(weatherMeta(model.code, 1).label)}</span></div>
                  <div class="model-sparkline model-readable-chart">${modelForecastStrip(model)}</div>
                  <div class="model-metrics">
                    <div><small>${escapeHTML(t("history.plot.maximum_temperature"))}</small><strong>${escapeHTML(temperature(model.temperatureMax))}</strong></div>
                    <div><small>${escapeHTML(t("intelligence.rendermodelcomparison.24h_rain"))}</small><strong>${model.precipitationTotal.toFixed(1)} mm</strong></div>
                    <div><small>${escapeHTML(t("intelligence.rendermodelcomparison.maximum_wind"))}</small><strong>${Math.round(model.windMax)} km/h</strong></div>
                  </div>
                  <div class="model-rain-time"><svg><use href="#i-umbrella"/></svg><span>${escapeHTML(model.firstRain ? t("intelligence.first_possible_rain_at_value", { time: formatClock(model.firstRain) }) : t("intelligence.no_relevant_rain_next_24_hours"))}</span></div>
                </article>`).join('');
            }
            function buildForecastChanges() {
                const current = state.intelligence.currentSnapshot || createIntelligenceSnapshot();
                const previous = state.intelligence.previousSnapshot;
                if (!current || !previous || previous.locationKey !== current.locationKey) {
                    return [{ icon: 'i-refresh', title: t("intelligence.buildforecastchanges.first_analysis_available"), copy: t("intelligence.future_updates_will_compared_automatically_explain_what_changes") }];
                }
                const changes = [];
                const rainDelta = current.rain24 - previous.rain24;
                const tempDelta = current.tempMax24 - previous.tempMax24;
                const windDelta = current.windMax24 - previous.windMax24;
                if (previous.firstRain && current.firstRain) {
                    const minutes = Math.round((new Date(current.firstRain) - new Date(previous.firstRain)) / 60000);
                    if (Math.abs(minutes) >= 20) {
                        const pressureDelta = Number(current.pressure || 0) - Number(previous.pressure || 0);
                        const windShift = Math.abs(((Number(current.windDirection || 0) - Number(previous.windDirection || 0) + 540) % 360) - 180);
                        let reason = t('change.reason.model_update');
                        if (minutes < 0 && pressureDelta < -1.5) reason = t('change.reason.pressure_falling');
                        else if (minutes > 0 && pressureDelta > 1.5) reason = t('change.reason.pressure_rising');
                        else if (windShift >= 45) reason = t('change.reason.wind_shift');
                        changes.push({
                            icon: 'i-clock',
                            title: minutes < 0 ? t("intelligence.buildforecastchanges.rain_brought_forward") : t("intelligence.buildforecastchanges.rain_delayed"),
                            copy: t('change.timing.explained', { minutes: Math.abs(minutes), direction: minutes < 0 ? t('change.direction.earlier') : t('change.direction.later'), reason })
                        });
                    }
                }
                else if (!previous.firstRain && current.firstRain) {
                    changes.push({ icon: 'i-umbrella', title: t("intelligence.buildforecastchanges.new_chance_rain"), copy: t("intelligence.rain_now_expected_from_value", { time: formatClock(current.firstRain) }) });
                }
                else if (previous.firstRain && !current.firstRain) {
                    changes.push({ icon: 'i-sun', title: t("intelligence.rain_no_longer_expected"), copy: t("intelligence.rain_has_moved_outside_next_24_hour_window") });
                }
                if (Math.abs(rainDelta) >= 0.5)
                    changes.push({ icon: 'i-droplet', title: rainDelta > 0 ? t("intelligence.buildforecastchanges.rain_total_increasing") : t("intelligence.buildforecastchanges.rain_total_decreasing"), copy: rainDelta > 0 ? t("intelligence.about_value_mm_more_now_expected", { value: Math.abs(rainDelta).toFixed(1) }) : t("intelligence.about_value_mm_less_now_expected", { value: Math.abs(rainDelta).toFixed(1) }) });
                if (Math.abs(tempDelta) >= 1)
                    changes.push({ icon: 'i-sun', title: tempDelta > 0 ? t("intelligence.buildforecastchanges.high_temperature_rising") : t("intelligence.buildforecastchanges.high_temperature_falling"), copy: tempDelta > 0 ? t("intelligence.maximum_temperature_increased_by_about_value", { value: Math.abs(tempDelta).toFixed(1) }) : t("intelligence.maximum_temperature_decreased_by_about_value", { value: Math.abs(tempDelta).toFixed(1) }) });
                if (Math.abs(windDelta) >= 8)
                    changes.push({ icon: 'i-wind', title: windDelta > 0 ? t("intelligence.buildforecastchanges.stronger_wind") : t("intelligence.buildforecastchanges.wind_easing"), copy: windDelta > 0 ? t("intelligence.maximum_gusts_increased_by_about_value_km_h", { value: Math.round(Math.abs(windDelta)) }) : t("intelligence.maximum_gusts_decreased_by_about_value_km_h", { value: Math.round(Math.abs(windDelta)) }) });
                if (Number.isFinite(Number(current.modelRainSpreadMinutes)) && Number.isFinite(Number(previous.modelRainSpreadMinutes)) && Number(current.modelCount || 0) >= 2) {
                    const spreadDelta = Number(current.modelRainSpreadMinutes) - Number(previous.modelRainSpreadMinutes);
                    if (spreadDelta <= -20)
                        changes.push({ icon: 'i-shield', title: t('change.models.converging.title'), copy: t('change.models.converging.copy', { spread: Math.max(0, Number(current.modelRainSpreadMinutes)) }) });
                    else if (spreadDelta >= 30)
                        changes.push({ icon: 'i-alert', title: t('change.models.diverging.title'), copy: t('change.models.diverging.copy', { spread: Number(current.modelRainSpreadMinutes) }) });
                }
                return changes.length ? changes.slice(0, 4) : [{ icon: 'i-shield', title: t("intelligence.buildforecastchanges.stable_forecast"), copy: t("intelligence.no_important_change_since_previous_update") }];
            }
            function renderIntelligenceDecisions(nowcast, confidence) {
                const root = $('#intelligence-decisions');
                if (!root || !state.weather)
                    return;
                const hourly = state.weather.hourly;
                const start = currentHourlyIndex(state.weather);
                const next12Rain = Math.max(0, ...numericValues((hourly.precipitation_probability || []).slice(start, start + 12)));
                const next12Wind = Math.max(0, ...numericValues((hourly.wind_gusts_10m || []).slice(start, start + 12)));
                const next12Uv = Math.max(0, ...numericValues((hourly.uv_index || []).slice(start, start + 12)));
                const decisions = [
                    { icon: 'i-umbrella', title: t("intelligence.renderintelligencedecisions.umbrella"), value: nowcast.firstWet >= 0 || next12Rain >= 55 ? t("intelligence.renderintelligencedecisions.recommended.variant_2") : t("intelligence.renderintelligencedecisions.not_needed"), note: nowcast.firstWet >= 0 ? t("intelligence.rain_possible_within_2_hours") : t("intelligence.renderintelligencedecisions.maximum_probability_value", { value: Math.round(next12Rain) }) },
                    { icon: 'i-wind', title: t("intelligence.renderintelligencedecisions.outdoor_activities"), value: next12Wind >= 55 ? t("intelligence.renderintelligencedecisions.use_caution") : t("intelligence.renderintelligencedecisions.favourable_conditions"), note: t("intelligence.gusts_up_value_km_h", { value: Math.round(next12Wind) }) },
                    { icon: 'i-sun', title: t("intelligence.renderintelligencedecisions.sun_protection"), value: next12Uv >= 6 ? t("intelligence.renderintelligencedecisions.recommended") : t("intelligence.renderintelligencedecisions.limited_risk"), note: t("intelligence.maximum_uv_index_value", { value: next12Uv.toFixed(1) }) },
                    { icon: 'i-shield', title: t("intelligence.renderintelligencedecisions.confidence"), value: `${confidence.score}/100`, note: confidence.label }
                ];
                root.innerHTML = decisions.map(item => `<div class="decision-row"><span><svg><use href="#${item.icon}"/></svg></span><div><strong>${escapeHTML(item.title)}</strong><small>${escapeHTML(item.note)}</small></div><b>${escapeHTML(item.value)}</b></div>`).join('');
            }
            function renderIntelligence() {
                const page = $('#page-intelligence');
                if (!page || !state.weather)
                    return;
                const nowcast = extractNowcast();
                const message = nowcastMessage(nowcast);
                const messageRoot = $('#nowcast-message');
                if (messageRoot)
                    messageRoot.innerHTML = `<span><svg><use href="#${nowcast.firstWet >= 0 ? 'i-umbrella' : 'i-shield'}"/></svg></span><div><strong>${escapeHTML(message.title)}</strong><p>${escapeHTML(message.copy)}</p></div>`;
                $('#nowcast-start').textContent = nowcast.firstWet >= 0 ? (nowcast.rainingNow ? t("intelligence.renderintelligence.progress") : formatClock(nowcast.start)) : t("intelligence.renderintelligence.not_expected");
                $('#nowcast-end').textContent = nowcast.firstWet < 0 ? '--' : (nowcast.end ? formatClock(nowcast.end) : t("intelligence.renderintelligence.beyond_2_hours"));
                $('#nowcast-peak').textContent = nowcast.intensity;
                $('#nowcast-total').textContent = `${nowcast.total.toFixed(1)} mm`;
                const timeline = $('#nowcast-timeline');
                if (timeline)
                    timeline.innerHTML = nowcast.points.map((point, index) => {
                        const level = clamp(point.precipitation / 2, 0, 1);
                        const exact = point.exactQuarter === true;
                        return `<div class="nowcast-point${exact ? ' is-quarter' : ''}" style="--rain-level:${level.toFixed(3)}"><span><i></i></span><strong>${escapeHTML(formatClock(point.time))}</strong><small>${point.precipitation.toFixed(1)} mm</small></div>`;
                    }).join('');
                const countdownNode = $('#nowcast-pro-countdown');
                const reliabilityNode = $('#nowcast-pro-reliability');
                if (countdownNode) {
                    if (nowcast.firstWet < 0) countdownNode.textContent = t('nowcast.pro.no_rain_120');
                    else if (nowcast.rainingNow) countdownNode.textContent = t('nowcast.pro.raining_now');
                    else countdownNode.textContent = t('nowcast.pro.minutes', { minutes: Math.max(0, Math.round((new Date(nowcast.start).getTime() - Date.now()) / 60000)) });
                }
                if (reliabilityNode) reliabilityNode.textContent = `${nowcastProReliability(nowcast)}%`;
                const confidence = confidenceFromModels();
                const ring = $('#confidence-ring');
                if (ring)
                    ring.style.setProperty('--confidence', String(confidence.score));
                $('#confidence-score').textContent = String(confidence.score);
                $('#confidence-label').textContent = confidence.label;
                $('#confidence-badge').textContent = confidence.score >= 82 ? t("intelligence.confidencefrommodels.very_high") : confidence.score >= 66 ? t("intelligence.confidencefrommodels.good") : t("intelligence.confidencefrommodels.variable");
                $('#confidence-models').textContent = t("intelligence.renderintelligence.value_3", { count: state.intelligence.models.length });
                $('#confidence-agreement').textContent = confidence.agreement;
                $('#confidence-updated').textContent = state.intelligence.fetchedAt ? formatClock(new Date(state.intelligence.fetchedAt)) : '--:--';
                $('#confidence-description').textContent = confidence.score >= 82
                    ? t("intelligence.models_agree_next_24_hours_so_forecast_stable")
                    : confidence.score >= 66
                        ? t("intelligence.forecasts_broadly_aligned_limited_differences")
                        : t("intelligence.models_diverge_check_updates_more_frequently");
                const changes = buildForecastChanges();
                const changeRoot = $('#forecast-change-list');
                if (changeRoot)
                    changeRoot.innerHTML = changes.map(item => `<article><span><svg><use href="#${item.icon}"/></svg></span><div><strong>${escapeHTML(item.title)}</strong><p>${escapeHTML(item.copy)}</p></div></article>`).join('');
                renderIntelligenceDecisions(nowcast, confidence);
                renderModelComparison();
                renderVerifiedModelAccuracy(state.intelligence.accuracy || loadJSON(STORAGE.modelWeights, null) || {});
                loadPublicLocalAccuracy().catch(() => {});
                renderPersonalImpact();
                renderStoredBriefing();
                renderProactiveInsight();
                loadPersonalWeatherPreferences().catch(() => {});
                if (!isGuestSession() && getPersonalWeatherPrefs().proactiveEnabled) {
                    setTimeout(() => maybeGenerateProactiveInsight({ manual: false }).catch(() => {}), 900);
                }
                if (!isGuestSession() && !accuracyBackgroundScheduled) {
                    accuracyBackgroundScheduled = true;
                    const runAccuracy = () => {
                        accuracyBackgroundScheduled = false;
                        syncVerifiedModelAccuracy().catch(() => {});
                    };
                    if (typeof window.requestIdleCallback === 'function')
                        window.requestIdleCallback(runAccuracy, { timeout: 1600 });
                    else
                        setTimeout(runAccuracy, 450);
                }
                translateDOM(page);
            }

            return Object.freeze({ intelligenceLocationKey, numericValues, average, standardDeviation, summarizeModelForecast, summarizeServerIntelligenceModel, createPreviewIntelligenceModels, loadIntelligence, createIntelligenceSnapshot, updateIntelligenceSnapshot, extractNowcast, nowcastProReliability, confidenceFromModels, nowcastMessage, modelForecastStrip, ensembleForecastStrip, renderModelComparison, buildForecastChanges, renderIntelligenceDecisions, renderIntelligence });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
