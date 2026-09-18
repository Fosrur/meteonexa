export const serviceNames = Object.freeze(['forecastHistory']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void deps;
    provided.forecastHistory = Object.freeze({
        create(context) {
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

            function currentHourlyIndex(data = state.weather) {
                if (!data?.hourly?.time?.length)
                    return 0;
                return localSeriesIndex(data.hourly.time, { currentTime: data.current?.time || '', weatherData: data });
            }
            
            const PRECIPITATION_CODES = new Set([51,53,55,56,57,61,63,65,66,67,71,73,75,77,80,81,82,85,86,95,96,99]);
            const LIQUID_PRECIPITATION_CODES = new Set([51,53,55,56,57,61,63,65,66,67,80,81,82,95,96,99]);
            const SNOW_CODES = new Set([71,73,75,77,85,86]);
            const STORM_CODES = new Set([95,96,99]);
            function isPrecipitationCode(code) { return PRECIPITATION_CODES.has(Number(code)); }
            function isLiquidPrecipitationCode(code) { return LIQUID_PRECIPITATION_CODES.has(Number(code)); }
            function cloudFallbackCode(cloudCover = 0) {
                const cloud = clamp(Number(cloudCover || 0), 0, 100);
                if (cloud < 20) return 0;
                if (cloud < 50) return 1;
                if (cloud < 80) return 2;
                return 3;
            }
            function forecastFusionLocationKey(locationData = state.location) {
                return `${Number(locationData?.latitude || 0).toFixed(3)}:${Number(locationData?.longitude || 0).toFixed(3)}`;
            }
            function forecastFusionIsAuthoritative(data = state.weather) {
                const fusion = state.forecastFusion;
                if (!data || data !== state.weather || data.source !== 'live') return false;
                if (!fusion || fusion.degraded || fusion.freshModelsAvailable < 3 || !Array.isArray(fusion.hourly) || !fusion.hourly.length) return false;
                if (fusion.locationKey !== forecastFusionLocationKey()) return false;
                return Date.now() - Number(fusion.fetchedAt || 0) <= 20 * 60 * 1000;
            }
            function fusionRowForHour(data, index) {
                if (!forecastFusionIsAuthoritative(data)) return null;
                const localKey = String(data?.hourly?.time?.[index] || '').slice(0, 13);
                if (!localKey) return null;
                return state.forecastFusion.hourly.find(row => {
                    if (!row?.time) return false;
                    const date = new Date(row.time);
                    return !Number.isNaN(date.getTime()) && localDateHourKey(state.location, data, date) === localKey;
                }) || null;
            }
            function minutelyEvidenceForHour(data, index) {
                if (!data || data !== state.weather || data.source !== 'live') return { available: false };
                if (Date.now() - Number(data.fetchedAt || 0) > 15 * 60 * 1000) return { available: false };
                const currentIndex = currentHourlyIndex(data);
                if (index < currentIndex || index > currentIndex + 2) return { available: false };
                const minutely = data.minutely_15;
                if (!minutely?.time?.length) return { available: false };
                const hourKey = String(data.hourly?.time?.[index] || '').slice(0, 13);
                if (!hourKey) return { available: false };
                let precipitation = 0;
                let points = 0;
                let wetPoints = 0;
                let stormPoints = 0;
                let snowPoints = 0;
                minutely.time.forEach((time, minuteIndex) => {
                    if (String(time).slice(0, 13) !== hourKey) return;
                    const mm = Math.max(0, Number(minutely.precipitation?.[minuteIndex] || 0));
                    const code = Number(minutely.weather_code?.[minuteIndex] || 0);
                    precipitation += mm;
                    points += 1;
                    // Amount is the primary 15-minute rain evidence. A WMO rain code
                    // with 0.0 mm must not by itself keep the UI in a raining state.
                    if (mm >= 0.02) wetPoints += 1;
                    if (STORM_CODES.has(code)) stormPoints += 1;
                    if (SNOW_CODES.has(code)) snowPoints += 1;
                });
                return points ? {
                    available: true,
                    precipitation: Math.round(precipitation * 100) / 100,
                    points, wetPoints, stormPoints, snowPoints,
                    wet: precipitation >= 0.05 || wetPoints > 0 || stormPoints > 0 || snowPoints > 0,
                    dry: precipitation < 0.05 && wetPoints === 0 && stormPoints === 0 && snowPoints === 0
                } : { available: false };
            }
            /**
             * Resolve the condition shown by Panoramica using explicit, conservative
             * evidence. The base Best Match forecast remains the fallback. Near-term live
             * 15-minute evidence has priority; fresh >=3-model consensus may refine later
             * hours. Degraded/stale model fusion is never allowed to override the base.
             * Severe thunderstorm/snow codes are not suppressed by a dry heuristic.
             */
            function resolveFusedHourlyCondition(data, index) {
                const hourly = data?.hourly;
                const rawCode = Number(hourly?.weather_code?.[index] ?? 0);
                const isDay = Number(hourly?.is_day?.[index] ?? data?.current?.is_day ?? 1);
                const probability = clamp(Number(hourly?.precipitation_probability?.[index] || 0), 0, 100);
                const baseMm = Math.max(0, Number(hourly?.precipitation?.[index] || 0));
                const cloudCover = Number(hourly?.cloud_cover?.[index] ?? data?.current?.cloud_cover ?? 0);
                const minutely = minutelyEvidenceForHour(data, index);
                const model = fusionRowForHour(data, index);
                let code = rawCode;
                let reason = 'base';
                let confidence = 'base';
                // Keep the displayed precipitation amount aligned with the same evidence
                // that resolves the condition. Previously the condition could be
                // promoted to rain by 3/5 fresh models while the card still printed the
                // Best Match 0.0 mm value, creating an internal contradiction.
                let precipitationMm = baseMm;
                let precipitationSource = 'base';
            
                // Safety first: never hide a severe thunderstorm/snow signal solely because
                // a heuristic says the hour may be dry.
                const severeBase = STORM_CODES.has(rawCode) || SNOW_CODES.has(rawCode);
            
                if (minutely.available && minutely.wet) {
                    if (minutely.stormPoints > 0) code = 95;
                    else if (minutely.snowPoints > 0) code = 71;
                    else if (!STORM_CODES.has(rawCode) && !SNOW_CODES.has(rawCode)) code = isLiquidPrecipitationCode(rawCode) ? rawCode : 61;
                    precipitationMm = Math.max(baseMm, Math.max(0, Number(minutely.precipitation || 0)));
                    precipitationSource = 'nowcast';
                    reason = 'nowcast-wet'; confidence = 'high';
                }
            
                if (model && Number(model.available || 0) >= 3) {
                    const available = Math.max(1, Number(model.available || 0));
                    const rainPct = 100 * Number(model.rainVotes || 0) / available;
                    const amountPct = 100 * Number(model.precipitationVotes || 0) / available;
                    const stormPct = 100 * Number(model.stormVotes || 0) / available;
                    const snowPct = 100 * Number(model.snowVotes || 0) / available;
                    const modelMean = Math.max(0, Number(model.precipitationMean || 0));
                    const modelMedian = Math.max(0, Number(model.precipitationMedian || 0));
                    const modelWetMean = Math.max(0, Number(model.precipitationWetMean || 0));
                    const modelMax = Math.max(0, Number(model.precipitationMax || 0));
                    const strongModelRain = amountPct >= 60 || (rainPct >= 60 && (modelMean >= 0.1 || modelMax >= 0.2));
                    // Median is preferred to the arithmetic mean because it is resistant to
                    // one excessively wet model. precipitationVotes uses >=0.1 mm, so with
                    // 3/5 wet votes the median is normally itself measurable.
                    const robustModelMm = modelMedian >= 0.05 ? modelMedian : (amountPct >= 60 ? modelWetMean : modelMean);
                    // Measurable amount has priority over the WMO icon code for deciding
                    // whether rain has ended. The probability remains visible separately.
                    const strongModelDry = amountPct <= 40 && modelMean < 0.1 && modelMax < 0.2;
            
                    // When fresh model consensus is materially wetter than a 0.0 mm Best
                    // Match hour, expose the consensus amount as the effective amount. This
                    // is not a copy of any third-party forecast: it is the median/robust
                    // amount from MeteoNexa's five-model ensemble.
                    if (strongModelRain && baseMm < 0.05 && robustModelMm >= 0.05 && !(minutely.available && minutely.dry)) {
                        precipitationMm = robustModelMm;
                        precipitationSource = 'models';
                    }
            
                    // A very recent dry 15-minute signal + fresh multi-model dry agreement
                    // may suppress a weak raw rain code. This is the key guard against a
                    // Best Match WMO rain code lingering for hours after the precipitation
                    // amount/nowcast/model ensemble has become dry.
                    if (!severeBase && isLiquidPrecipitationCode(rawCode)
                        && minutely.available && minutely.dry && strongModelDry && baseMm < 0.15) {
                        code = cloudFallbackCode(cloudCover);
                        precipitationMm = Math.min(baseMm, Math.max(0, Number(minutely.precipitation || 0)));
                        precipitationSource = 'nowcast-models';
                        reason = 'nowcast-model-dry'; confidence = 'high';
                    } else if (!severeBase && isLiquidPrecipitationCode(rawCode)
                        && !minutely.available && strongModelDry && baseMm < 0.05) {
                        code = cloudFallbackCode(cloudCover);
                        precipitationMm = Math.min(baseMm, modelMedian);
                        precipitationSource = 'models';
                        reason = 'model-dry'; confidence = 'medium';
                    } else if (!minutely.available && strongModelRain && !isPrecipitationCode(rawCode)) {
                        if (stormPct >= 60) code = 95;
                        else if (snowPct >= 60) code = 71;
                        else code = 61;
                        reason = 'model-wet'; confidence = 'medium';
                    }
                }
            
                // Probability is a risk estimate, not proof of ongoing rain. If the base
                // provider itself says there is no measurable liquid precipitation for this
                // hour, a weak lingering WMO rain code must not force a rain icon. Fresh
                // multi-model evidence with >=3 measurable-rain votes still wins.
                if (!severeBase && isLiquidPrecipitationCode(code) && baseMm < 0.02
                    && !(model && Number(model.precipitationVotes || 0) >= 3)) {
                    code = cloudFallbackCode(cloudCover);
                    if (reason === 'base') { reason = 'base-dry-amount'; confidence = 'medium'; }
                }
            
                const meta = weatherMeta(code, isDay);
                const dry = !isPrecipitationCode(code) && isLiquidPrecipitationCode(rawCode) && precipitationMm < 0.05;
                return {
                    code, rawCode, isDay, meta, reason, confidence, changed: code !== rawCode,
                    minutely, model, dry,
                    precipitationMm: Math.max(0, precipitationMm),
                    basePrecipitationMm: baseMm,
                    precipitationSource,
                    probability
                };
            }
            function currentResolvedCondition(data = state.weather) {
                if (!data?.hourly?.time?.length) {
                    const code = Number(data?.current?.weather_code || 0);
                    const isDay = Number(data?.current?.is_day ?? 1);
                    return { code, rawCode: code, isDay, meta: weatherMeta(code, isDay), reason: 'base', changed: false };
                }
                return resolveFusedHourlyCondition(data, currentHourlyIndex(data));
            }
            function resolvedConditionEvidenceLabel(resolved) {
                if (!resolved) return t('weather.reliability.evidence.base');
                if (resolved.reason === 'nowcast-model-dry') return t('weather.reliability.evidence.nowcast_models');
                if (resolved.reason === 'nowcast-wet') return resolved.model ? t('weather.reliability.evidence.nowcast_models') : t('weather.reliability.evidence.nowcast');
                if (resolved.reason === 'model-dry' || resolved.reason === 'model-wet') return t('weather.reliability.evidence.models');
                if (resolved.model) return t('weather.reliability.evidence.verified_models');
                return t('weather.reliability.evidence.base');
            }
            async function loadForecastFusion({ force = false } = {}) {
                if (PREVIEW_MODE || !navigator.onLine || !hasUsableLocation()) return null;
                const key = forecastFusionLocationKey();
                const fusion = state.forecastFusion;
                const age = Date.now() - Number(fusion?.fetchedAt || 0);
                if (!force && fusion?.locationKey === key && age < 12 * 60 * 1000 && Array.isArray(fusion.hourly) && fusion.hourly.length) return fusion;
                if (fusion?.request) return fusion.request;
                const params = new URLSearchParams({ lat: String(state.location.latitude), lon: String(state.location.longitude) });
                const task = (async () => {
                    try {
                        // Deliberately credentialless: this public reliability request
                        // carries neither session/client cookies nor device-proof headers.
                        // The endpoint is same-origin/read-only and rate-limited by IP.
                        const controller = new AbortController();
                        const timer = setTimeout(() => controller.abort(), 11000);
                        let response;
                        try {
                            response = await fetch(`api/weather/fusion.php?${params}`, {
                                signal: controller.signal,
                                headers: { Accept: 'application/json' },
                                credentials: 'omit',
                                cache: 'no-store'
                            });
                        } finally { clearTimeout(timer); }
                        if (!response?.ok) throw new Error(`FUSION_HTTP_${response?.status || 0}`);
                        const result = await response.json();
                        if (!result?.ok || !result?.fusion) return null;
                        // Ignore a response that raced with a location switch.
                        if (key !== forecastFusionLocationKey()) return null;
                        state.forecastFusion = {
                            ...result.fusion,
                            hourly: Array.isArray(result.fusion.hourly) ? result.fusion.hourly : [],
                            fetchedAt: Date.now(), locationKey: key, request: null
                        };
                        if (state.weather?.source === 'live') renderAll();
                        return state.forecastFusion;
                    } catch (error) {
                        console.warn('HOME_FORECAST_FUSION_FAILED', error);
                        if (key === forecastFusionLocationKey()) {
                            state.forecastFusion = { hourly: [], request: null, fetchedAt: Date.now(), locationKey: key, modelsAvailable: 0, freshModelsAvailable: 0, modelsExpected: 0, degraded: true, mode: 'unavailable' };
                            if (state.weather?.source === 'live') renderHeader();
                        }
                        return null;
                    }
                })();
                state.forecastFusion.request = task;
                try { return await task; }
                finally { if (state.forecastFusion.request === task) state.forecastFusion.request = null; }
            }
            function weatherSummary(data) {
                const current = data.current;
                const index = currentHourlyIndex(data);
                const probability = Number(data.hourly.precipitation_probability[index] || 0);
                const wind = Number(current.wind_speed_10m || 0);
                const resolved = resolveFusedHourlyCondition(data, index);
                const meta = resolved.meta;
                if (STORM_CODES.has(Number(resolved.code)))
                    return "" + meteonexaText("history.thunderstorms_possible_check_alerts_limit_outdoor_activities");
                // A probability describes risk, not observed/expected precipitation. Keep it
                // visible in the summary without forcing the condition label itself to rain.
                if (probability >= 65)
                    return meteonexaText("history.high_chance_rain_next_few_hours_value_take", { value: Math.round(probability) });
                if (wind >= 40)
                    return meteonexaText("history.value_conditions_sustained_winds_up_value_km_h", { condition: meta.label.toLowerCase(), value: Math.round(wind) });
                if (Number(current.cloud_cover) < 25)
                    return "" + meteonexaText("history.mostly_clear_skies_favorable_conditions_outdoor_activities");
                return meteonexaText("history.value_feels_like_value_wind_from_value", { condition: meta.label, temperature: temperature(current.apparent_temperature), direction: windDirection(current.wind_direction_10m) });
            }
            function localTrustBriefReliability() {
                if (isGuestSession()) return { verified: false, score: null, samples: 0, label: t('trust.brief.reliability.guest'), detail: t('trust.brief.reliability.guest_detail') };
                const accuracy = state.intelligence.accuracy || loadJSON(STORAGE.modelWeights, null) || {};
                const rows = Array.isArray(accuracy.rows) ? accuracy.rows : [];
                const minimum = Math.max(1, Number(accuracy.minimumSamples || 6));
                const samples = rows.reduce((max, row) => Math.max(max, Number(row.samples || 0)), 0);
                if (!rows.length || samples < minimum) {
                    return {
                        verified: false,
                        score: null,
                        samples,
                        label: t('trust.brief.reliability.learning'),
                        detail: t('trust.brief.reliability.progress', { count: samples, total: minimum })
                    };
                }
                const weighted = rows.reduce((acc, row) => {
                    const n = Math.max(0, Number(row.samples || 0));
                    const score = clamp(Number(row.score || 0), 0, 100);
                    acc.sum += score * n;
                    acc.weight += n;
                    return acc;
                }, { sum: 0, weight: 0 });
                const score = weighted.weight > 0 ? Math.round(weighted.sum / weighted.weight) : null;
                return {
                    verified: Number.isFinite(score),
                    score,
                    samples,
                    label: Number.isFinite(score) ? t('trust.brief.reliability.verified', { value: score }) : t('trust.brief.reliability.learning'),
                    detail: t('trust.brief.reliability.samples', { count: samples })
                };
            }
            function trustBriefSevereSignal() {
                const monitor = state.severeWeather;
                const age = monitor?.fetchedAt ? Date.now() - Number(monitor.fetchedAt) : Infinity;
                const usable = monitor?.authoritative === true && monitor?.degraded !== true
                    && monitor?.locationKey === severeWeatherLocationKey() && age <= 8 * 60 * 1000;
                if (!usable || !Array.isArray(monitor.events) || !monitor.events.length) return null;
                const events = [...monitor.events].sort((a,b) => severeWeatherEventRank(b) - severeWeatherEventRank(a));
                const event = events[0] || {};
                const eta = optionalFiniteNumber(event.etaMinutes);
                const startsAt = String(event.startsAt || '');
                const when = Number.isFinite(eta) && eta >= 0 && eta <= 360
                    ? (eta <= 0 ? t('trust.brief.now') : t('trust.brief.in_minutes', { count: Math.round(eta) }))
                    : (startsAt ? formatOfficialAlertTime(startsAt) : t('trust.brief.soon'));
                const confidence = clamp(Number(event.confidence || 0), 0, 100);
                return {
                    kind: 'severe',
                    title: String(event.title || t('home.severe.fallback.title')),
                    copy: String(event.body || t('home.severe.fallback.copy')),
                    when,
                    confidence: confidence || null,
                    sources: Number(state.forecastFusion.freshModelsAvailable || 0),
                    available: Number(state.forecastFusion.modelsExpected || state.forecastFusion.modelsAvailable || 0),
                    agreement: confidence || null
                };
            }
            function trustBriefForecastSignal(data = state.weather) {
                if (!data?.hourly?.time?.length) return null;
                const start = currentHourlyIndex(data);
                const end = Math.min(data.hourly.time.length - 1, start + 12);
                for (let index = start; index <= end; index += 1) {
                    const resolved = resolveFusedHourlyCondition(data, index);
                    const measurable = Number(resolved.precipitationMm || 0) >= 0.05;
                    if (!isPrecipitationCode(resolved.code) && !measurable) continue;
                    const row = resolved.model || fusionRowForHour(data, index);
                    const available = Math.max(0, Number(row?.available || 0));
                    const votes = Math.max(0, Number(row?.precipitationVotes || row?.rainVotes || 0));
                    const agreement = available > 0 ? Math.round(100 * Math.min(votes, available) / available) : null;
                    return {
                        kind: 'forecast',
                        title: resolved.meta?.label || t('trust.brief.precipitation'),
                        copy: t('trust.brief.precipitation_copy', {
                            mm: Number(resolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
                            probability: Math.round(Number(resolved.probability || 0))
                        }),
                        when: index === start ? t('trust.brief.now') : formatClock(data.hourly.time[index]),
                        confidence: agreement,
                        sources: available || Number(state.forecastFusion.freshModelsAvailable || 0),
                        available: Number(state.forecastFusion.modelsExpected || state.forecastFusion.modelsAvailable || 0),
                        agreement
                    };
                }
                // No precipitation/severe signal: use a near-term dry/stable consensus row,
                // but never claim certainty if the reliability fusion is degraded.
                const probeIndex = Math.min(end, start + 3);
                const row = fusionRowForHour(data, probeIndex);
                const available = Math.max(0, Number(row?.available || 0));
                const wetVotes = Math.max(0, Number(row?.precipitationVotes || row?.rainVotes || 0));
                const dryVotes = available > 0 ? Math.max(0, available - Math.min(wetVotes, available)) : 0;
                const agreement = available > 0 ? Math.round(100 * dryVotes / available) : null;
                return {
                    kind: 'stable',
                    title: forecastFusionIsAuthoritative(data) ? t('trust.brief.no_strong_signal') : t('trust.brief.base_only'),
                    copy: forecastFusionIsAuthoritative(data) ? t('trust.brief.no_strong_signal_copy') : t('trust.brief.base_only_copy'),
                    when: t('trust.brief.next_hours'),
                    confidence: agreement,
                    sources: available || Number(state.forecastFusion.freshModelsAvailable || 0),
                    available: Number(state.forecastFusion.modelsExpected || state.forecastFusion.modelsAvailable || 0),
                    agreement
                };
            }
            function renderTrustBrief() {
                const root = $('#trust-brief-panel');
                if (!root || !state.weather) return;
                const signal = trustBriefSevereSignal() || trustBriefForecastSignal(state.weather);
                const reliability = localTrustBriefReliability();
                const eventNode = $('#trust-brief-event');
                const copyNode = $('#trust-brief-event-copy');
                const whenNode = $('#trust-brief-when');
                const sourcesNode = $('#trust-brief-sources');
                const agreementNode = $('#trust-brief-agreement');
                const reliabilityNode = $('#trust-brief-reliability');
                const samplesNode = $('#trust-brief-samples');
                const statusNode = $('#trust-brief-status');
                if (eventNode) eventNode.textContent = signal?.title || t('trust.brief.unavailable');
                if (copyNode) copyNode.textContent = signal?.copy || t('trust.brief.unavailable_copy');
                if (whenNode) whenNode.textContent = signal?.when || '--';
                const fresh = Math.max(0, Number(state.forecastFusion.freshModelsAvailable || signal?.sources || 0));
                const total = Math.max(1, Number(state.forecastFusion.modelsExpected || signal?.available || 1));
                if (sourcesNode) sourcesNode.textContent = t('trust.brief.models', { count: fresh, total });
                if (agreementNode) agreementNode.textContent = Number.isFinite(Number(signal?.agreement))
                    ? t('trust.brief.agreement', { value: Math.round(Number(signal.agreement)) })
                    : (state.forecastFusion.degraded ? t('trust.brief.degraded') : t('trust.brief.waiting'));
                if (reliabilityNode) reliabilityNode.textContent = reliability.label;
                if (samplesNode) samplesNode.textContent = reliability.detail;
                const score = Number(signal?.confidence);
                if (statusNode) {
                    statusNode.textContent = Number.isFinite(score) && score > 0 ? `${Math.round(score)}%` : (forecastFusionIsAuthoritative(state.weather) ? t('trust.brief.live') : t('trust.brief.base'));
                    statusNode.classList.toggle('good', Number.isFinite(score) && score >= 75);
                }
                root.dataset.signalKind = signal?.kind || 'unavailable';
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
                currentHourlyIndex, isPrecipitationCode, isLiquidPrecipitationCode, cloudFallbackCode, forecastFusionLocationKey, forecastFusionIsAuthoritative, fusionRowForHour, minutelyEvidenceForHour, resolveFusedHourlyCondition, currentResolvedCondition, resolvedConditionEvidenceLabel, loadForecastFusion, weatherSummary, localTrustBriefReliability, trustBriefSevereSignal, trustBriefForecastSignal, renderTrustBrief, historyDateValue, historyDateOffset, historyDateObject, historyLocationKey, ensureHistoryRange, setHistoryRange, historyRangeDays, validateHistoryRange, buildHistoricalWeatherURL, createPreviewHistory, loadHistory, historyAverage, historyMaximum, historyTotal, formatHistoryDay, renderHistory, drawHistoryChart
            });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
