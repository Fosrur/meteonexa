export function createRadarVectorMap(context) {
    const {
        window, state, CONFIG, $, clamp, meteonexaText, drawRadarBaseMap, security,
        loadRadarBaseData, loadRadarAdminData, renderRadarMap,
        drawForecastRadarLayer, scheduleRadarRender
    } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_RADAR_VECTOR_CONTEXT_INVALID');
    }
function clearRadarVectorTimers() {
    if (state.radar.vectorRepaintTimer)
        clearTimeout(state.radar.vectorRepaintTimer);
    if (state.radar.vectorRecoveryTimer)
        clearTimeout(state.radar.vectorRecoveryTimer);
    clearRadarVectorRevealTimer();
    state.radar.vectorRepaintTimer = null;
    state.radar.vectorRecoveryTimer = null;
}
function radarVectorCameraKey(center, zoom) {
    return `${Number(center.lon).toFixed(5)}:${Number(center.lat).toFixed(5)}:${Number(zoom).toFixed(2)}`;
}
const RADAR_VECTOR_SOURCE_ID = 'meteonexa-radar-live-source';
const RADAR_VECTOR_LAYER_ID = 'meteonexa-radar-live-layer';
function radarVectorTileTemplate(frame) {
    if (!frame?.path || !frame?.frameSignature || !frame?.tileExpires)
        return '';
    const params = new URLSearchParams({
        host: frame.tileHost || frame.host || '', path: frame.path, z: '{z}', x: '{x}', y: '{y}', color: String(frame.tileColor ?? 10), smooth: String(frame.tileSmooth || '1_1'),
        deviceId: security?.deviceId || '', exp: String(frame.tileExpires), frameSig: frame.frameSignature
    });
    return `api/radar/tile.php?${params.toString().replaceAll('%7Bz%7D', '{z}').replaceAll('%7Bx%7D', '{x}').replaceAll('%7By%7D', '{y}')}`;
}
function clearRadarVectorRevealTimer() {
    if (state.radar.vectorRadarRevealTimer)
        clearTimeout(state.radar.vectorRadarRevealTimer);
    state.radar.vectorRadarRevealTimer = null;
}
function removeRadarVectorLayer() {
    const map = state.radar.vectorMap;
    clearRadarVectorRevealTimer();
    state.radar.vectorRadarToken += 1;
    state.radar.vectorRadarKey = '';
    if (!map)
        return;
    try {
        if (map.getLayer(RADAR_VECTOR_LAYER_ID))
            map.removeLayer(RADAR_VECTOR_LAYER_ID);
    }
    catch { }
    try {
        if (map.getSource(RADAR_VECTOR_SOURCE_ID))
            map.removeSource(RADAR_VECTOR_SOURCE_ID);
    }
    catch { }
}
function scheduleRadarVectorReveal(token, attempt = 0) {
    clearRadarVectorRevealTimer();
    state.radar.vectorRadarRevealTimer = setTimeout(() => {
        state.radar.vectorRadarRevealTimer = null;
        const map = state.radar.vectorMap;
        if (!map || token !== state.radar.vectorRadarToken || !map.getLayer(RADAR_VECTOR_LAYER_ID))
            return;
        let loaded = false;
        try {
            loaded = Boolean(map.isSourceLoaded?.(RADAR_VECTOR_SOURCE_ID));
        }
        catch { }
        if (!loaded && attempt < 30) {
            scheduleRadarVectorReveal(token, attempt + 1);
            return;
        }
        try {
            map.setPaintProperty(RADAR_VECTOR_LAYER_ID, 'raster-opacity', state.radar.opacity);
            map.triggerRepaint?.();
        }
        catch { }
    }, attempt ? 70 : 0);
}
function syncRadarVectorLayer({ force = false } = {}) {
    const map = state.radar.vectorMap;
    if (!map || !state.radar.vectorMapReady || !map.isStyleLoaded?.())
        return false;
    if (state.radar.vectorRadarFailed && Date.now() < state.radar.vectorRadarRetryAt)
        return false;
    if (state.radar.mode !== 'live' || (state.radar.presentationLayer && state.radar.presentationLayer !== 'radar')) {
        removeRadarVectorLayer();
        return false;
    }
    const frame = state.radar.liveFrames[state.radar.index];
    const template = radarVectorTileTemplate(frame);
    if (!template) {
        removeRadarVectorLayer();
        return false;
    }
    const key = template;
    try {
        state.radar.vectorRadarFailed = false;
        state.radar.vectorRadarRetryAt = 0;
        $('#radar-map')?.classList.remove("vector-radar-fallback");
        const existingSource = map.getSource(RADAR_VECTOR_SOURCE_ID);
        const existingLayer = map.getLayer(RADAR_VECTOR_LAYER_ID);
        if (!existingSource || !existingLayer) {
            if (existingLayer)
                map.removeLayer(RADAR_VECTOR_LAYER_ID);
            if (existingSource)
                map.removeSource(RADAR_VECTOR_SOURCE_ID);
            map.addSource(RADAR_VECTOR_SOURCE_ID, {
                type: 'raster',
                tiles: [template],
                tileSize: 256,
                minzoom: 0,
                maxzoom: 7,
                attribution: meteonexaText('provider.librewxr')
            });
            const firstSymbol = map.getStyle()?.layers?.find(layer => layer.type === 'symbol')?.id;
            map.addLayer({
                id: RADAR_VECTOR_LAYER_ID,
                type: 'raster',
                source: RADAR_VECTOR_SOURCE_ID,
                paint: {
                    'raster-opacity': 0,
                    'raster-fade-duration': 0,
                    'raster-resampling': 'linear'
                }
            }, firstSymbol);
            state.radar.vectorRadarKey = key;
            const token = ++state.radar.vectorRadarToken;
            scheduleRadarVectorReveal(token);
            return true;
        }
        if (force || state.radar.vectorRadarKey !== key) {
            map.setPaintProperty(RADAR_VECTOR_LAYER_ID, 'raster-opacity', 0);
            const source = map.getSource(RADAR_VECTOR_SOURCE_ID);
            if (typeof source?.setTiles === 'function')
                source.setTiles([template]);
            else {
                map.removeLayer(RADAR_VECTOR_LAYER_ID);
                map.removeSource(RADAR_VECTOR_SOURCE_ID);
                state.radar.vectorRadarKey = '';
                return syncRadarVectorLayer({ force: true });
            }
            state.radar.vectorRadarKey = key;
            const token = ++state.radar.vectorRadarToken;
            scheduleRadarVectorReveal(token);
        }
        else {
            map.setPaintProperty(RADAR_VECTOR_LAYER_ID, 'raster-opacity', state.radar.opacity);
        }
        return true;
    }
    catch (error) {
        console.warn(meteonexaText("radar.vector_radar_layer_unavailable_using_fallback_renderer"), error);
        removeRadarVectorLayer();
        return false;
    }
}
function scheduleForecastRadarDraw() {
    if (state.radar.forecastRenderRaf)
        return;
    state.radar.forecastRenderRaf = requestAnimationFrame(() => {
        state.radar.forecastRenderRaf = null;
        if (state.radar.mode === 'forecast')
            drawForecastRadarLayer();
    });
}
function resizeRadarVectorMap(force = false) {
    const map = state.radar.vectorMap;
    const root = $('#radar-world-map');
    if (!map || !root)
        return false;
    const rect = root.getBoundingClientRect();
    const width = Math.max(1, Math.round(rect.width));
    const height = Math.max(1, Math.round(rect.height));
    const changed = force || Math.abs(width - state.radar.vectorSize.width) > 1 || Math.abs(height - state.radar.vectorSize.height) > 1;
    if (!changed)
        return false;
    state.radar.vectorSize = { width, height };
    try {
        map.resize();
        map.triggerRepaint?.();
        return true;
    }
    catch (error) {
        console.warn(meteonexaText('log.map.resize'), error);
        return false;
    }
}
function scheduleRadarVectorRecovery(reason = '') {
    if (state.radar.vectorRecoveryTimer || state.radar.vectorRecoveryAttempts >= 3)
        return;
    state.radar.vectorRecoveryTimer = setTimeout(() => {
        state.radar.vectorRecoveryTimer = null;
        const root = $('#radar-world-map');
        const previousMap = state.radar.vectorMap;
        state.radar.vectorMap = null;
        state.radar.vectorMapReady = false;
        state.radar.vectorMapInitializing = false;
        state.radar.vectorCameraKey = '';
        state.radar.vectorRadarKey = '';
        state.radar.vectorRadarToken += 1;
        clearRadarVectorRevealTimer();
        state.radar.vectorSize = { width: 0, height: 0 };
        const radarContainer = $('#radar-map');
        radarContainer?.classList.remove('vector-map-ready', 'map-fallback-active');
        radarContainer?.classList.add('map-initializing');
        clearRadarFallbackCanvas();
        try {
            previousMap?.remove?.();
        }
        catch { }
        root?.replaceChildren();
        state.radar.vectorRecoveryAttempts += 1;
        console.warn(meteonexaText('log.map.restart'), reason || String(state.radar.vectorRecoveryAttempts));
        initRadarVectorMap();
        scheduleRadarInitialFallback(5000);
    }, 320);
}
function clearRadarInitialFallbackTimer() {
    if (!state.radar.vectorFallbackTimer)
        return;
    clearTimeout(state.radar.vectorFallbackTimer);
    state.radar.vectorFallbackTimer = null;
}
function clearRadarFallbackCanvas() {
    const canvas = $('#radar-base-canvas');
    if (canvas) {
        const context = canvas.getContext('2d');
        context?.clearRect(0, 0, canvas.width, canvas.height);
    }
    $('.radar-tiles', $('#radar-map'))?.replaceChildren();
}
function activateRadarInitialFallback(reason = '') {
    const container = $('#radar-map');
    if (!container || state.radar.vectorMapReady)
        return;
    clearRadarInitialFallbackTimer();
    container.classList.remove('map-initializing');
    container.classList.add('map-fallback-active');
    if (reason)
        console.warn(meteonexaText("radar.activateradarinitialfallback.fallback_map_enabled"), reason);
    loadRadarBaseData();
    loadRadarAdminData();
    renderRadarMap();
}
function scheduleRadarInitialFallback(delay = 5000) {
    clearRadarInitialFallbackTimer();
    state.radar.vectorFallbackTimer = setTimeout(() => {
        state.radar.vectorFallbackTimer = null;
        if (!state.radar.vectorMapReady)
            activateRadarInitialFallback(meteonexaText("radar.scheduleradarinitialfallback.loading_timeout"));
    }, delay);
}
function initRadarVectorMap() {
    if (state.radar.vectorMap || state.radar.vectorMapInitializing || state.radar.vectorMapFailed)
        return;
    const root = $('#radar-world-map');
    if (!root || !window.maplibregl || !CONFIG.OPENFREEMAP_STYLE) {
        state.radar.vectorMapFailed = true;
        queueMicrotask(() => activateRadarInitialFallback(meteonexaText("radar.initradarvectormap.map_engine_unavailable")));
        return;
    }
    state.radar.vectorMapInitializing = true;
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    try {
        const map = new window.maplibregl.Map({
            container: root,
            style: CONFIG.OPENFREEMAP_STYLE,
            center: [center.lon, center.lat],
            zoom: state.radar.zoom,
            minZoom: 1,
            maxZoom: 10,
            interactive: true,
            attributionControl: false,
            renderWorldCopies: true,
            fadeDuration: 0,
            pitchWithRotate: false,
            dragRotate: false,
            antialias: false,
            transformRequest: (url) => {
                try {
                    const parsed = new URL(url, location.href);
                    if (/librewxr\.net$|openfreemap\.org$/i.test(parsed.hostname)) {
                        parsed.searchParams.set('meteonexa_nocache', String(state.radar.requestNonce || Date.now()));
                        return { url: parsed.toString(), credentials: 'omit' };
                    }
                }
                catch { }
                return { url };
            }
        });
        state.radar.vectorMap = map;
        const markReady = () => {
            if (state.radar.vectorMap !== map)
                return;
            state.radar.vectorMapInitializing = false;
            state.radar.vectorMapReady = true;
            state.radar.vectorRecoveryAttempts = 0;
            clearRadarInitialFallbackTimer();
            const radarContainer = $('#radar-map');
            radarContainer?.classList.remove('map-initializing', 'map-fallback-active');
            radarContainer?.classList.add('vector-map-ready');
            clearRadarFallbackCanvas();
            resizeRadarVectorMap(true);
            syncRadarVectorMap({ force: true });
            syncRadarVectorLayer({ force: true });
            drawRadarBaseMap();
        };
        map.on('load', markReady);
        map.on('styledata', () => {
            if (!state.radar.vectorMapReady && map.isStyleLoaded?.()) {
                markReady();
                return;
            }
            if (state.radar.vectorMapReady && state.radar.mode === 'live' && map.isStyleLoaded?.()
                && (!map.getSource(RADAR_VECTOR_SOURCE_ID) || !map.getLayer(RADAR_VECTOR_LAYER_ID))) {
                syncRadarVectorLayer({ force: true });
            }
        });
        map.on('idle', () => {
            if (state.radar.vectorMap !== map)
                return;
            $('#radar-map')?.classList.remove('map-render-pending');
        });
        try {
            map.dragRotate?.disable?.();
            map.touchZoomRotate?.disableRotation?.();
            map.boxZoom?.disable?.();
        }
        catch { }
        const syncStateFromMap = () => {
            if (state.radar.vectorMap !== map)
                return;
            const centerNow = map.getCenter();
            state.radar.center = { lat: clamp(centerNow.lat, -85, 85), lon: centerNow.lng };
            state.radar.zoom = clamp(map.getZoom(), 1, 10);
            state.radar.vectorCameraKey = radarVectorCameraKey(state.radar.center, state.radar.zoom);
            if (state.radar.mode === 'forecast')
                scheduleForecastRadarDraw();
        };
        map.on('movestart', () => $('#radar-map')?.classList.add('is-panning'));
        map.on('zoomstart', () => $('#radar-map')?.classList.add('is-zooming'));
        map.on('move', () => {
            if (state.radar.vectorMoveRaf)
                return;
            state.radar.vectorMoveRaf = requestAnimationFrame(() => {
                state.radar.vectorMoveRaf = null;
                syncStateFromMap();
                if (state.radar.vectorRadarFailed && state.radar.mode === 'live')
                    scheduleRadarRender(40);
            });
        });
        map.on('moveend', () => {
            syncStateFromMap();
            $('#radar-map')?.classList.remove('is-panning', 'map-render-pending');
            drawRadarBaseMap();
            if (state.radar.mode === 'forecast')
                drawForecastRadarLayer();
        });
        map.on('zoomend', () => {
            syncStateFromMap();
            $('#radar-map')?.classList.remove('is-zooming', 'map-render-pending');
            if (state.radar.mode === 'forecast')
                drawForecastRadarLayer();
        });
        map.on('error', event => {
            const message = String(event?.error?.message || event?.error || '');
            const sourceId = String(event?.sourceId || event?.source?.id || '');
            if (sourceId === RADAR_VECTOR_SOURCE_ID || /librewxr\.net/i.test(message)) {
                state.radar.vectorRadarFailed = true;
                state.radar.vectorRadarRetryAt = Date.now() + 30000;
                $('#radar-map')?.classList.add("vector-radar-fallback");
                removeRadarVectorLayer();
                scheduleRadarRender(0);
                return;
            }
            if (/webgl|context lost|context was lost/i.test(message))
                scheduleRadarVectorRecovery(message);
            else if (!state.radar.vectorMapReady && event?.error)
                console.warn(meteonexaText("radar.syncstatefrommap.loading_world_map"), event.error);
        });
        const canvas = map.getCanvas?.();
        canvas?.addEventListener('webglcontextlost', event => {
            event.preventDefault();
            if (state.radar.vectorMap !== map)
                return;
            state.radar.vectorMapReady = false;
            const radarContainer = $('#radar-map');
            radarContainer?.classList.remove('vector-map-ready', 'map-fallback-active');
            radarContainer?.classList.add('map-initializing');
            clearRadarFallbackCanvas();
            scheduleRadarInitialFallback(5000);
            scheduleRadarVectorRecovery(meteonexaText('log.map.webgl_lost'));
        }, { passive: false });
        canvas?.addEventListener('webglcontextrestored', () => {
            if (state.radar.vectorMap !== map)
                return;
            state.radar.vectorMapReady = true;
            $('#radar-map')?.classList.add('vector-map-ready');
            resizeRadarVectorMap(true);
            syncRadarVectorMap({ force: true });
            syncRadarVectorLayer({ force: true });
        });
        setTimeout(() => {
            if (state.radar.vectorMap === map && !state.radar.vectorMapReady) {
                state.radar.vectorMapInitializing = false;
                activateRadarInitialFallback(meteonexaText('log.map.not_ready'));
            }
        }, 12000);
    }
    catch (error) {
        state.radar.vectorMapInitializing = false;
        state.radar.vectorMapFailed = true;
        console.warn(meteonexaText("radar.world_map_unavailable_using_local_fallback"), error);
        activateRadarInitialFallback(error?.message || meteonexaText('log.map.init_failed'));
    }
}
function syncRadarVectorMap({ force = false, resize = false } = {}) {
    const map = state.radar.vectorMap;
    if (!map || !state.radar.vectorMapReady)
        return;
    if (map.isMoving?.() && !force)
        return;
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    const zoom = clamp(Number(state.radar.zoom), 1, 10);
    const key = radarVectorCameraKey(center, zoom);
    try {
        if (resize)
            resizeRadarVectorMap(true);
        if (force || state.radar.vectorCameraKey !== key) {
            $('#radar-map')?.classList.add('map-render-pending');
            map.jumpTo({ center: [center.lon, center.lat], zoom, bearing: 0, pitch: 0 });
            state.radar.vectorCameraKey = key;
        }
        map.triggerRepaint?.();
        syncRadarVectorLayer();
        if (state.radar.vectorRepaintTimer)
            clearTimeout(state.radar.vectorRepaintTimer);
        state.radar.vectorRepaintTimer = setTimeout(() => {
            state.radar.vectorRepaintTimer = null;
            if (state.radar.vectorMap !== map || !state.radar.vectorMapReady)
                return;
            try {
                map.triggerRepaint?.();
                const canvas = map.getCanvas?.();
                if (!canvas || canvas.width < 2 || canvas.height < 2)
                    resizeRadarVectorMap(true);
            }
            catch (error) {
                scheduleRadarVectorRecovery(error?.message || meteonexaText('log.map.redraw_failed'));
            }
        }, 120);
    }
    catch (error) {
        console.warn(meteonexaText("radar.unable_sync_world_map"), error);
        scheduleRadarVectorRecovery(error?.message || meteonexaText("radar.syncradarvectormap.sync_failed"));
    }
}

    return Object.freeze({
        clearRadarVectorTimers, radarVectorCameraKey, radarVectorTileTemplate, clearRadarVectorRevealTimer, removeRadarVectorLayer, scheduleRadarVectorReveal, syncRadarVectorLayer, scheduleForecastRadarDraw, resizeRadarVectorMap, scheduleRadarVectorRecovery, clearRadarInitialFallbackTimer, clearRadarFallbackCanvas, activateRadarInitialFallback, scheduleRadarInitialFallback, initRadarVectorMap, syncRadarVectorMap
    });
}
