export function createForecastFusion(context) {
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
    let precipitationMm = baseMm;
    let precipitationSource = 'base';

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
        const robustModelMm = modelMedian >= 0.05 ? modelMedian : (amountPct >= 60 ? modelWetMean : modelMean);
        const strongModelDry = amountPct <= 40 && modelMean < 0.1 && modelMax < 0.2;

        if (strongModelRain && baseMm < 0.05 && robustModelMm >= 0.05 && !(minutely.available && minutely.dry)) {
            precipitationMm = robustModelMm;
            precipitationSource = 'models';
        }

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

    return Object.freeze({
        currentHourlyIndex, isPrecipitationCode, isLiquidPrecipitationCode, cloudFallbackCode, forecastFusionLocationKey, forecastFusionIsAuthoritative, fusionRowForHour, minutelyEvidenceForHour, resolveFusedHourlyCondition, currentResolvedCondition, resolvedConditionEvidenceLabel, loadForecastFusion, weatherSummary, localTrustBriefReliability, trustBriefSevereSignal, trustBriefForecastSignal, renderTrustBrief
    });
}
