const PROVIDES = Object.freeze(['radarController']);
export const dependencies = Object.freeze(['advanced', 'radar', 'security']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            function create(deps) {
                const {
                    state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
                    normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
                    loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
                    persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
                    showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
                    lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion
                } = deps;
        
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
                        deviceId: deps.security?.deviceId || '', exp: String(frame.tileExpires), frameSig: frame.frameSignature
                    });
                    // Keep MapLibre placeholders unescaped after URLSearchParams encoding.
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
                    if (state.radar.mode !== 'live') {
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
                async function selectRadarLocation(rawResult) {
                    const locationData = normalizeLocation(rawResult);
                    await withLoader("" + meteonexaText("radar.selectradarlocation.opening_city"), meteonexaText("radar.selectradarlocation.centering_radar_value", { location: locationData.name }), async () => {
                        state.location = locationData;
                        state.weather = null;
                        state.air = null;
                        state.radar.center = { lat: Number(locationData.latitude), lon: Number(locationData.longitude) };
                        state.radar.zoom = 7;
                        state.radar.loaded = false;
                        state.radar.forecastFrames = [];
                        state.radar.forecastGrid = [];
                        saveJSON(STORAGE.location, state.location);
                        saveJSON(STORAGE.weather, null);
                        saveJSON(STORAGE.air, null);
                        addRecent(state.location);
                        updateSelectedLocationUI();
                        $('#radar-city-search').value = fullLocationLabel(locationData);
                        $('#radar-city-results').innerHTML = '';
                        await loadWeather({ force: true, silent: true });
                        await ensureRadar(true, { silent: true });
                        renderAll();
                        deps.advanced?.locationChanged?.();
                        renderRadarMap();
                    }, 520);
                    showToast("" + meteonexaText("radar.selectradarlocation.radar_recentered"), fullLocationLabel(locationData), 'success');
                }
                async function performRadarCitySearch() {
                    const input = $('#radar-city-search');
                    const target = $('#radar-city-results');
                    const query = input?.value.trim() || '';
                    if (query.length < 2) {
                        showToast("" + meteonexaText("locations.performcommandsearch.search_too_short"), "" + meteonexaText("locations.enter_at_least_two_characters"), 'warning');
                        input?.focus();
                        return;
                    }
                    target?.classList.add('open');
                    await searchCities(query, target, selectRadarLocation, { compact: true });
                }
                async function useGpsFromRadar() {
                    const button = $('#radar-city-gps');
                    button?.classList.add('loading');
                    button?.setAttribute('aria-busy', 'true');
                    try {
                        const locationData = await getCurrentLocationData();
                        await selectRadarLocation(locationData);
                    }
                    catch (error) {
                        showToast("" + meteonexaText("locations.detectlocation.location_unavailable"), locationErrorMessage(error), 'error');
                    }
                    finally {
                        button?.classList.remove('loading');
                        button?.removeAttribute('aria-busy');
                    }
                }
                function initRadarMap() {
                    if (state.radar.initialized)
                        return;
                    const container = $('#radar-map');
                    const tiles = $('.radar-tiles', container);
                    const canvas = $('#radar-forecast-canvas');
                    state.radar.map = { container, tiles, canvas, baseCanvas: $('#radar-base-canvas') };
                    state.radar.center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
                    container.classList.add('map-initializing');
                    container.classList.remove('map-fallback-active', 'vector-map-ready');
                    clearRadarFallbackCanvas();
                    initRadarVectorMap();
                    if (state.radar.vectorMapFailed)
                        activateRadarInitialFallback(meteonexaText("radar.initradarvectormap.map_engine_unavailable"));
                    else
                        scheduleRadarInitialFallback(5000);
                    container.addEventListener('pointerdown', event => {
                        if (state.radar.vectorMapReady)
                            return;
                        if (event.button !== 0 && event.pointerType === 'mouse')
                            return;
                        if (event.target.closest('button,input,select,.choices'))
                            return;
                        container.setPointerCapture?.(event.pointerId);
                        container.classList.add('is-panning');
                        state.radar.drag = { id: event.pointerId, x: event.clientX, y: event.clientY, center: { ...state.radar.center } };
                    });
                    container.addEventListener('pointermove', event => {
                        if (state.radar.vectorMapReady)
                            return;
                        if (!state.radar.drag || state.radar.drag.id !== event.pointerId)
                            return;
                        const startWorld = lonLatToWorld(state.radar.drag.center.lat, state.radar.drag.center.lon, state.radar.zoom);
                        const center = worldToLonLat(startWorld.x - (event.clientX - state.radar.drag.x), startWorld.y - (event.clientY - state.radar.drag.y), state.radar.zoom);
                        state.radar.center = { lat: clamp(center.lat, -85, 85), lon: center.lon };
                        renderRadarCameraPreview();
                        scheduleRadarRender(90);
                    });
                    const stopDrag = event => {
                        if (state.radar.drag?.id !== event.pointerId)
                            return;
                        state.radar.drag = null;
                        container.classList.remove('is-panning');
                        try {
                            container.releasePointerCapture?.(event.pointerId);
                        }
                        catch { }
                        syncRadarVectorMap({ force: true });
                        scheduleRadarRender(0);
                    };
                    container.addEventListener('pointerup', stopDrag);
                    container.addEventListener('pointercancel', stopDrag);
                    container.addEventListener('lostpointercapture', event => {
                        if (state.radar.drag?.id === event.pointerId) {
                            state.radar.drag = null;
                            container.classList.remove('is-panning');
                            scheduleRadarRender(0);
                        }
                    });
                    container.addEventListener('wheel', event => {
                        if (state.radar.vectorMapReady)
                            return;
                        event.preventDefault();
                        state.radar.wheelAccumulator += event.deltaY;
                        if (Math.abs(state.radar.wheelAccumulator) < 34)
                            return;
                        const direction = state.radar.wheelAccumulator < 0 ? 1 : -1;
                        state.radar.wheelAccumulator = 0;
                        if (state.radar.wheelTimer)
                            return;
                        zoomRadar(direction, false);
                        state.radar.wheelTimer = setTimeout(() => { state.radar.wheelTimer = null; }, 110);
                    }, { passive: false });
                    new ResizeObserver(debounce(() => {
                        resizeRadarVectorMap(true);
                        scheduleRadarRender(0);
                    }, 120)).observe(container);
                    state.radar.initialized = true;
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
                    state.radar.frames = activeRadarFrames();
                    state.radar.index = Math.max(0, state.radar.frames.length - 1);
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
                            syncRadarVectorLayer();
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
                async function ensureRadar(force = false, options = {}) {
                    initRadarMap();
                    renderRadarMap();
                    if (state.radar.loading)
                        return;
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
                        const [liveResult, forecastResult] = await Promise.allSettled([loadLiveRadar(), loadForecastRadar()]);
                        const liveOk = liveResult.status === 'fulfilled' && liveResult.value;
                        const forecastOk = forecastResult.status === 'fulfilled' && forecastResult.value;
                        state.radar.loaded = true;
                        $('#radar-empty').hidden = true;
                        let desired = state.settings.radarMode || 'live';
                        if (desired === 'forecast' && !forecastOk)
                            desired = liveOk ? 'live' : 'forecast';
                        state.radar.mode = desired;
                        state.radar.frames = activeRadarFrames();
                        state.radar.index = Math.max(0, state.radar.frames.length - 1);
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
                    if (state.radar.vectorMapReady && state.radar.mode === 'live')
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
                    syncRadarVectorLayer,
                    removeRadarVectorLayer,
                    selectRadarLocation,
                    performRadarCitySearch,
                    useGpsFromRadar,
                    setRadarMode,
                    renderRadarMap,
                    drawForecastRadarLayer,
                    ensureRadar,
                    setRadarFrame,
                    stopRadarAnimation,
                    toggleRadarAnimation,
                    stepRadar,
                    zoomRadar
                });
            }
        
            provided.radarController = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
