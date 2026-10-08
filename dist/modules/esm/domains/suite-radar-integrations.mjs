export function createSuiteRadarIntegrations(context) {
    const {
        window, deps, CONFIG, state, suite, q, qa, num, clamp, safe, currentLocale,
        fetchJson, API, setText, locationData, ensureRadar, removeRadarVectorLayer,
        isGuest, uiVisible, deviceId, toast, meteonexaText, dateValue, localTime
    } = context;
    if (!state || !suite || typeof q !== 'function') {
        throw new Error('METEONEXA_SUITE_RADAR_INTEGRATIONS_CONTEXT_INVALID');
    }
function removeLightningLayers(map) {
    if (!map) return;
    for (const layerId of ['suite-lightning-halo', 'suite-lightning-layer']) {
        try { if (map.getLayer(layerId)) map.removeLayer(layerId); } catch { }
    }
    try { if (map.getSource('suite-lightning-source')) map.removeSource('suite-lightning-source'); } catch { }
}
function imageFrom(url) { return new Promise((resolve, reject) => { const img = new Image(); img.decoding = 'async'; img.onload = () => resolve(img); img.onerror = () => reject(new Error(meteonexaText("suite.imagefrom.radar_tile_unreadable"))); img.src = url; }); }
async function showLiveLightning() {
    if (typeof ensureRadar === 'function')
        await ensureRadar();
    const map = state?.radar?.vectorMap;
    if (!map || !state.radar.vectorMapReady)
        throw new Error(meteonexaText("suite.showlivelightning.map_not_ready_yet"));
    const loc = locationData();
    const params = new URLSearchParams({ lat: String(loc.latitude), lon: String(loc.longitude), radius: '100', limit: '400' });
    const data = await fetchJson(`${API.lightning}?${params}`, { timeout: 25000 });
    removeLightningLayers(map);
    try {
        removeRadarVectorLayer?.();
    }
    catch { }
    ['suite-weather-layer', 'suite-weather-labels', 'meteonexa-satellite-layer'].forEach(id => {
        try {
            if (map.getLayer(id))
                map.removeLayer(id);
        }
        catch { }
    });
    ['suite-weather-source', 'meteonexa-satellite-source'].forEach(id => {
        try {
            if (map.getSource(id))
                map.removeSource(id);
        }
        catch { }
    });
    qa('[data-suite-map-layer]').forEach(b => b.classList.remove('active'));
    const features = (data.strikes || []).map(row => ({ type: 'Feature', properties: { age: row.ageSeconds, polarity: row.polarity || 'unknown', amperage: row.amperage, distance: row.distanceKm, label: meteonexaText('lightning.marker.distance_age', { distance: Math.round(row.distanceKm), minutes: Math.round(row.ageSeconds / 60) }) }, geometry: { type: 'Point', coordinates: [row.longitude, row.latitude] } }));
    state.radar.mode = 'live';
    state.radar.presentationLayer = 'lightning';
    map.addSource('suite-lightning-source', { type: 'geojson', data: { type: 'FeatureCollection', features } });
    map.addLayer({ id: 'suite-lightning-halo', type: 'circle', source: 'suite-lightning-source', paint: { 'circle-radius': ['interpolate', ['linear'], ['zoom'], 3, 8, 9, 25], 'circle-color': '#ffd456', 'circle-opacity': .18 } });
    map.addLayer({ id: 'suite-lightning-layer', type: 'circle', source: 'suite-lightning-source', paint: { 'circle-radius': ['interpolate', ['linear'], ['zoom'], 3, 4, 9, 10], 'circle-color': ['match', ['get', 'polarity'], 'negative', '#66b8ff', 'positive', '#ff8a5f', '#ffd456'], 'circle-stroke-color': '#fff', 'circle-stroke-width': 1.5, 'circle-opacity': ['interpolate', ['linear'], ['get', 'age'], 0, 1, 300, .35] } });
    setText('#radar-source', meteonexaText('lightning.real_count', { count: features.length }));
    qa('[data-advanced-radar-layer]').forEach(b => b.classList.toggle('active', b.dataset.advancedRadarLayer === 'lightning'));
    return features.length;
}
async function registerRadarArchive(notify = true) {
    if (isGuest() || !uiVisible('section.radar.archive')) return null;
    const loc = locationData(), archiveKey = `${loc.latitude.toFixed(3)}:${loc.longitude.toFixed(3)}`;
    if (!notify && localStorage.getItem('meteonexa_suite_radar_archive') !== archiveKey)
        return null;
    if (notify)
        localStorage.setItem('meteonexa_suite_radar_archive', archiveKey);
    const status = q('#suite-archive-status');
    try {
        const data = await fetchJson(API.radarRegister, { method: 'POST', body: { deviceId, name: loc.name, latitude: loc.latitude, longitude: loc.longitude }, timeout: 30000 });
        suite.archiveLocationId = data.locationId;
        if (status)
            status.textContent = data.capture?.captured ? meteonexaText("suite.registerradararchive.frame_archived") : meteonexaText("suite.registerradararchive.location_registered");
        if (notify)
            toast(meteonexaText("suite.registerradararchive.radar_archive_enabled"), meteonexaText("suite.registerradararchive.progressive_retention_set_up_366_days"));
        return data;
    }
    catch (error) {
        if (status)
            status.textContent = meteonexaText('radar.archive.server_missing');
        if (notify)
            toast(meteonexaText("suite.registerradararchive.archive_not_enabled"), error.message, 'warning');
        throw error;
    }
}
function archiveTileBounds(frame) {
    const z = Math.max(0, Math.min(22, num(frame?.zoom)));
    const x = num(frame?.x), y = num(frame?.y), scale = 2 ** z;
    const lon = tileX => tileX / scale * 360 - 180;
    const lat = tileY => Math.atan(Math.sinh(Math.PI * (1 - 2 * tileY / scale))) * 180 / Math.PI;
    return { west: lon(x), east: lon(x + 1), north: lat(y), south: lat(y + 1) };
}
function archiveCoordinates(frame) {
    const b = archiveTileBounds(frame);
    return [[b.west, b.north], [b.east, b.north], [b.east, b.south], [b.west, b.south]];
}
function stopArchivePlayback() {
    if (suite.archiveTimer) clearTimeout(suite.archiveTimer);
    suite.archiveTimer = null;
    const button = q('#suite-archive-play');
    if (button) button.innerHTML = '<svg><use href="#i-play"/></svg>';
}
function destroyArchiveMap() {
    stopArchivePlayback();
    try { suite.archiveMap?.remove?.(); } catch { }
    suite.archiveMap = null;
    suite.archiveMapReady = null;
    suite.archiveFitted = false;
    q('.suite-archive-radar-map')?.classList.remove('vector-map-ready', 'map-initializing', 'map-fallback-active');
    q('#suite-archive-map-fallback')?.classList.remove('map-ready');
}
async function transparentArchiveImage(frame) {
    const img = await imageFrom(frame.imageUrl);
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, img.naturalWidth || img.width || 256);
    canvas.height = Math.max(1, img.naturalHeight || img.height || 256);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const px = imageData.data;
    for (let i = 0; i < px.length; i += 4) {
        if (px[i + 3] < 8) continue;
        const max = Math.max(px[i], px[i + 1], px[i + 2]);
        const min = Math.min(px[i], px[i + 1], px[i + 2]);
        const chroma = max - min;
        if (max < 34 && chroma < 22) px[i + 3] = 0;
        else if (max < 58 && chroma < 18) px[i + 3] = Math.min(px[i + 3], 72);
    }
    ctx.putImageData(imageData, 0, 0);
    return canvas.toDataURL('image/png');
}
function archiveFallback(frame, url = '') {
    const fallback = q('#suite-archive-map-fallback');
    const stage = q('.suite-archive-radar-map');
    if (!fallback || !frame) return;
    stage?.classList.remove('map-initializing', 'vector-map-ready');
    stage?.classList.add('map-fallback-active');
    fallback.classList.remove('map-ready');
    fallback.hidden = false;
    fallback.innerHTML = `<img src="${safe(url || frame.imageUrl)}" alt="${safe(meteonexaText('radar.archive.image_alt'))}" style="opacity:${Math.max(.2, Math.min(1, suite.archiveOpacity / 100))}">`;
}
function updateArchiveControls(index) {
    suite.archiveIndex = clamp(num(index), 0, Math.max(0, suite.archive.length - 1));
    const range = q('#suite-archive-range');
    if (range) range.value = String(suite.archiveIndex);
    setText('#suite-archive-progress-copy', meteonexaText('radar.radar_frame_value_value', { current: suite.archiveIndex + 1, total: suite.archive.length }));
    q('#suite-archive-prev')?.toggleAttribute('disabled', suite.archive.length < 2);
    q('#suite-archive-next')?.toggleAttribute('disabled', suite.archive.length < 2);
}
async function renderArchiveFrame(index = 0, { fit = false } = {}) {
    const targetIndex = clamp(num(index), 0, Math.max(0, suite.archive.length - 1));
    const frame = suite.archive[targetIndex];
    if (!frame) return;
    const renderToken = ++suite.archiveRenderToken;
    updateArchiveControls(targetIndex);
    try { deps.tooltips?.hideUiTooltip?.({ immediate: true }); } catch { }
    setText('#suite-archive-time', localTime(frame.time));
    let overlayUrl = frame.imageUrl;
    try { overlayUrl = await transparentArchiveImage(frame); } catch { }
    if (renderToken !== suite.archiveRenderToken) return;
    archiveFallback(frame, overlayUrl);
    const overlay = q('#suite-archive-map-fallback');
    const mapRoot = q('#suite-archive-map');
    const stage = q('.suite-archive-radar-map');
    if (!mapRoot || !window.maplibregl || !CONFIG.OPENFREEMAP_STYLE) return;
    const coordinates = archiveCoordinates(frame);
    try {
        if (!suite.archiveMap) {
            stage?.classList.add('map-initializing');
            stage?.classList.remove('vector-map-ready', 'map-fallback-active');
            const liveMap = state?.radar?.vectorMap;
            const liveCenter = liveMap?.getCenter?.();
            const frameCenter = [(coordinates[0][0] + coordinates[2][0]) / 2, (coordinates[0][1] + coordinates[2][1]) / 2];
            const center = liveCenter && Number.isFinite(Number(liveCenter.lng)) && Number.isFinite(Number(liveCenter.lat))
                ? [Number(liveCenter.lng), Number(liveCenter.lat)]
                : frameCenter;
            const liveZoom = Number(liveMap?.getZoom?.());
            suite.archiveMap = new window.maplibregl.Map({
                container: mapRoot,
                style: CONFIG.OPENFREEMAP_STYLE,
                center,
                zoom: Number.isFinite(liveZoom) ? liveZoom : Math.max(3, num(frame.zoom)),
                minZoom: 1,
                maxZoom: 10,
                bearing: Number(liveMap?.getBearing?.()) || 0,
                pitch: Number(liveMap?.getPitch?.()) || 0,
                attributionControl: false,
                interactive: true,
                renderWorldCopies: true,
                fadeDuration: 0,
                pitchWithRotate: false,
                dragRotate: false,
                antialias: false,
                transformRequest: (url) => {
                    try {
                        const parsed = new URL(url, location.href);
                        if (/librewxr\.net$|openfreemap\.org$/i.test(parsed.hostname)) {
                            parsed.searchParams.set('meteonexa_nocache', String(Date.now()));
                            return { url: parsed.toString(), credentials: 'omit' };
                        }
                    }
                    catch { }
                    return { url };
                }
            });
            try {
                suite.archiveMap.dragRotate?.disable?.();
                suite.archiveMap.touchZoomRotate?.disableRotation?.();
                suite.archiveMap.boxZoom?.disable?.();
            }
            catch { }
            const markReady = () => {
                stage?.classList.remove('map-initializing', 'map-fallback-active');
                stage?.classList.add('vector-map-ready');
                overlay?.classList.add('map-ready');
            };
            suite.archiveMapReady = new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('ARCHIVE_MAP_TIMEOUT')), 9000);
                const onReady = () => {
                    clearTimeout(timer);
                    try { markReady(); } catch { }
                    resolve();
                };
                suite.archiveMap.once('load', onReady);
                suite.archiveMap.on('styledata', () => {
                    if (suite.archiveMap?.isStyleLoaded?.()) markReady();
                });
                suite.archiveMap.once('error', event => {
                    if (!suite.archiveMap?.loaded?.()) { clearTimeout(timer); reject(event?.error || new Error('ARCHIVE_MAP_ERROR')); }
                });
            });
        }
        await suite.archiveMapReady;
        const sourceId = 'meteonexa-archive-radar-source';
        const layerId = 'meteonexa-archive-radar-layer';
        const existing = suite.archiveMap.getSource(sourceId);
        if (existing?.updateImage) existing.updateImage({ url: overlayUrl, coordinates });
        else {
            if (suite.archiveMap.getLayer(layerId)) suite.archiveMap.removeLayer(layerId);
            if (existing) suite.archiveMap.removeSource(sourceId);
            suite.archiveMap.addSource(sourceId, { type: 'image', url: overlayUrl, coordinates });
            suite.archiveMap.addLayer({ id: layerId, type: 'raster', source: sourceId, paint: { 'raster-opacity': suite.archiveOpacity / 100, 'raster-fade-duration': 0 } });
        }
        if (suite.archiveMap.getLayer(layerId)) suite.archiveMap.setPaintProperty(layerId, 'raster-opacity', suite.archiveOpacity / 100);
        if (fit || !suite.archiveFitted) {
            const liveMap = state?.radar?.vectorMap;
            const liveCenter = liveMap?.getCenter?.();
            const liveZoom = Number(liveMap?.getZoom?.());
            if (liveCenter && Number.isFinite(Number(liveCenter.lng)) && Number.isFinite(Number(liveCenter.lat)) && Number.isFinite(liveZoom)) {
                suite.archiveMap.jumpTo({
                    center: [Number(liveCenter.lng), Number(liveCenter.lat)],
                    zoom: liveZoom,
                    bearing: Number(liveMap?.getBearing?.()) || 0,
                    pitch: Number(liveMap?.getPitch?.()) || 0
                });
            } else {
                const b = archiveTileBounds(frame);
                suite.archiveMap.fitBounds([[b.west, b.south], [b.east, b.north]], { padding: 0, duration: 0, maxZoom: num(frame.zoom) });
            }
            suite.archiveFitted = true;
        }
        if (overlay) {
            overlay.hidden = true;
            overlay.classList.add('map-ready');
        }
        stage?.classList.remove('map-initializing', 'map-fallback-active');
        stage?.classList.add('vector-map-ready');
        try { suite.archiveMap.resize?.(); } catch { }
    }
    catch (error) {
        console.warn('RADAR_ARCHIVE_MAP_FALLBACK', error?.message || error);
        stage?.classList.remove('vector-map-ready', 'map-initializing');
        stage?.classList.add('map-fallback-active');
        destroyArchiveMap();
        archiveFallback(frame, overlayUrl);
    }
}
async function archiveStep(delta) {
    if (!suite.archive.length) return;
    const next = (suite.archiveIndex + delta + suite.archive.length) % suite.archive.length;
    await renderArchiveFrame(next);
}
function toggleArchivePlayback() {
    if (suite.archive.length < 2) return;
    if (suite.archiveTimer) {
        stopArchivePlayback();
        return;
    }
    const button = q('#suite-archive-play');
    if (button) button.innerHTML = '<svg><use href="#i-pause"/></svg>';
    const tick = async () => {
        if (!suite.archiveTimer) return;
        try { await archiveStep(1); } catch { }
        if (suite.archiveTimer) suite.archiveTimer = setTimeout(tick, suite.archiveSpeed);
    };
    suite.archiveTimer = setTimeout(tick, suite.archiveSpeed);
}
async function loadRadarArchive() {
    if (isGuest() || !uiVisible('section.radar.archive')) return null;
    const root = q('#suite-archive-view');
    if (!root) return;
    const loc = locationData(), date = q('#suite-archive-date')?.value || dateValue(), params = new URLSearchParams({ deviceId, lat: String(loc.latitude), lon: String(loc.longitude), date });
    const data = await fetchJson(`${API.radarArchive}?${params}`, { timeout: 20000 });
    suite.archive = data.frames || [];
    suite.archiveIndex = 0;
    if (!suite.archive.length) {
        destroyArchiveMap();
        root.innerHTML = `<div class="advanced-empty">${safe(meteonexaText('radar.archive.empty_date', { date: new Date(`${date}T12:00:00`).toLocaleDateString(currentLocale()) }))}</div>`;
        return;
    }
    destroyArchiveMap();
    root.innerHTML = `<div class="suite-archive-stage radar-card">
      <div class="radar-map suite-archive-radar-map map-initializing" role="application" aria-label="${safe(meteonexaText('radar.archive.image_alt'))}">
        <div id="suite-archive-map" class="radar-world-map suite-archive-map"></div>
        <div id="suite-archive-map-fallback" class="suite-archive-map-fallback" hidden></div>
        <div class="map-vignette"></div><div class="radar-marker"><span></span><i></i></div>
      </div>
      <div class="radar-map-controls" aria-label="${safe(meteonexaText('suite.loadradararchive.radar_map_controls'))}">
        <button class="radar-map-button" id="suite-archive-fullscreen" type="button" aria-label="${safe(meteonexaText('suite.loadradararchive.full_screen'))}"><svg><use href="#i-fullscreen"/></svg></button>
        <div class="radar-zoom" role="group" aria-label="${safe(meteonexaText('suite.loadradararchive.zoom_controls'))}"><button class="radar-map-button" id="suite-archive-zoom-in" type="button" aria-label="${safe(meteonexaText('suite.loadradararchive.zoom'))}">+</button><button class="radar-map-button" id="suite-archive-zoom-out" type="button" aria-label="${safe(meteonexaText('suite.loadradararchive.zoom_out'))}">−</button></div>
      </div>
      <div class="radar-attribution">${safe(meteonexaText('suite.loadradararchive.openfreemap_openstreetmap_natural_earth_librewxr_open_meteo'))}</div>
      <div class="radar-bottom-overlay suite-archive-player">
        <div class="radar-admin-key" aria-label="${safe(meteonexaText('suite.loadradararchive.administrative_border_legend'))}"><span class="region-key"><i></i><span>${safe(meteonexaText('suite.loadradararchive.world_borders'))}</span></span><span class="metro-key"><i></i><span>${safe(meteonexaText('suite.loadradararchive.cities_locations'))}</span></span></div>
        <div class="radar-player-buttons"><button class="radar-step-button" id="suite-archive-prev" type="button" aria-label="${safe(meteonexaText('suite.loadradararchive.previous_frame'))}">‹</button><button class="radar-play-button" id="suite-archive-play" type="button" aria-label="${safe(meteonexaText('radar.stopradaranimation.play_animation'))}"><svg><use href="#i-play"/></svg></button><button class="radar-step-button" id="suite-archive-next" type="button" aria-label="${safe(meteonexaText('suite.loadradararchive.next_frame'))}">›</button></div>
        <div class="radar-timeline"><div class="radar-time-row"><strong id="suite-archive-time">--:--</strong><span id="suite-archive-progress-copy"></span></div><div id="suite-archive-range" class="meteo-range" data-meteo-range role="slider" tabindex="0" data-min="0" data-max="${suite.archive.length - 1}" data-step="1" data-value="0" aria-valuemin="0" aria-valuemax="${suite.archive.length - 1}" aria-valuenow="0"><span class="meteo-range-track"><span class="meteo-range-fill"></span><span class="meteo-range-thumb"></span></span></div></div>
        <div class="radar-speed"><span>${safe(meteonexaText('suite.loadradararchive.speed'))}</span><div class="meteo-select-control" data-meteo-select-control><button class="meteo-select-trigger" data-meteo-select data-choice-position="top" id="suite-archive-speed" type="button" value="720" aria-haspopup="listbox" aria-expanded="false" aria-controls="suite-archive-speed-menu"><span class="meteo-select-value" data-meteo-select-value>${safe(meteonexaText('suite.loadradararchive.normal'))}</span><svg aria-hidden="true"><use href="#i-chevron"/></svg></button><div class="meteo-select-menu" id="suite-archive-speed-menu" role="listbox" hidden><button class="meteo-select-option" data-meteo-option="1100" role="option" type="button" aria-selected="false"><span>${safe(meteonexaText('suite.loadradararchive.slow'))}</span></button><button class="meteo-select-option" data-meteo-option="720" role="option" type="button" aria-selected="true"><span>${safe(meteonexaText('suite.loadradararchive.normal'))}</span></button><button class="meteo-select-option" data-meteo-option="420" role="option" type="button" aria-selected="false"><span>${safe(meteonexaText('suite.loadradararchive.fast'))}</span></button></div></div></div>
        <div class="radar-opacity"><svg><use href="#i-layers"/></svg><div id="suite-archive-opacity" class="meteo-range" data-meteo-range role="slider" tabindex="0" data-min="20" data-max="100" data-step="1" data-value="${suite.archiveOpacity}" aria-valuemin="20" aria-valuemax="100" aria-valuenow="${suite.archiveOpacity}"><span class="meteo-range-track"><span class="meteo-range-fill"></span><span class="meteo-range-thumb"></span></span></div><span id="suite-archive-opacity-value">${suite.archiveOpacity}%</span></div>
      </div>
    </div>`;
    deps.controls?.enhance?.(root);
    q('#suite-archive-range')?.addEventListener('input', e => { stopArchivePlayback(); renderArchiveFrame(num(e.target.value)).catch(() => { }); });
    q('#suite-archive-prev')?.addEventListener('click', () => { stopArchivePlayback(); archiveStep(-1); });
    q('#suite-archive-next')?.addEventListener('click', () => { stopArchivePlayback(); archiveStep(1); });
    q('#suite-archive-play')?.addEventListener('click', toggleArchivePlayback);
    q('#suite-archive-fullscreen')?.addEventListener('click', async () => { const stage = q('.suite-archive-stage'); if (!stage) return; if (document.fullscreenElement === stage) await document.exitFullscreen?.(); else await stage.requestFullscreen?.(); setTimeout(() => { try { suite.archiveMap?.resize?.(); } catch { } }, 220); });
    document.addEventListener('fullscreenchange', () => { setTimeout(() => { try { suite.archiveMap?.resize?.(); } catch { } }, 220); }, { passive: true });
    q('#suite-archive-zoom-in')?.addEventListener('click', () => suite.archiveMap?.zoomIn?.({ duration: 180 }));
    q('#suite-archive-zoom-out')?.addEventListener('click', () => suite.archiveMap?.zoomOut?.({ duration: 180 }));
    q('#suite-archive-speed')?.addEventListener('change', event => { suite.archiveSpeed = clamp(num(event.target.value), 300, 1600); if (suite.archiveTimer) { stopArchivePlayback(); toggleArchivePlayback(); } });
    q('#suite-archive-opacity')?.addEventListener('input', event => {
        suite.archiveOpacity = clamp(num(event.target.value), 20, 100);
        setText('#suite-archive-opacity-value', `${suite.archiveOpacity}%`);
        const layerId = 'meteonexa-archive-radar-layer';
        try { if (suite.archiveMap?.getLayer?.(layerId)) suite.archiveMap.setPaintProperty(layerId, 'raster-opacity', suite.archiveOpacity / 100); } catch { }
        const fallbackImage = q('#suite-archive-map-fallback img');
        if (fallbackImage) fallbackImage.style.opacity = String(suite.archiveOpacity / 100);
    });
    await renderArchiveFrame(0, { fit: true });
}
    return Object.freeze({ removeLightningLayers, imageFrom, showLiveLightning, registerRadarArchive, archiveTileBounds, archiveCoordinates, stopArchivePlayback, destroyArchiveMap, transparentArchiveImage, archiveFallback, updateArchiveControls, renderArchiveFrame, archiveStep, toggleArchivePlayback, loadRadarArchive });
}
