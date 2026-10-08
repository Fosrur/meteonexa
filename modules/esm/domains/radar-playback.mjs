export function createRadarPlayback(context) {
    const {
        state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
        normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
        loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
        persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
        showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
        lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion,
        initRadarMap, activeRadarFrames, setRadarStatus, updateRadarModeUI, renderRadarMap,
        drawForecastRadarLayer, loadForecastRadar, loadLiveRadar, updateRadarStats,
        scheduleRadarRefresh, syncRadarVectorMap
    } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_RADAR_PLAYBACK_CONTEXT_INVALID');
    }
async function ensureRadar(force = false, options = {}) {
    initRadarMap();
    renderRadarMap();
    if (state.radar.loading)
        return state.radar.loadingPromise || Promise.resolve();
    if (state.radar.loaded && !force)
        return;
    state.radar.loading = true;
    deps.radar?.begin?.(force ? 'refresh' : 'load', 14000);
    $('#radar-refresh').classList.add('loading');
    const task = async () => {
        if (force) {
            stopRadarAnimation();
            state.radar.vectorRadarFailed = false;
            state.radar.vectorRadarRetryAt = 0;
            $('#radar-map')?.classList.remove("vector-radar-fallback");
            state.radar.requestNonce = Date.now();
            state.radar.failedTiles = 0;
        }
        setRadarStatus('', "" + meteonexaText("radar.task.connecting_weather_sources"));
        const selectedOverlay = state.radar.presentationLayer === 'satellite' || state.radar.presentationLayer === 'lightning'
            ? state.radar.presentationLayer
            : '';
        const [liveResult, forecastResult] = await Promise.allSettled([loadLiveRadar(), loadForecastRadar()]);
        const liveOk = liveResult.status === 'fulfilled' && liveResult.value;
        const forecastOk = forecastResult.status === 'fulfilled' && forecastResult.value;
        state.radar.loaded = true;
        $('#radar-empty').hidden = true;
        let desired = selectedOverlay ? 'live' : (state.settings.radarMode || 'live');
        if (!selectedOverlay && desired === 'live' && !liveOk && forecastOk)
            desired = 'forecast';
        if (!selectedOverlay && desired === 'forecast' && !forecastOk)
            desired = liveOk ? 'live' : 'forecast';
        state.radar.mode = desired;
        state.radar.presentationLayer = selectedOverlay || (desired === 'forecast' ? 'forecast' : 'radar');
        state.radar.frames = activeRadarFrames();
        state.radar.index = desired === 'forecast' ? 0 : Math.max(0, state.radar.frames.length - 1);
        if (liveOk)
            setRadarStatus('success', desired === 'live' ? t('radar.connected') : t('radar.background.ready'));
        else if (forecastOk)
            setRadarStatus('warning', desired === 'forecast' ? meteonexaText("radar.task.precipitation_forecast_active") : meteonexaText("radar.live_radar_unavailable_forecast_active"));
        else
            setRadarStatus('error', navigator.onLine ? "" + meteonexaText("radar.task.weather_sources_unavailable") : "" + meteonexaText("radar.task.offline_mode"));
        if ((desired === 'live' && !liveOk && !state.radar.liveFrames.length) || (!liveOk && !forecastOk))
            $('#radar-empty').hidden = false;
        updateRadarModeUI();
        $('#radar-slider').max = String(Math.max(0, state.radar.frames.length - 1));
        setRadarFrame(state.radar.index);
        updateRadarStats();
        renderRadarMotion();
        if (liveOk) analyzeRadarMotion({ force }).catch(error => console.warn('RADAR_PREDICTIVE_BACKGROUND_FAILED', error));
        scheduleRadarRefresh();
        deps.radar?.complete?.({
            mode: desired,
            liveAvailable: Boolean(liveOk),
            forecastAvailable: Boolean(forecastOk),
            frameCount: state.radar.frames.length,
            lastUpdatedAt: new Date().toISOString()
        });
            if (force && !options.silent)
            showToast(liveOk || forecastOk ? "" + meteonexaText("radar.task.radar_updated") : "" + meteonexaText("radar.task.radar_unavailable"), liveOk ? "" + meteonexaText("radar.new_live_frames_loaded") : forecastOk ? meteonexaText("radar.12_hour_forecast_available_live_radar_will_retried") : "" + meteonexaText("radar.check_connection_try_again"), liveOk ? 'success' : 'warning');
    };
    const loadingPromise = (async () => {
        try {
            if (options.silent)
                await task();
            else
                await withLoader(force ? "" + meteonexaText("radar.task.radar_update") : "" + meteonexaText("radar.task.radar_connection"), "" + meteonexaText("radar.loading_precipitation_observations_forecast"), task, 620);
        }
        catch (error) {
            deps.radar?.fail?.(error);
            throw error;
        }
        finally {
            state.radar.loading = false;
            $('#radar-refresh').classList.remove('loading');
        }
    })();
    state.radar.loadingPromise = loadingPromise;
    try {
        return await loadingPromise;
    }
    finally {
        if (state.radar.loadingPromise === loadingPromise)
            state.radar.loadingPromise = null;
    }
}
function setRadarFrame(index) {
    const frames = activeRadarFrames();
    state.radar.frames = frames;
    if (!frames.length) {
        $('#radar-time').textContent = '--:--';
        $('#radar-progress-copy').textContent = navigator.onLine ? "" + meteonexaText("radar.setradarframe.no_frames_available") : "" + meteonexaText("radar.setradarframe.data_unavailable_offline");
        updateRadarStats();
        renderRadarMap();
        return;
    }
    state.radar.index = clamp(Number(index), 0, frames.length - 1);
    const frame = frames[state.radar.index];
    $('#radar-slider').max = String(Math.max(0, frames.length - 1));
    $('#radar-slider').value = String(state.radar.index);
    const frameDate = new Date(Number(frame.time) * 1000);
    const timeText = new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit' }).format(frameDate);
    $('#radar-time').textContent = timeText;
    const localTime = formatLocationLocalTime();
    $('#radar-frame-label').textContent = state.radar.mode === 'live' ? meteonexaText("radar.observation_value_local_value", { time: timeText, localTime: localTime }) : meteonexaText("radar.forecast_value_local_value", { time: timeText, localTime: localTime });
    $('#radar-progress-copy').textContent = meteonexaText('radar.radar_frame_value_value', { current: state.radar.index + 1, total: frames.length });
    updateRadarStats();
    if (state.radar.vectorMapReady && state.radar.mode === 'live' && (!state.radar.presentationLayer || state.radar.presentationLayer === 'radar'))
        syncRadarVectorLayer({ force: true });
    renderRadarMap();
}
function stopRadarAnimation() {
    if (state.radar.timer)
        clearInterval(state.radar.timer);
    state.radar.timer = null;
    $('#radar-play').innerHTML = '<svg><use href="#i-play"/></svg>';
    $('#radar-play').setAttribute('aria-label', "" + meteonexaText("radar.stopradaranimation.play_animation"));
}
function toggleRadarAnimation() {
    const frames = activeRadarFrames();
    if (!frames.length) {
        showToast("" + meteonexaText("radar.toggleradaranimation.animation_unavailable"), "" + meteonexaText("radar.there_no_frames_play"), 'warning');
        return;
    }
    if (state.radar.timer) {
        stopRadarAnimation();
        return;
    }
    $('#radar-play').innerHTML = '<svg><use href="#i-pause"/></svg>';
    $('#radar-play').setAttribute('aria-label', "" + meteonexaText("radar.toggleradaranimation.pause_animation"));
    state.radar.timer = setInterval(() => setRadarFrame((state.radar.index + 1) % frames.length), state.radar.speed);
}
function stepRadar(delta) {
    stopRadarAnimation();
    const frames = activeRadarFrames();
    if (!frames.length)
        return;
    setRadarFrame((state.radar.index + delta + frames.length) % frames.length);
}
function zoomRadar(delta, notify = true) {
    const map = state.radar.vectorMap;
    const previous = state.radar.vectorMapReady && map ? map.getZoom() : Number(state.radar.zoom);
    const target = clamp(previous + delta, 1, 10);
    if (previous === target) {
        if (notify)
            showToast("" + meteonexaText("radar.zoomradar.radar_zoom"), meteonexaText("radar.zoomradar.level_value", { value: Math.round(target * 10) / 10 }), 'info', 1300);
        return;
    }
    state.radar.zoom = target;
    const container = $('#radar-map');
    container?.classList.add('is-zooming');
    if (state.radar.vectorMapReady && map) {
        try {
            map.easeTo({ zoom: target, duration: state.settings.reduceMotion ? 0 : 260, essential: true });
        }
        catch {
            map.jumpTo({ zoom: target });
        }
    }
    else {
        drawRadarBaseMap();
        scheduleRadarRender(80);
        setTimeout(() => container?.classList.remove('is-zooming'), 180);
    }
    if (notify)
        showToast("" + meteonexaText("radar.zoomradar.radar_zoom"), meteonexaText("radar.zoomradar.level_value", { value: Math.round(target * 10) / 10 }), 'info', 1600);
}


    return Object.freeze({
        ensureRadar, setRadarFrame, stopRadarAnimation, toggleRadarAnimation, stepRadar, zoomRadar
    });
}
