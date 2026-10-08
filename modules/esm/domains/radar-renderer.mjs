export function createRadarRenderer(context) {
    const {
        window, state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp,
        persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, showToast,
        drawRadarBaseMap, lonLatToWorld, worldToLonLat, radarTileUrl,
        syncRadarVectorMap, syncRadarVectorLayer, removeRadarVectorLayer, clearRadarFallbackCanvas,
        ensureRadar, setRadarFrame, stopRadarAnimation
    } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_RADAR_RENDERER_CONTEXT_INVALID');
    }
function renderRadarCameraPreview() {
    if (state.radar.vectorMapReady)
        return;
    if (state.radar.cameraPreviewRaf)
        return;
    state.radar.cameraPreviewRaf = requestAnimationFrame(() => {
        state.radar.cameraPreviewRaf = null;
        syncRadarVectorMap({ force: true });
        drawRadarBaseMap();
    });
}
function scheduleRadarRender(delay = 0) {
    if (state.radar.renderTimer) {
        clearTimeout(state.radar.renderTimer);
        state.radar.renderTimer = null;
    }
    if (delay > 0) {
        state.radar.renderTimer = setTimeout(() => {
            state.radar.renderTimer = null;
            scheduleRadarRender(0);
        }, delay);
        return;
    }
    if (state.radar.renderRaf)
        return;
    state.radar.renderRaf = requestAnimationFrame(() => {
        state.radar.renderRaf = null;
        renderRadarMap();
    });
}
function activeRadarFrames() {
    return state.radar.mode === 'live' ? state.radar.liveFrames : state.radar.forecastFrames;
}
function setRadarStatus(kind, text) {
    const status = $('#radar-status');
    status.classList.remove('error', 'warning', 'success');
    if (kind)
        status.classList.add(kind);
    status.innerHTML = `<i></i> ${escapeHTML(text)}`;
}
function updateRadarModeUI() {
    $$('[data-radar-mode]').forEach(button => button.classList.toggle('active', button.dataset.radarMode === state.radar.mode));
    const map = $('#radar-map');
    map.classList.toggle('live-mode', state.radar.mode === 'live');
    map.classList.toggle('forecast-mode', state.radar.mode === 'forecast');
    $('#radar-forecast-canvas').classList.toggle('active', state.radar.mode === 'forecast');
    if (state.radar.vectorMapReady) {
        if (state.radar.mode === 'live')
            syncRadarVectorLayer({ force: true });
        else
            removeRadarVectorLayer();
    }
    $('#radar-source').textContent = state.radar.mode === 'live' ? "" + meteonexaText("radar.updateradarmodeui.librewxr_radar") : "" + meteonexaText("radar.updateradarmodeui.open_meteo_forecast");
}
function setRadarMode(mode, { notify = true, persist = true } = {}) {
    const selected = mode === 'forecast' ? 'forecast' : 'live';
    stopRadarAnimation();
    state.radar.mode = selected;
    state.radar.presentationLayer = selected === 'forecast' ? 'forecast' : 'radar';
    state.radar.frames = activeRadarFrames();
    state.radar.index = selected === 'forecast' ? 0 : Math.max(0, state.radar.frames.length - 1);
    if (persist) {
        state.settings.radarMode = selected;
        persistLocalSettings();
        if ($('#radar-mode-setting'))
            $('#radar-mode-setting').value = selected;
    }
    updateRadarModeUI();
    const frames = activeRadarFrames();
    $('#radar-slider').max = String(Math.max(0, frames.length - 1));
    setRadarFrame(state.radar.index);
    if (notify)
        showToast(selected === 'live' ? t('radar.toast.active.title') : t('radar.forecast.active.title'), selected === 'live' ? t('radar.toast.active.copy') : t('radar.forecast.active.copy'), 'info', 2400);
}
function renderRadarMap() {
    if (!state.radar.map)
        return;
    const { container, tiles } = state.radar.map;
    const rect = container.getBoundingClientRect();
    if (!rect.width || !rect.height)
        return;
    syncRadarVectorMap();
    const fallbackActive = container.classList.contains('map-fallback-active');
    if (!state.radar.vectorMapReady && !fallbackActive) {
        clearRadarFallbackCanvas();
        clearForecastCanvas();
        return;
    }
    if (fallbackActive)
        drawRadarBaseMap();
    else
        clearRadarFallbackCanvas();
    if (state.radar.vectorMapReady && !state.radar.vectorRadarFailed) {
        tiles.replaceChildren();
        if (state.radar.mode === 'live') {
            clearForecastCanvas();
            if (!state.radar.presentationLayer || state.radar.presentationLayer === 'radar')
                syncRadarVectorLayer();
            else
                removeRadarVectorLayer();
        }
        else {
            removeRadarVectorLayer();
            drawForecastRadarLayer();
        }
        return;
    }
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    const zoom = clamp(Number(state.radar.zoom), 1, 10);
    const sourceZoom = state.radar.mode === 'live' ? Math.min(7, Math.floor(zoom)) : Math.floor(zoom);
    const tileScale = 2 ** (zoom - sourceZoom);
    const world = lonLatToWorld(center.lat, center.lon, sourceZoom);
    const tileSize = 256;
    const viewWidth = rect.width / tileScale;
    const viewHeight = rect.height / tileScale;
    const minX = Math.floor((world.x - viewWidth / 2) / tileSize) - 1;
    const maxX = Math.ceil((world.x + viewWidth / 2) / tileSize) + 1;
    const minY = Math.floor((world.y - viewHeight / 2) / tileSize) - 1;
    const maxY = Math.ceil((world.y + viewHeight / 2) / tileSize) + 1;
    const count = 2 ** sourceZoom;
    const frame = state.radar.mode === 'live' ? state.radar.liveFrames[state.radar.index] : null;
    const fragment = document.createDocumentFragment();
    const token = ++state.radar.renderToken;
    let radarPending = 0, radarLoaded = 0;
    tiles.replaceChildren();
    if (frame?.path && state.radar.mode === 'live') {
        for (let tx = minX; tx <= maxX; tx += 1) {
            for (let ty = minY; ty <= maxY; ty += 1) {
                if (ty < 0 || ty >= count)
                    continue;
                const wrappedX = ((tx % count) + count) % count;
                const left = (tx * tileSize - (world.x - viewWidth / 2)) * tileScale;
                const top = (ty * tileSize - (world.y - viewHeight / 2)) * tileScale;
                const displayTileSize = tileSize * tileScale;
                const tile = document.createElement('div');
                tile.className = 'radar-tile radar-only-tile';
                tile.style.width = `${displayTileSize}px`;
                tile.style.height = `${displayTileSize}px`;
                tile.style.transform = `translate3d(${Math.round(left)}px,${Math.round(top)}px,0)`;
                radarPending += 1;
                const radar = document.createElement('img');
                radar.src = radarTileUrl(frame, sourceZoom, wrappedX, ty);
                radar.className = "radar-overlay-tile";
                radar.style.width = '100%';
                radar.style.height = '100%';
                radar.alt = '';
                radar.decoding = 'async';
                radar.draggable = false;
                radar.referrerPolicy = 'no-referrer';
                radar.style.opacity = String(state.radar.opacity);
                const complete = ok => {
                    radarPending -= 1;
                    if (ok)
                        radarLoaded += 1;
                    if (radarPending === 0 && token === state.radar.renderToken) {
                        if (radarLoaded === 0)
                            handleRadarTileFailure();
                        else {
                            state.radar.failedTiles = 0;
                            $('#radar-empty').hidden = true;
                        }
                    }
                };
                radar.onload = () => complete(true);
                radar.onerror = () => { radar.style.display = 'none'; complete(false); };
                tile.appendChild(radar);
                fragment.appendChild(tile);
            }
        }
    }
    tiles.appendChild(fragment);
    if (state.radar.mode === 'forecast')
        drawForecastRadarLayer();
    else
        clearForecastCanvas();
}
function clearForecastCanvas() {
    const canvas = $('#radar-forecast-canvas');
    if (!canvas)
        return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if ($('#radar-dry-chip'))
        $('#radar-dry-chip').hidden = true;
}
function precipitationColor(amount) {
    if (amount >= 10)
        return [182, 70, 255];
    if (amount >= 5)
        return [255, 83, 88];
    if (amount >= 2.5)
        return [255, 191, 62];
    if (amount >= .6)
        return [60, 228, 156];
    return [45, 169, 255];
}
function drawForecastRadarLayer() {
    const canvas = $('#radar-forecast-canvas');
    const container = $('#radar-map');
    if (!canvas || !container)
        return;
    const rect = container.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const width = Math.max(1, Math.round(rect.width));
    const height = Math.max(1, Math.round(rect.height));
    if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
    }
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const frame = state.radar.forecastFrames[state.radar.index];
    const index = frame?.forecastIndex ?? 0;
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    const centerWorld = lonLatToWorld(center.lat, center.lon, state.radar.zoom);
    let significant = 0;
    for (const point of state.radar.forecastGrid) {
        const amount = Number(point.precipitation?.[index] || 0);
        const probability = Number(point.probability?.[index] || 0);
        if (amount < .02 && probability < 18)
            continue;
        significant += 1;
        const pointWorld = lonLatToWorld(point.lat, point.lon, state.radar.zoom);
        const x = width / 2 + (pointWorld.x - centerWorld.x);
        const y = height / 2 + (pointWorld.y - centerWorld.y);
        if (x < -150 || x > width + 150 || y < -150 || y > height + 150)
            continue;
        const radius = clamp(38 + probability * .48 + amount * 5, 45, 120);
        const [r, g, b] = precipitationColor(amount);
        const alpha = clamp((.13 + probability / 185 + amount / 22) * state.radar.opacity, .08, .78);
        const gradient = ctx.createRadialGradient(x, y, radius * .1, x, y, radius);
        gradient.addColorStop(0, `rgba(${r},${g},${b},${alpha})`);
        gradient.addColorStop(.46, `rgba(${r},${g},${b},${alpha * .55})`);
        gradient.addColorStop(1, `rgba(${r},${g},${b},0)`);
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.fill();
    }
    if ($('#radar-dry-chip'))
        $('#radar-dry-chip').hidden = true;
}
function handleRadarTileFailure() {
    state.radar.failedTiles += 1;
    if (state.radar.failedTiles < 1)
        return;
    $('#radar-empty').hidden = false;
    setRadarStatus('warning', meteonexaText("radar.radar_frame_unavailable_retrying"));
    clearTimeout(state.radar.tileRetryTimer);
    state.radar.tileRetryTimer = setTimeout(() => {
        if (state.currentPage === "radar" && navigator.onLine)
            ensureRadar(true, { silent: true });
    }, 2200);
}
function radarGridCoordinates() {
    const lat = Number(state.location.latitude), lon = Number(state.location.longitude);
    const latStep = .55;
    const lonStep = .7 / Math.max(.45, Math.cos(lat * Math.PI / 180));
    const points = [];
    for (let y = -2; y <= 2; y += 1)
        for (let x = -2; x <= 2; x += 1)
            points.push({ lat: lat + y * latStep, lon: lon + x * lonStep });
    return points;
}
async function loadForecastRadar() {
    const coordinates = radarGridCoordinates();
    const params = new URLSearchParams({
        latitude: coordinates.map(p => p.lat.toFixed(4)).join(','),
        longitude: coordinates.map(p => p.lon.toFixed(4)).join(','),
        hourly: 'precipitation,precipitation_probability',
        forecast_hours: '12',
        timezone: 'GMT'
    });
    let rows;
    try {
        const data = await fetchJSON(`${CONFIG.WEATHER_API}?${params}`, { timeout: 15000 });
        rows = Array.isArray(data) ? data : [data];
    }
    catch (error) {
        rows = [];
    }
    if (rows.length === coordinates.length && rows[0]?.hourly?.time?.length) {
        state.radar.forecastGrid = rows.map((row, i) => ({
            lat: Number(row.latitude ?? coordinates[i].lat), lon: Number(row.longitude ?? coordinates[i].lon),
            precipitation: row.hourly?.precipitation || [], probability: row.hourly?.precipitation_probability || []
        }));
        state.radar.forecastFrames = rows[0].hourly.time.map((time, index) => ({ time: Math.floor(new Date(`${time}Z`).getTime() / 1000), forecast: true, forecastIndex: index }));
    }
    else if (state.weather?.hourly?.time?.length) {
        const start = currentHourlyIndex(state.weather);
        const times = state.weather.hourly.time.slice(start, start + 12);
        state.radar.forecastGrid = coordinates.map((point, pointIndex) => ({
            ...point,
            precipitation: times.map((_, i) => Number(state.weather.hourly.precipitation?.[start + i] || 0) * (0.55 + ((pointIndex * 17 + i * 13) % 70) / 100)),
            probability: times.map((_, i) => clamp(Number(state.weather.hourly.precipitation_probability?.[start + i] || 0) + ((pointIndex * 11 + i * 7) % 24) - 12, 0, 100))
        }));
        state.radar.forecastFrames = times.map((time, index) => ({ time: Math.floor(new Date(time).getTime() / 1000), forecast: true, forecastIndex: index }));
    }
    else {
        state.radar.forecastGrid = [];
        state.radar.forecastFrames = [];
    }
    state.radar.forecastAvailable = state.radar.forecastFrames.length > 0;
    return state.radar.forecastAvailable;
}
async function loadLiveRadar() {
    if (!navigator.onLine)
        return false;
    let data = null;
    let lastError = null;
    for (let attempt = 0; attempt < 2 && !data; attempt += 1) {
        try {
            const endpoint = new URL(CONFIG.RADAR_API, location.href);
            if (endpoint.origin === location.origin) {
                endpoint.searchParams.set('lat', String(state.location.latitude));
                endpoint.searchParams.set('lon', String(state.location.longitude));
            }
            endpoint.searchParams.set('meteonexa_nocache', `${Date.now()}-${attempt}`);
            data = await fetchJSON(endpoint.toString(), { timeout: 15000 });
        }
        catch (error) {
            lastError = error;
            if (attempt === 0)
                await sleep(550);
        }
    }
    const pastFrames = data?.frames || data?.radar?.past || [];
    if (!data?.host || !pastFrames.length) {
        if (lastError)
            console.warn(t('radar.unavailable'), lastError);
        if (!state.radar.liveFrames.length)
            state.radar.liveAvailable = false;
        return false;
    }
    state.radar.requestNonce = Date.now();
    state.radar.host = data.host;
    state.radar.liveFrames = pastFrames.map(frame => ({ ...frame, host: data.host }));
    state.radar.liveAvailable = true;
    return true;
}
function updateRadarStats() {
    const frames = activeRadarFrames();
    $('#radar-frame-count').textContent = frames.length ? String(frames.length) : '0';
    const frame = frames[frames.length - 1];
    $('#radar-last-scan').textContent = frame?.time ? new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(Number(frame.time) * 1000)) : '--:--';
    if (state.radar.presentationLayer === 'satellite' || state.radar.presentationLayer === 'lightning')
        return;
    $('#radar-source').textContent = state.radar.mode === 'live' ? "" + meteonexaText("radar.updateradarmodeui.librewxr_radar") : "" + meteonexaText("radar.updateradarmodeui.open_meteo_forecast");
}
function scheduleRadarRefresh() {
    if (state.radar.refreshTimer)
        clearInterval(state.radar.refreshTimer);
    state.radar.refreshTimer = setInterval(() => {
        if (state.currentPage === "radar" && navigator.onLine)
            ensureRadar(true, { silent: true });
    }, (CONFIG.RADAR_REFRESH_MINUTES || 10) * 60 * 1000);
}

    return Object.freeze({
        renderRadarCameraPreview, scheduleRadarRender, activeRadarFrames, setRadarStatus, updateRadarModeUI, setRadarMode, renderRadarMap, clearForecastCanvas, precipitationColor, drawForecastRadarLayer, handleRadarTileFailure, radarGridCoordinates, loadForecastRadar, loadLiveRadar, updateRadarStats, scheduleRadarRefresh
    });
}
