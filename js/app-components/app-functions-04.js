'use strict';
async function syncVerifiedModelAccuracy({ force = false } = {}) {
    if (isGuestSession() || !isUiFeatureVisible('feature.model.accuracy') || !state.weather || !state.intelligence.models?.length) return;
    if (!force && Date.now() - accuracySyncAt < 10 * 60 * 1000) return;
    accuracySyncAt = Date.now();
    const deviceId = SERVICES.get('security')?.deviceId || '';
    const locationKey = intelligenceLocationKey();
    const current = state.weather.current || {};
    const observation = {
        time: current.time || new Date().toISOString(),
        temperature: Number(current.temperature_2m ?? 0),
        precipitation: Number(current.precipitation ?? current.rain ?? 0),
        windGust: Number(current.wind_gusts_10m ?? current.wind_speed_10m ?? 0)
    };
    const forecasts = state.intelligence.models.flatMap(modelVerificationForecasts);
    try {
        await apiRequest('api/accuracy/models.php', { deviceId, locationKey, observation, forecasts });
        const data = await apiRequest(`api/accuracy/models.php?deviceId=${encodeURIComponent(deviceId)}&locationKey=${encodeURIComponent(locationKey)}`);
        state.intelligence.accuracy = data;
        saveJSON(STORAGE.modelWeights, data);
        state.intelligence.ensemble = buildAutoCalibratedEnsemble();
        renderVerifiedModelAccuracy(data);
        renderModelComparison();
    }
    catch (error) {
        console.warn('MODEL_ACCURACY_SYNC_FAILED', error);
        const status = $('#accuracy-status');
        if (status) status.textContent = t('accuracy.status.unavailable');
    }
}
function renderPublicLocalAccuracy(data){
    const grid=$('#accuracy-public-grid'),status=$('#accuracy-public-status'),foot=$('#accuracy-public-foot');if(!grid||!status||!foot)return;
    const metrics=data?.metrics||{};if(data?.status!=='verified'){
        status.textContent=t('accuracy.public.learning');grid.innerHTML=`<div class="accuracy-public-learning"><strong>${escapeHTML(t('accuracy.public.learning_title'))}</strong><p>${escapeHTML(t('accuracy.public.learning_copy',{samples:Number(data?.minimumSamples||20)}))}</p></div>`;foot.textContent=t('accuracy.public.privacy');return;
    }
    status.textContent=t('accuracy.public.verified');const cards=[];
    if(metrics.temperature)cards.push([t('accuracy.metric.temperature'),`${Number(metrics.temperature.maeC||0).toFixed(1)} °C`,t('accuracy.metric.mae'),metrics.temperature.samples]);
    if(metrics.rain)cards.push([t('accuracy.metric.rain'),`${Number(metrics.rain.precisionPct||0).toFixed(0)}% / ${Number(metrics.rain.recallPct||0).toFixed(0)}%`,t('accuracy.metric.precision_recall'),metrics.rain.samples]);
    if(metrics.storm)cards.push([t('accuracy.metric.storm'),`${Number(metrics.storm.falseAlarmRatioPct||0).toFixed(0)}%`,t('accuracy.metric.false_alarm'),metrics.storm.samples]);
    if(metrics.radarEta)cards.push([t('accuracy.metric.radar'),`${Number(metrics.radarEta.maeMinutes||0).toFixed(1)} min`,t('accuracy.metric.within',{value:Number(metrics.radarEta.withinTolerancePct||0).toFixed(0)}),metrics.radarEta.samples]);
    grid.innerHTML=cards.map(([label,value,note,samples])=>`<article class="accuracy-public-card"><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong><span>${escapeHTML(note)}</span><em>${escapeHTML(t('accuracy.metric.samples',{count:Number(samples||0)}))}</em></article>`).join('');
    foot.textContent=t('accuracy.public.foot',{days:Number(data.windowDays||60),count:Number(data.verifiedChecks||0)});
}
async function loadPublicLocalAccuracy({force=false}={}){
    const key=intelligenceLocationKey();if(!force&&publicAccuracyCache.key===key&&Date.now()-publicAccuracyCache.at<10*60*1000&&publicAccuracyCache.data){renderPublicLocalAccuracy(publicAccuracyCache.data);return;}
    try{const response=await fetch(`api/accuracy/public.php?locationKey=${encodeURIComponent(key)}`,{method:'GET',headers:{Accept:'application/json'},cache:'no-store',credentials:'omit'});const data=await response.json().catch(()=>({}));if(!response.ok||data.ok===false)throw new Error(data.message||`HTTP_${response.status}`);publicAccuracyCache={key,at:Date.now(),data};renderPublicLocalAccuracy(data);}catch(error){const status=$('#accuracy-public-status'),grid=$('#accuracy-public-grid');if(status)status.textContent=t('accuracy.public.unavailable');if(grid)grid.innerHTML=`<div class="advanced-empty">${escapeHTML(t('accuracy.public.unavailable_copy'))}</div>`;}
}
function modelWeightSet(metric, horizonHours = 1) {
    const accuracy = state.intelligence.accuracy || loadJSON(STORAGE.modelWeights, null) || {};
    const buckets = accuracy.weightsByHorizon || {};
    const horizon = horizonHours <= 1 ? '1' : horizonHours <= 3 ? '3' : horizonHours <= 6 ? '6' : horizonHours <= 24 ? '24' : horizonHours <= 48 ? '48' : '72';
    const selected = buckets?.[horizon]?.[metric] || accuracy.weights?.[metric] || {};
    const available = (state.intelligence.models || []).map(model => model.id);
    const valid = Object.fromEntries(Object.entries(selected).filter(([id, value]) => available.includes(id) && Number(value) > 0));
    const sum = Object.values(valid).reduce((total, value) => total + Number(value || 0), 0);
    if (sum > 0) return Object.fromEntries(Object.entries(valid).map(([id, value]) => [id, Number(value) / sum]));
    if (!available.length) return {};
    return Object.fromEntries(available.map(id => [id, 1 / available.length]));
}
function weightedModelHourlyValue(metric, offset) {
    const models = state.intelligence.models || [];
    if (!models.length) return null;
    const key = metric === 'temperature' ? 'temperature_2m' : metric === 'rain' ? 'precipitation' : 'wind_gusts_10m';
    const weights = modelWeightSet(metric, offset + 1);
    let sum = 0, weightSum = 0;
    for (const model of models) {
        const index = Number(model.start || 0) + offset;
        const hourly = model.raw?.hourly || {};
        let value = Number(hourly[key]?.[index]);
        if (!Number.isFinite(value) && metric === 'wind') value = Number(hourly.wind_speed_10m?.[index]);
        if (!Number.isFinite(value)) continue;
        const weight = Number(weights[model.id] || 0);
        if (weight <= 0) continue;
        sum += value * weight;
        weightSum += weight;
    }
    return weightSum > 0 ? sum / weightSum : null;
}
function buildAutoCalibratedEnsemble() {
    const models = state.intelligence.models || [];
    if (!models.length) return null;
    const times = models[0]?.raw?.hourly?.time || [];
    const start = Number(models[0]?.start || 0);
    const rows = [];
    for (let offset = 0; offset < 12; offset += 1) {
        const time = times[start + offset];
        if (!time) break;
        rows.push({
            time,
            temperature: weightedModelHourlyValue('temperature', offset),
            precipitation: weightedModelHourlyValue('rain', offset),
            wind: weightedModelHourlyValue('wind', offset)
        });
    }
    if (!rows.length) return null;
    const accuracyRows = Array.isArray(state.intelligence.accuracy?.rows) ? state.intelligence.accuracy.rows : [];
    const sampleCount = accuracyRows.reduce((max, row) => Math.max(max, Number(row.samples || 0)), 0);
    const calibrated = sampleCount >= Number(state.intelligence.accuracy?.minimumSamples || 6);
    const rainIndex = rows.findIndex(row => Number(row.precipitation || 0) >= .1);
    const overallWeights = modelWeightSet('overall', 3);
    const dominant = Object.entries(overallWeights).sort((a,b) => b[1] - a[1])[0]?.[0] || '';
    return {
        rows,
        calibrated,
        sampleCount,
        dominantModel: dominant,
        temperatureMax: Math.max(...rows.map(row => Number(row.temperature ?? -99))),
        precipitationTotal: rows.reduce((sum,row) => sum + Math.max(0,Number(row.precipitation || 0)),0),
        windMax: Math.max(...rows.map(row => Number(row.wind || 0))),
        firstRain: rainIndex >= 0 ? rows[rainIndex].time : '',
        weights: {
            overall: modelWeightSet('overall', 3),
            temperature: modelWeightSet('temperature', 3),
            rain: modelWeightSet('rain', 3),
            wind: modelWeightSet('wind', 3)
        }
    };
}
function ensembleWeightLabel(weights = {}) {
    const rows = Object.entries(weights).sort((a,b) => Number(b[1]) - Number(a[1])).slice(0, 2);
    return rows.map(([id,value]) => `${modelDisplayName(id)} ${Math.round(Number(value) * 100)}%`).join(' · ');
}
function renderVerifiedModelAccuracy(data) {
    const root = $('#accuracy-model-list');
    const bestRoot = $('#accuracy-best');
    const status = $('#accuracy-status');
    if (!root || !bestRoot) return;
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    const minimum = Math.max(1, Number(data?.minimumSamples || 6));
    const maxSamples = rows.length ? Math.max(...rows.map(row => Number(row.samples || 0))) : 0;
    const calibrated = maxSamples >= minimum;
    const liveConfidence = confidenceFromModels();
    if (status) status.textContent = calibrated
        ? t('accuracy.status.verified')
        : t('accuracy.status.progress', { count: maxSamples, total: minimum, agreement: liveConfidence.score });
    const best = data?.best || {};
    const models = Array.isArray(state.intelligence.models) ? state.intelligence.models : [];
    if (calibrated) {
        const bestItems = [
            ['accuracy.best.overall', best.overall],
            ['accuracy.best.temperature', best.temperature],
            ['accuracy.best.rain', best.rain],
            ['accuracy.best.wind', best.wind]
        ];
        bestRoot.innerHTML = bestItems.map(([key, model]) => `<div><small>${escapeHTML(t(key))}</small><strong>${escapeHTML(model ? modelDisplayName(model) : t('accuracy.learning.short'))}</strong></div>`).join('');
    } else {
        const liveItems = [
            [t('accuracy.live.agreement'), `${liveConfidence.score}/100`],
            [t('accuracy.live.models'), String(models.length)],
            [t('accuracy.live.samples'), `${maxSamples}/${minimum}`],
            [t('accuracy.live.mode'), t('accuracy.live.relative')]
        ];
        bestRoot.innerHTML = liveItems.map(([label, value]) => `<div><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong></div>`).join('');
    }
    if (!rows.length) {
        if (!models.length) {
            root.innerHTML = `<div class="advanced-empty">${escapeHTML(t('accuracy.empty'))}</div>`;
            return;
        }
        root.innerHTML = models.map(model => `<div class="accuracy-model-row accuracy-live-row"><span><small>${escapeHTML(t('accuracy.metric.model'))}</small><strong>${escapeHTML(modelDisplayName(model.id))}</strong></span><span><small>${escapeHTML(t('accuracy.live.tempmax'))}</small><strong>${Number(model.temperatureMax || 0).toFixed(1)} °C</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.live.rain24'))}</small><strong>${Number(model.precipitationTotal || 0).toFixed(1)} mm</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.live.windmax'))}</small><strong>${Number(model.windMax || 0).toFixed(0)} km/h</strong></span><span class="accuracy-score-pill"><small>${escapeHTML(t('accuracy.live.label'))}</small><strong>${escapeHTML(t('accuracy.live.now'))}</strong></span></div>`).join('');
        return;
    }
    root.innerHTML = rows.map(row => `<div class="accuracy-model-row"><span><small>${escapeHTML(t('accuracy.metric.model'))}</small><strong>${escapeHTML(modelDisplayName(row.model))}</strong></span><span><small>${escapeHTML(t('accuracy.metric.temperature'))}</small><strong>${Number(row.tempMae).toFixed(1)} °C</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.metric.rain'))}</small><strong>${Number(row.rainMae).toFixed(2)} mm</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.metric.wind'))}</small><strong>${Number(row.windMae).toFixed(1)} km/h</strong></span><span class="accuracy-score-pill"><small>${escapeHTML(t('accuracy.metric.samples', { count: row.samples }))}</small><strong>${row.score}/100</strong></span></div>`).join('');
}
function currentLocationHour(date = new Date()) {
    const timeZone = resolveLocationTimeZone(state.location, state.weather);
    try {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(date);
        const value = Number(parts.find(part => part.type === 'hour')?.value);
        if (Number.isInteger(value)) return clamp(value, 0, 23);
    }
    catch { }
    return clamp(date.getHours(), 0, 23);
}
function effectiveBriefingHour() {
    if (personalWeatherPrefs.briefingHourSet === true && Number.isInteger(Number(personalWeatherPrefs.briefingHour)))
        return clamp(Number(personalWeatherPrefs.briefingHour), 0, 23);
    return currentLocationHour();
}
function syncBriefingControls() {
    const enabled = $('#briefing-enabled');
    const hour = $('#briefing-hour');
    const proactive = $('#proactive-enabled');
    if (enabled) enabled.checked = personalWeatherPrefs.briefingEnabled === true;
    if (proactive) proactive.checked = personalWeatherPrefs.proactiveEnabled === true;
    if (hour) {
        const selectedHour = effectiveBriefingHour();
        hour.value = String(selectedHour);
        if (typeof syncEnhancedSelect === 'function') syncEnhancedSelect('briefing-hour', hour.value);
    }
}
function briefingLocalDateKey() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
function briefingAiContext() {
    const current = state.weather?.current || {};
    const h = state.weather?.hourly || {};
    const start = currentHourlyIndex(state.weather);
    const hourlyForecast = (h.time || []).slice(start, start + 18).map((time, offset) => {
        const index = start + offset;
        return {
            time,
            condition: weatherMeta(Number(h.weather_code?.[index] ?? 0), 1).label,
            temperatureC: Number(h.temperature_2m?.[index] ?? 0),
            feelsLikeC: Number(h.apparent_temperature?.[index] ?? h.temperature_2m?.[index] ?? 0),
            rainProbability: Number(h.precipitation_probability?.[index] ?? 0),
            precipitationMm: Number(h.precipitation?.[index] ?? 0),
            windKmh: Number(h.wind_speed_10m?.[index] ?? 0),
            gustKmh: Number(h.wind_gusts_10m?.[index] ?? 0),
            humidity: Number(h.relative_humidity_2m?.[index] ?? 0),
            pressureHpa: Number(h.surface_pressure?.[index] ?? 0),
            visibilityKm: Number(h.visibility?.[index] ?? 10000) / 1000,
            uvIndex: Number(h.uv_index?.[index] ?? 0)
        };
    });
    const nowcast = extractNowcast();
    const impact = personalWeatherPrefs.activities.flatMap(id => {
        const summary = impactSummary(id);
        if (!summary) return [];
        const meta = impactActivityMeta(id);
        const end = new Date(summary.end);
        end.setHours(end.getHours() + 1);
        return [{
            activity: t(meta.label),
            score: summary.score,
            bestStart: summary.start,
            bestEnd: end.toISOString(),
            reason: t(summary.reason.key, summary.reason.params || {})
        }];
    });
    return {
        generatedAt: new Date().toISOString(),
        location: { name: shortLocationLabel(state.location), timezone: state.weather?.timezone || state.location?.timezone || '' },
        current: {
            time: current.time || new Date().toISOString(),
            condition: weatherMeta(Number(current.weather_code ?? 0), Number(current.is_day ?? 1)).label,
            temperatureC: Number(current.temperature_2m ?? 0),
            feelsLikeC: Number(current.apparent_temperature ?? current.temperature_2m ?? 0),
            precipitationMm: Number(current.precipitation ?? 0),
            windKmh: Number(current.wind_speed_10m ?? 0),
            gustKmh: Number(current.wind_gusts_10m ?? 0),
            humidity: Number(current.relative_humidity_2m ?? 0),
            pressureHpa: Number(current.surface_pressure ?? 0)
        },
        hourlyForecast,
        nowcast: {
            rainingNow: nowcast.rainingNow === true,
            expectedStart: nowcast.start || '',
            expectedEnd: nowcast.end || '',
            totalMm: Number(nowcast.total || 0),
            reliability: nowcastProReliability(nowcast)
        },
        changes: proactiveChangeMetrics(),
        radarPrediction: state.radar.motion ? {
            rainingNow: state.radar.motion.rainingNow === true,
            etaMinutes: optionalFiniteNumber(state.radar.motion.etaMinutes),
            exitMinutes: optionalFiniteNumber(state.radar.motion.exitMinutes),
            direction: String(state.radar.motion.direction || ''),
            speedKmh: Number(state.radar.motion.speedKmh || 0),
            confidence: Number(state.radar.motion.confidence || 0)
        } : {},
        ensemble: state.intelligence.ensemble ? {
            calibrated: state.intelligence.ensemble.calibrated === true,
            dominantModel: String(state.intelligence.ensemble.dominantModel || ''),
            sampleCount: Number(state.intelligence.ensemble.sampleCount || 0),
            temperatureMax: Number(state.intelligence.ensemble.temperatureMax || 0),
            precipitationTotal: Number(state.intelligence.ensemble.precipitationTotal || 0),
            windMax: Number(state.intelligence.ensemble.windMax || 0)
        } : {},
        severeEvents: state.severeWeather.authoritative === true && state.severeWeather.degraded !== true
            ? (state.severeWeather.events || []).slice(0, 4).map(event => ({
                type: String(event.type || ''), severity: String(event.severity || ''),
                confidence: Number(event.confidence || 0), title: String(event.title || ''),
                body: String(event.body || ''), startsAt: String(event.startsAt || event.start || ''),
                etaMinutes: optionalFiniteNumber(event.etaMinutes)
            })) : [],
        impact
    };
}
function briefingHistoryEntries() {
    const rows = loadJSON(STORAGE.briefingHistory, []);
    return Array.isArray(rows) ? rows.filter(row => row && typeof row.answer === 'string' && row.answer.trim()).slice(0, 7) : [];
}
function rememberBriefing(entry) {
    if (!entry?.answer) return;
    const next = [entry, ...briefingHistoryEntries().filter(row => row.answer !== entry.answer || row.date !== entry.date)].slice(0, 7);
    saveJSON(STORAGE.briefingHistory, next);
}
function briefingPreviousEntry() {
    const current = loadJSON(STORAGE.briefingDaily, null);
    if (current?.answer) return current;
    return briefingHistoryEntries()[0] || null;
}
function briefingInlineHtml(value) {
    const safe = escapeHTML(String(value || ''));
    return safe
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');
}
function briefingAnswerHtml(answer) {
    const lines = String(answer || '').replace(/\r/g, '').split('\n');
    let html = '<div class="briefing-rich">';
    let inList = false;
    let contentLines = 0;
    const closeList = () => {
        if (!inList) return;
        html += '</ul>';
        inList = false;
    };
    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) {
            closeList();
            continue;
        }
        const bullet = line.match(/^[-•]\s+(.+)$/);
        if (bullet) {
            if (!inList) {
                html += '<ul>';
                inList = true;
            }
            html += `<li>${briefingInlineHtml(bullet[1])}</li>`;
            contentLines += 1;
            continue;
        }
        closeList();
        const heading = line.match(/^(?:#{1,4}\s*)?\*\*([^*]+)\*\*\s*:?\s*(.*)$/);
        if (heading) {
            const label = briefingInlineHtml(heading[1].trim());
            const rest = String(heading[2] || '').trim();
            if (contentLines === 0 && !rest)
                html += `<h3>${label}</h3>`;
            else
                html += `<h4>${label}</h4>`;
            if (rest) html += `<p>${briefingInlineHtml(rest)}</p>`;
            contentLines += 1;
            continue;
        }
        const stripped = line.replace(/^#{1,4}\s+/, '').replace(/^\*\*(.+)\*\*$/, '$1');
        html += `<p>${briefingInlineHtml(stripped)}</p>`;
        contentLines += 1;
    }
    closeList();
    html += '</div>';
    return html;
}
function localBriefingPreview() {
    const current = state.weather?.current || {};
    const daily = state.weather?.daily || {};
    const times = Array.isArray(daily.time) ? daily.time : [];
    const temp = Number(current.temperature_2m);
    const condition = weatherMeta(Number(current.weather_code ?? 0), Number(current.is_day ?? 1)).label;
    let risk = null;
    for (let index = 0; index < Math.min(4, times.length); index += 1) {
        const probability = Math.round(Number(daily.precipitation_probability_max?.[index] || 0));
        const code = Number(daily.weather_code?.[index] || 0);
        const rainMm = Number(daily.precipitation_sum?.[index] || 0);
        if ([95,96,99].includes(code) || probability >= 50 || rainMm >= 1.5) {
            risk = { index, probability, code, rainMm, date: times[index] };
            break;
        }
    }
    const location = shortLocationLabel(state.location);
    const temperatureText = Number.isFinite(temp) ? temperature(temp) : '--';
    if (risk) {
        const phenomenon = [95,96,99].includes(risk.code) ? t('briefing.local.storm') : t('briefing.local.rain');
        return {
            title: t('briefing.local.title'),
            copy: t('briefing.local.risk', { location, condition, temperature: temperatureText, date: formatShortDate(risk.date), phenomenon, probability: risk.probability })
        };
    }
    return {
        title: t('briefing.local.title'),
        copy: t('briefing.local.clear', { location, condition, temperature: temperatureText })
    };
}
function renderStoredBriefing() {
    const output = $('#briefing-output');
    if (!output) return;
    const stored = loadJSON(STORAGE.briefingDaily, null);
    output.classList.remove('is-thinking');
    if (stored?.answer && stored.date === briefingLocalDateKey()) {
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('briefing.ready.title'))}</strong>${briefingAnswerHtml(stored.answer)}</div>`;
        return;
    }
    if (!state.weather) return;
    const preview = localBriefingPreview();
    output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(preview.title)}</strong><p>${escapeHTML(preview.copy)}</p><small class="proactive-meta">${escapeHTML(t('briefing.local.ai_note'))}</small></div>`;
}
async function generateAiBriefing({ manual = true } = {}) {
    if (briefingGenerating || isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession())) return;
    briefingGenerating = true;
    SERVICES.get('panelLoader')?.set?.('#ai-briefing-panel', true, t('panel.loading'));
    const output = $('#briefing-output');
    if (output) {
        output.classList.add('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('briefing.thinking.title'))}</strong><p>${escapeHTML(t('briefing.thinking.copy'))}</p></div>`;
    }
    const activities = personalWeatherPrefs.activities.map(id => t(impactActivityMeta(id).label)).join(', ') || t('briefing.activities.none');
    const previous = briefingPreviousEntry();
    const previousAnswer = String(previous?.answer || '').trim();
    const prompt = [
        t('briefing.ai.prompt.v2', { location: shortLocationLabel(state.location), activities }),
        previousAnswer ? t('briefing.ai.previous_hint') : ''
    ].filter(Boolean).join('\n');
    try {
        const result = await apiRequest('api/ai/chat.php', {
            mode: 'briefing',
            message: prompt,
            language: state.settings.language,
            messages: previousAnswer ? [{ role: 'assistant', content: previousAnswer.slice(0, 1800) }] : [],
            context: briefingAiContext()
        }, { timeout: 55000 });
        const answer = String(result?.answer || '').trim();
        if (!answer) throw new Error(t('briefing.error.empty'));
        const oldDaily = loadJSON(STORAGE.briefingDaily, null);
        if (oldDaily?.answer) rememberBriefing(oldDaily);
        const next = {
            date: briefingLocalDateKey(),
            answer,
            provider: result.provider || '',
            model: result.model || '',
            at: Date.now()
        };
        saveJSON(STORAGE.briefingDaily, next);
        renderStoredBriefing();
        if (manual) showToast(t('briefing.toast.ready.title'), t('briefing.toast.ready.copy'), 'success');
    }
    catch (error) {
        if (output) {
            output.classList.remove('is-thinking');
            output.innerHTML = `<span><svg><use href="#i-alert"/></svg></span><div><strong>${escapeHTML(t('briefing.error.title'))}</strong><p>${escapeHTML(error.message || t('briefing.error.copy'))}</p></div>`;
        }
        if (manual) showToast(t('briefing.error.title'), error.message || t('briefing.error.copy'), 'warning');
    }
    finally {
        briefingGenerating = false;
        SERVICES.get('panelLoader')?.set?.('#ai-briefing-panel', false);
    }
}
function maybeGenerateMorningBriefing() {
    if (isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession()) || !personalWeatherPrefs.briefingEnabled || !isUiFeatureVisible('feature.ai.briefing') || !state.weather) return;
    const now = new Date();
    if (currentLocationHour(now) < effectiveBriefingHour()) return;
    const stored = loadJSON(STORAGE.briefingDaily, null);
    if (stored?.date === briefingLocalDateKey() && stored?.answer) {
        renderStoredBriefing();
        return;
    }
    generateAiBriefing({ manual: false });
}
function proactiveStoredInsight() {
    const value = loadJSON(STORAGE.proactiveInsight, null);
    return value && typeof value === 'object' ? value : null;
}
function proactiveChangeMetrics() {
    const current = state.intelligence.currentSnapshot || createIntelligenceSnapshot();
    const previous = state.intelligence.previousSnapshot;
    if (!current || !previous || current.locationKey !== previous.locationKey) return {};
    const rainTimingShiftMinutes = current.firstRain && previous.firstRain
        ? Math.round((new Date(current.firstRain).getTime() - new Date(previous.firstRain).getTime()) / 60000)
        : null;
    return {
        comparedAt: previous.fetchedAt ? new Date(Number(previous.fetchedAt)).toISOString() : null,
        rainTimingShiftMinutes,
        rainAccumulationDeltaMm: Number((current.rain24 - previous.rain24).toFixed(2)),
        maxGustDeltaKmh: Number((current.windMax24 - previous.windMax24).toFixed(1)),
        maxTemperatureDeltaC: Number((current.tempMax24 - previous.tempMax24).toFixed(1)),
        stabilityScore: confidenceFromModels().score
    };
}
