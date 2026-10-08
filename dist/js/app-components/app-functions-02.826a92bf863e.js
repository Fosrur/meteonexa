'use strict';
function buildAirURL(locationData = state.location) {
    const params = new URLSearchParams({
        latitude: String(locationData.latitude), longitude: String(locationData.longitude),
        hourly: 'european_aqi,pm10,pm2_5,ozone,nitrogen_dioxide,carbon_monoxide,alder_pollen,birch_pollen,grass_pollen',
        timezone: 'auto', forecast_days: '3'
    });
    return `${CONFIG.AIR_QUALITY_API}?${params}`;
}
function setArticleInlineLoading(target, busy, label = '') {
    const root = typeof target === 'string' ? $(target) : target;
    if (!root || !root.matches?.('article')) return;
    let loader = root.querySelector(':scope > .article-inline-preloader');
    if (busy) {
        if (!loader) {
            loader = document.createElement('div');
            loader.className = 'article-inline-preloader';
            loader.setAttribute('role', 'status');
            loader.setAttribute('aria-live', 'polite');
            loader.innerHTML = '<span class="article-inline-spinner" aria-hidden="true"></span><strong></strong>';
            root.append(loader);
        }
        loader.querySelector('strong').textContent = label || meteonexaText('panel.loading');
        loader.hidden = false;
        root.classList.add('is-panel-loading');
        root.setAttribute('aria-busy', 'true');
    } else {
        if (loader) loader.hidden = true;
        root.classList.remove('is-panel-loading');
        root.removeAttribute('aria-busy');
    }
}
function severeWeatherLocationKey(locationData = state.location) {
    return `${Number(locationData?.latitude || 0).toFixed(3)}:${Number(locationData?.longitude || 0).toFixed(3)}`;
}
function severeWeatherEventRank(event = {}) {
    const severity = ({ red: 3, orange: 2, yellow: 1 })[String(event.severity || '').toLowerCase()] || 0;
    const type = ({ hail: 8, storm: 7, snow: 6, ice: 5, wind: 4, fog: 3, heat: 2 })[String(event.type || '').toLowerCase()] || 1;
    return severity * 100 + type;
}
function renderHomeSevereWeather() {
    const root = $('#home-severe-alert');
    if (!root) return;
    const monitor = state.severeWeather;
    const expectedKey = severeWeatherLocationKey();
    const age = monitor?.fetchedAt ? Date.now() - Number(monitor.fetchedAt) : Infinity;
    const usable = monitor?.authoritative === true && monitor?.degraded !== true
        && monitor?.locationKey === expectedKey && age <= 8 * 60 * 1000;
    const events = usable && Array.isArray(monitor.events) ? [...monitor.events] : [];
    if (!events.length) { root.hidden = true; return; }
    events.sort((a, b) => severeWeatherEventRank(b) - severeWeatherEventRank(a));
    const event = events[0] || {};
    root.hidden = false;
    root.dataset.severity = ['red','orange','yellow'].includes(String(event.severity || '')) ? String(event.severity) : 'yellow';
    const title = $('#home-severe-alert-title'), copy = $('#home-severe-alert-copy'), meta = $('#home-severe-alert-meta');
    if (title) title.textContent = String(event.title || meteonexaText('home.severe.fallback.title'));
    if (copy) copy.textContent = String(event.body || meteonexaText('home.severe.fallback.copy'));
    if (meta) {
        const eta = optionalFiniteNumber(event.etaMinutes);
        const startsAt = String(event.startsAt || '');
        const when = Number.isFinite(eta) && eta >= 0 && eta <= 180
            ? (eta <= 0 ? meteonexaText('home.severe.now') : meteonexaText('home.severe.arrival',{minutes:Math.round(eta)}))
            : (startsAt ? meteonexaText('home.severe.starts',{time:formatOfficialAlertTime(startsAt)}) : '');
        const confidence = Number(event.confidence);
        const confidenceText = Number.isFinite(confidence) ? meteonexaText('home.severe.confidence',{value:Math.round(confidence)}) : '';
        const votes = Number(event.modelVotes), available = Number(event.modelsAvailable), agreement = Number(event.modelAgreementPct);
        const modelText = Number.isFinite(votes) && Number.isFinite(available) && available > 0 && Number.isFinite(agreement)
            ? meteonexaText('home.severe.models',{votes:Math.round(votes),available:Math.round(available),agreement:Math.round(agreement)}) : '';
        meta.textContent = [when, confidenceText, modelText, meteonexaText('home.severe.predictive')].filter(Boolean).join(' · ');
    }
}
async function loadSevereWeatherMonitor({ force = false } = {}) {
    if (!hasUsableLocation()) return null;
    if (state.severeWeather.request) return state.severeWeather.request;
    const key = severeWeatherLocationKey();
    const age = state.severeWeather.fetchedAt ? Date.now() - state.severeWeather.fetchedAt : Infinity;
    if (!force && state.severeWeather.locationKey === key && age < 90 * 1000) {
        renderHomeSevereWeather();
        return state.severeWeather;
    }
    if (PREVIEW_MODE || !navigator.onLine) {
        renderHomeSevereWeather();
        return state.severeWeather;
    }
    const task = (async () => {
        const params = new URLSearchParams({
            lat: String(Number(state.location.latitude).toFixed(3)),
            lon: String(Number(state.location.longitude).toFixed(3)),
            lang: String(state.settings.language || 'it').slice(0,2)
        });
        try {
            const result = await fetchJSON(`api/weather/severe.php?${params}`, { timeout: 25000, credentials: 'omit' });
            const monitor = result?.monitor || {};
            state.severeWeather.events = Array.isArray(monitor.events) ? monitor.events : [];
            state.severeWeather.authoritative = monitor.authoritative === true;
            state.severeWeather.degraded = monitor.degraded === true;
            state.severeWeather.fetchedAt = Date.now();
            state.severeWeather.locationKey = key;
            renderHomeSevereWeather();
            if (state.severeWeather.authoritative && !state.severeWeather.degraded && state.severeWeather.events.length
                && !isGuestSession() && personalWeatherPrefs.proactiveEnabled === true) {
                setTimeout(() => maybeGenerateProactiveInsight({ manual: false }).catch(() => {}), 250);
            }
            return state.severeWeather;
        } catch (error) {
            console.warn('SEVERE_WEATHER_MONITOR_FAILED', error);
            if (Date.now() - Number(state.severeWeather.fetchedAt || 0) > 8 * 60 * 1000) {
                state.severeWeather.events = [];
                state.severeWeather.authoritative = false;
                state.severeWeather.degraded = true;
                state.severeWeather.locationKey = key;
                renderHomeSevereWeather();
            }
            return state.severeWeather;
        }
    })();
    state.severeWeather.request = task;
    try { return await task; }
    finally { if (state.severeWeather.request === task) state.severeWeather.request = null; }
}
function scheduleSevereWeatherMonitor() {
    clearInterval(state.severeWeather.timer);
    state.severeWeather.timer = window.setInterval(() => {
        if (!document.hidden && $('#weather-app')?.hidden === false) loadSevereWeatherMonitor({ force: true }).catch(()=>{});
    }, 2 * 60 * 1000);
}
function officialAlertEventId(raw = '') {
    const text = String(raw || '');
    const map = [[/thunder|tempor/i,'thunderstorm'],[/rain|piogg/i,'rain'],[/snow|neve/i,'snow'],[/wind|vento/i,'wind'],[/ice|ghiacci/i,'ice'],[/fog|nebb/i,'fog'],[/heat|high temperature|caldo/i,'heat']];
    return (map.find(([rx]) => rx.test(text)) || [])[1] || 'weather';
}
function officialAlertReadable(warning = {}) {
    const raw = String(warning.title || warning.officialTitle || '').trim();
    const severityRaw = String(warning.severity || '').toLowerCase();
    const colorMatch = raw.match(/\b(yellow|orange|red)\b/i);
    const severity = ['yellow','orange','red'].includes(severityRaw) ? severityRaw : String(colorMatch?.[1] || 'yellow').toLowerCase();
    const eventId = officialAlertEventId(raw);
    const areaMatch = raw.match(/(?:issued\s+for\s+italy\s*[-–:]\s*|italy\s*[-–:]\s*)(.+)$/i);
    const area = areaMatch?.[1] ? String(areaMatch[1]).replace(/\s+warning.*$/i,'').trim() : '';
    const level = meteonexaText(`advanced.official.level.${severity}`);
    const event = meteonexaText(`advanced.official.event.${eventId}`);
    const label = area ? meteonexaText('advanced.official.summary.area',{level,event,area}) : meteonexaText('advanced.official.summary',{level,event});
    return { label, severity, eventId, area };
}
function officialAlertLifecycleText(warning = {}) {
    const life = warning?.lifecycle || {};
    const current = String(warning?.severity || 'yellow').toLowerCase();
    const previous = String(life.previousSeverity || '').toLowerCase();
    const parts = [];
    if (previous && previous !== current && ['yellow','orange','red'].includes(previous) && ['yellow','orange','red'].includes(current)) {
        const from = meteonexaText(`advanced.official.level.${previous}`), to = meteonexaText(`advanced.official.level.${current}`);
        parts.push(meteonexaText(meteonexaOfficialSeverityRank(current) > meteonexaOfficialSeverityRank(previous) ? 'home.official.lifecycle.escalated' : 'home.official.lifecycle.downgraded', { from, to }));
    }
    const previousEnd = Date.parse(String(life.previousEndsAt || ''));
    const currentEnd = Date.parse(String(warning?.endsAt || ''));
    if (Number.isFinite(previousEnd) && Number.isFinite(currentEnd) && currentEnd > previousEnd + 60000) {
        parts.push(meteonexaText('home.official.lifecycle.extended', { until: formatOfficialAlertTime(warning.endsAt) }));
    } else if (Number.isFinite(previousEnd) && Number.isFinite(currentEnd) && currentEnd < previousEnd - 60000) {
        parts.push(meteonexaText('home.official.lifecycle.shortened', { until: formatOfficialAlertTime(warning.endsAt) }));
    }
    if (!parts.length && ['updated','new'].includes(String(life.changeType || '')) && life.changedAt) parts.push(meteonexaText(life.changeType === 'new' ? 'home.official.lifecycle.new' : 'home.official.lifecycle.updated'));
    return parts.join(' · ');
}
function meteonexaOfficialSeverityRank(value='') { return ({green:0,yellow:1,orange:2,red:3})[String(value).toLowerCase()] ?? 0; }
function formatOfficialAlertTime(value) {
    const date = new Date(String(value || '')); if (Number.isNaN(date.getTime())) return '--';
    const locale = state.settings?.language || document.documentElement.lang || 'it';
    const timezone = state.weather?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
    try { return new Intl.DateTimeFormat(locale,{weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:timezone}).format(date); } catch { return date.toLocaleString(); }
}
function renderHomeOfficialAlert({ loading = false, error = false } = {}) {
    const root = $('#home-official-alert');
    if (!root) return;
    const data = state.officialAlerts;
    const expectedKey=`${Number(state.location.latitude).toFixed(3)}:${Number(state.location.longitude).toFixed(3)}`;
    const title = $('#home-official-alert-title'), copy = $('#home-official-alert-copy'), meta = $('#home-official-alert-meta');
    const guest = isGuestSession();
    if (loading || (!data && guest)) {
        root.hidden = false; root.dataset.severity = 'info'; root.dataset.state = 'loading';
        if (title) title.textContent = meteonexaText('home.official.loading.title');
        if (copy) copy.textContent = meteonexaText('home.official.loading.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:'MeteoAlarm'});
        return;
    }
    if (!data || state.officialAlertsLocationKey!==expectedKey) { root.hidden = true; return; }
    if (error || data.error === true || data.available === false) {
        root.hidden = false; root.dataset.severity = 'info'; root.dataset.state = 'unavailable';
        if (title) title.textContent = meteonexaText('home.official.unavailable.title');
        if (copy) copy.textContent = meteonexaText('home.official.unavailable.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    const rows = Array.isArray(data.relevant) ? data.relevant : [];
    const regional = Array.isArray(data.regionalAdvisories) ? data.regionalAdvisories : [];
    root.dataset.state = rows.length ? 'active' : (regional.length ? 'regional' : 'clear');
    if (!rows.length && regional.length) {
        root.hidden = false; root.dataset.severity = 'info';
        const region = String(state.location?.admin1 || state.location?.name || '').trim();
        if (title) title.textContent = meteonexaText('home.official.regional.title');
        if (copy) copy.textContent = meteonexaText('home.official.regional.copy',{location:shortLocationLabel(state.location),region});
        if (meta) meta.textContent = meteonexaText('home.official.regional.meta',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    if (!rows.length) {
        root.hidden = false;
        root.dataset.severity = 'green';
        if (title) title.textContent = meteonexaText('home.official.none.title');
        if (copy) copy.textContent = meteonexaText('home.official.none.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    const rank={red:3,orange:2,yellow:1};
    const stateRank={active:3,upcoming:2,unknown:1};
    const sorted=[...rows].sort((a,b)=>((stateRank[String(b?.windowState||'unknown')]||0)-(stateRank[String(a?.windowState||'unknown')]||0))||((rank[String(b?.severity||'yellow')]||1)-(rank[String(a?.severity||'yellow')]||1)));
    const warning=sorted[0], readable=officialAlertReadable(warning);
    root.hidden=false;root.dataset.severity=readable.severity;
    if(title)title.textContent=readable.label;
    if(copy){
        const windowState=String(warning?.windowState||'unknown');
        let base='';
        if(windowState==='upcoming'){
            base=warning?.startsAt
                ? meteonexaText('home.official.upcoming.copy',{location:shortLocationLabel(state.location),start:formatOfficialAlertTime(warning.startsAt)})
                : meteonexaText('home.official.upcoming.copy_unknown',{location:shortLocationLabel(state.location)});
        }else if(windowState==='active'){
            base=rows.length>1
                ? meteonexaText('home.official.active.multiple',{count:rows.length,location:shortLocationLabel(state.location)})
                : meteonexaText('home.official.active.copy',{location:shortLocationLabel(state.location)});
        }else{
            base=meteonexaText('home.official.unknown.copy',{location:shortLocationLabel(state.location)});
        }
        const life=officialAlertLifecycleText(warning);copy.textContent=[life,base].filter(Boolean).join(' · ');
    }
    const source=String(warning.source||data.source||'MeteoAlarm');
    const snapshot=(warning.serverSnapshot===true||data.pipelineFallback===true||data.staleProviderCache===true)?meteonexaText('home.official.server_snapshot'):'';
    let validity='';
    if(String(warning.windowState||'')==='active'&&warning.endsAt)validity=meteonexaText('home.official.validity.until',{time:formatOfficialAlertTime(warning.endsAt)});
    else if(String(warning.windowState||'')==='upcoming'&&warning.startsAt&&warning.endsAt)validity=meteonexaText('home.official.validity.window',{start:formatOfficialAlertTime(warning.startsAt),end:formatOfficialAlertTime(warning.endsAt)});
    else if(String(warning.windowState||'')==='upcoming'&&warning.startsAt)validity=meteonexaText('home.official.validity.starts',{time:formatOfficialAlertTime(warning.startsAt)});
    else if(warning.validityKnown===false)validity=meteonexaText('home.official.validity.unknown');
    if(meta)meta.textContent=[meteonexaText('home.official.source',{source}),validity,snapshot].filter(Boolean).join(' · ');
}
async function loadHomeOfficialAlerts({force=false}={}) {
    if (state.officialAlertsRequest) return state.officialAlertsRequest;
    const key=`${Number(state.location.latitude).toFixed(3)}:${Number(state.location.longitude).toFixed(3)}`;
    const age=state.officialAlertsFetchedAt?Date.now()-state.officialAlertsFetchedAt:Infinity;
    const degraded=state.officialAlerts?.error===true||state.officialAlerts?.available===false;
    const cacheTtl=degraded?20*1000:5*60*1000;
    if(!force&&state.officialAlerts&&state.officialAlertsLocationKey===key&&age<cacheTtl){renderHomeOfficialAlert();return state.officialAlerts;}
    if(PREVIEW_MODE){state.officialAlerts={available:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert();return state.officialAlerts;}
    if (isGuestSession()) renderHomeOfficialAlert({ loading: true });
    const task=(async()=>{
        const params=new URLSearchParams({lat:String(state.location.latitude),lon:String(state.location.longitude),location:String(state.location.name||''),admin1:String(state.location.admin1||''),lang:String(state.settings.language||'it').slice(0,2)});
        try{
            let result;
            try{result=await fetchJSON(`api/official/alerts.php?${params}`,{timeout:12000,credentials:'same-origin'});}
            catch(firstError){
                if(!isGuestSession())throw firstError;
                await sleep(450);
                result=await fetchJSON(`api/official/alerts.php?${params}`,{timeout:12000,credentials:'same-origin'});
            }
            state.officialAlerts=result?.alerts||{available:false,error:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert();return state.officialAlerts;
        }catch(error){console.warn('HOME_OFFICIAL_ALERTS_FAILED',error);state.officialAlerts={available:false,error:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert({error:true});return state.officialAlerts;}
    })();
    state.officialAlertsRequest=task;
    try{return await task;}finally{if(state.officialAlertsRequest===task)state.officialAlertsRequest=null;}
}
async function loadWeather({ force = false, silent = false } = {}) {
    if (state.weatherRequest)
        return state.weatherRequest;
    const cacheAge = state.weather?.fetchedAt ? Date.now() - state.weather.fetchedAt : Infinity;
    if (!force && state.weather?.source === 'live' && cacheAge < 5 * 60 * 1000) {
        renderAll();
        loadForecastFusion({ force: false }).catch(()=>{});
        loadSevereWeatherMonitor({ force: false }).catch(()=>{});
        loadHomeOfficialAlerts({force:false}).catch(()=>{});
        return state.weather;
    }
    const task = async () => {
        if (!state.weather)
            state.weather = createPreviewWeather();
        if (!state.air)
            state.air = createPreviewAir();
        renderAll();
        if (PREVIEW_MODE)
            return state.weather;
        if (!navigator.onLine) {
            if (state.weather?.source !== 'preview') {
                state.weather = { ...state.weather, source: 'cache', cacheReason: 'offline' };
                saveJSON(STORAGE.weather, state.weather);
            }
            renderAll();
            showToast("" + meteonexaText("radar.task.offline_mode"), "" + meteonexaText("app.task.showing_latest_data_available_device"), 'warning');
            return state.weather;
        }

        loadForecastFusion({ force }).catch(()=>{});
        loadSevereWeatherMonitor({ force }).catch(()=>{});
        const [weatherResult, airResult] = await Promise.allSettled([
            fetchJSON(buildWeatherURL()),
            fetchJSON(buildAirURL(), { timeout: 10000 })
        ]);
        if (weatherResult.status === 'fulfilled' && weatherResult.value?.current) {
            state.weather = { ...weatherResult.value, fetchedAt: Date.now(), source: 'live', cacheReason: '' };
            applyWeatherTimeZoneMetadata(state.weather);
            updateIntelligenceSnapshot(state.weather);
            saveJSON(STORAGE.weather, state.weather);
            applyLoginWeatherBackdrop(state.weather);
        }
        else if (!state.weather?.fetchedAt || state.weather?.source === 'preview') {
            state.weather = createPreviewWeather();
            showToast("" + meteonexaText("app.task.temporary_demo_data"), "" + meteonexaText("app.task.weather_service_did_not_respond_interface_remains_usable"), 'warning');
        }
        else {
            state.weather = { ...state.weather, source: 'cache', cacheReason: 'provider' };
            saveJSON(STORAGE.weather, state.weather);
            showToast("" + meteonexaText("app.task.update_failed"), "" + meteonexaText("app.task.continuing_show_latest_saved_data"), 'warning');
        }
        if (airResult.status === 'fulfilled' && airResult.value?.hourly) {
            state.air = { ...airResult.value, fetchedAt: Date.now(), source: 'live' };
            saveJSON(STORAGE.air, state.air);
        }
        renderAll();
        loadSevereWeatherMonitor({ force }).catch(()=>{});
        loadHomeOfficialAlerts({force}).catch(()=>{});
        try { SERVICES.get('weather')?.publish?.(state.weather, state.location, state.air); } catch { }
        return state.weather;
    };
    state.weatherRequest = silent
        ? task()
        : withLoader("" + meteonexaText("app.setloader.weather_update"), meteonexaText("app.task.retrieving_forecast_air_quality_value", { location: state.location.name }), task, 650);
    try {
        return await state.weatherRequest;
    }
    finally {
        state.weatherRequest = null;
    }
}
function renderAll() {
    if (!state.weather)
        return;
    updateWeatherAtmosphere();
    renderHeader();
    renderHero();
    renderNowMetrics();
    renderHourly();
    renderDaily();
    renderAirQuality();
    renderSun();
    renderInsights();
    renderTrustBrief();
    renderIntelligence();
    renderAlerts();
    renderHomeSevereWeather();
    renderHomeOfficialAlert();
    SERVICES.get('advanced')?.renderAll?.();
    queueMicrotask(evaluateLocalWeatherNotifications);
    renderDetailsTable();
    if (state.history.data)
        renderHistory();
    syncFavoriteUI();
    requestAnimationFrame(() => {
        drawHomeChart();
        drawTrendCharts();
        if (state.currentPage === 'details')
            drawAllDetailCharts();
        if (state.currentPage === 'history' && state.history.data)
            drawHistoryChart();
    });
    translateDOM(document);
}
function weatherFreshnessLabel() {
    const data = state.weather;
    const fetchedAt = Number(data?.fetchedAt || 0);
    const date = new Date(fetchedAt || Date.now());
    const cityTimeZone = resolveLocationTimeZone(state.location, data);
    const updateTime = new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit', timeZone: cityTimeZone }).format(date);
    if (data?.source === 'live') {
        if (forecastFusionIsAuthoritative(data)) {
            return t('weather.reliability.live_fusion', {
                time: updateTime,
                count: Number(state.forecastFusion.freshModelsAvailable || state.forecastFusion.modelsAvailable || 0),
                total: Number(state.forecastFusion.modelsExpected || state.forecastFusion.modelsAvailable || 0)
            });
        }
        return t('weather.reliability.live_base', { time: updateTime });
    }
    if (!fetchedAt) return t('weather.reliability.cache_unknown');
    const ageMinutes = Math.max(0, Math.floor((Date.now() - fetchedAt) / 60000));
    if (ageMinutes < 60) return t('weather.reliability.cache_minutes', { count: Math.max(1, ageMinutes) });
    const ageHours = Math.floor(ageMinutes / 60);
    if (ageHours < 48) return t('weather.reliability.cache_hours', { count: ageHours });
    return t('weather.reliability.cache_days', { count: Math.floor(ageHours / 24) });
}
function renderHeader() {
    const label = shortLocationLabel(state.location);
    $('#top-location').textContent = label;
    $('#hero-location').textContent = label;
    $('#radar-location-name').textContent = label;
    const updateLabel = weatherFreshnessLabel();
    const topLocation = $('#top-location');
    const lastUpdate = $('#last-update');
    topLocation.dataset.compact = state.location.name || label;
    topLocation.dataset.tooltip = label;
    lastUpdate.textContent = updateLabel;
    lastUpdate.dataset.compact = updateLabel;
    lastUpdate.dataset.tooltip = updateLabel;
    lastUpdate.dataset.freshness = state.weather.source === 'live' ? 'live' : 'cache';
    refreshTimeZoneLabels();
    const dataStatus = $('#data-status');
    dataStatus.textContent = state.weather.source !== 'live' ? updateLabel : (navigator.onLine ? updateLabel : t("app.renderheader.latest_data_available_offline"));
    dataStatus.classList.toggle('is-stale', state.weather.source !== 'live');
    const onlineDot = $('#online-dot');
    onlineDot.classList.toggle('offline', !navigator.onLine);
    onlineDot.classList.toggle('stale', state.weather.source !== 'live');
    const cityTimeZone = resolveLocationTimeZone(state.location, state.weather);
    $('#hero-date').textContent = capitalize(new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: cityTimeZone }).format(new Date()));
}
function renderHero() {
    const data = state.weather;
    const current = data.current;
    const hourlyIndex = currentHourlyIndex(data);
    const resolved = resolveFusedHourlyCondition(data, hourlyIndex);
    const meta = resolved.meta;
    const hero = $('#hero-card');
    hero.classList.remove('weather-clear', 'weather-night', 'weather-cloud', 'weather-rain', 'weather-storm', 'weather-snow', 'weather-fog');
    hero.classList.add(`weather-${meta.kind}`);
    $('#current-temp').textContent = Math.round(convertTemp(current.temperature_2m));
    $('#current-unit').textContent = unitLabel();
    $('#current-description').textContent = meta.label;
    $('#weather-summary').textContent = weatherSummary(data);
    $('#current-feels').textContent = temperature(current.apparent_temperature);
    $('#current-wind').textContent = `${Math.round(current.wind_speed_10m || 0)} km/h ${windDirection(current.wind_direction_10m)}`;
    $('#current-rain').textContent = `${Math.round(data.hourly.precipitation_probability[hourlyIndex] || 0)}%`;
    $('#current-weather-art').innerHTML = weatherArt(resolved.code, resolved.isDay);
    $('#hero-high-low').textContent = t('weather.high_low.compact', { high: temperature(data.daily.temperature_2m_max[0]), low: temperature(data.daily.temperature_2m_min[0]) });
    $('#radar-rain-risk').textContent = `${Math.round(data.daily.precipitation_probability_max[0] || 0)}%`;
}
function renderNowMetrics() {
    const current = state.weather.current;
    const hourlyIndex = currentHourlyIndex(state.weather);
    const visibility = Number(state.weather.hourly.visibility[hourlyIndex] || 0);
    const uv = Number(state.weather.hourly.uv_index[hourlyIndex] || 0);
    $('#metric-humidity').textContent = `${Math.round(current.relative_humidity_2m || 0)}%`;
    $('#humidity-note').textContent = metricNoteHumidity(current.relative_humidity_2m || 0);
    $('#metric-pressure').textContent = `${Math.round(current.surface_pressure || 0)} hPa`;
    $('#pressure-note').textContent = metricNotePressure(current.surface_pressure || 0);
    $('#metric-visibility').textContent = `${(visibility / 1000).toFixed(1)} km`;
    $('#visibility-note').textContent = metricNoteVisibility(visibility);
    $('#metric-uv').textContent = uv.toFixed(1);
    $('#uv-note').textContent = uvLabel(uv);
    const comfort = getComfort(current.temperature_2m, current.relative_humidity_2m, current.wind_speed_10m);
    const badge = $('#comfort-badge');
    badge.textContent = comfort.label;
    badge.className = `soft-badge ${comfort.className}`;
}
function getComfort(tempC, humidity, wind) {
    const score = Math.abs(Number(tempC) - 22) + Math.abs(Number(humidity) - 50) / 12 + Math.max(0, Number(wind) - 25) / 8;
    if (score < 4)
        return { label: "" + meteonexaText("app.getcomfort.excellent_comfort"), className: 'good' };
    if (score < 8)
        return { label: "" + meteonexaText("app.getcomfort.good_comfort"), className: 'good' };
    return { label: "" + meteonexaText("app.getcomfort.variable_comfort"), className: '' };
}
function renderHourly() {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    const root = $('#hourly-strip');
    root.innerHTML = data.hourly.time.slice(start, start + 12).map((time, offset) => {
        const index = start + offset;
        const current = offset === 0;
        const resolved = resolveFusedHourlyCondition(data, index);
        const meta = resolved.meta;
        return "" + "<button class=\"hour-card " + (current ? 'current' : '') + "\" type=\"button\" data-hour-index=\"" + index + "\" aria-label=\"" + escapeHTML(meteonexaText('hour.open_detail', { time: formatClock(time), condition: meta.label })) + "\">\n      <time>" + (current ? "" + escapeHTML(meteonexaText("app.renderhourly.now")) : escapeHTML(formatClock(time))) + "</time>\n      " + weatherArt(resolved.code, resolved.isDay) + "\n      <span class=\"hour-card-condition " + (resolved.dry ? 'is-dry' : '') + "\">" + escapeHTML(resolved.dry ? meteonexaText('weather.reliability.dry_short') : meta.label) + "</span>\n      <strong>" + temperature(data.hourly.temperature_2m[index]) + "</strong>\n      <small><svg><use href=\"#i-droplet\"/></svg>" + Number(resolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " mm · " + Math.round(data.hourly.precipitation_probability[index] || 0) + "%</small>\n      <span class=\"hour-card-more\">" + escapeHTML(meteonexaText("history.renderhistory.details")) + "</span>\n    </button>";
    }).join('');
    $$('.hour-card', root).forEach(card => card.addEventListener('click', () => openHourDetail(Number(card.dataset.hourIndex))));
}
function hourMetric(label, value, note = '') {
    return `<article class="hour-detail-metric"><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong>${note ? `<span>${escapeHTML(note)}</span>` : ''}</article>`;
}
