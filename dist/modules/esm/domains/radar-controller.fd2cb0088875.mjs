import { createRadarVectorMap } from './radar-vector-map.mjs';
import { createRadarRenderer } from './radar-renderer.mjs';
import { createRadarPlayback } from './radar-playback.mjs';

const PROVIDES = Object.freeze(['radarController']);
export const dependencies = Object.freeze(['advanced', 'radar', 'security']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
            function create(deps) {
                const {
                    state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
                    normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
                    loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
                    persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
                    showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
                    lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion
                } = deps;

                const runtime = {};
                const vector = createRadarVectorMap({
                    window, state, CONFIG, $, clamp, meteonexaText, drawRadarBaseMap,
                    loadRadarBaseData, loadRadarAdminData,
                    renderRadarMap: (...args) => runtime.renderRadarMap(...args),
                    drawForecastRadarLayer: (...args) => runtime.drawForecastRadarLayer(...args),
                    scheduleRadarRender: (...args) => runtime.scheduleRadarRender(...args)
                });
                Object.assign(runtime, vector);
                const renderer = createRadarRenderer({
                    window, state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp,
                    persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, showToast,
                    drawRadarBaseMap, lonLatToWorld, worldToLonLat, radarTileUrl,
                    syncRadarVectorMap: vector.syncRadarVectorMap,
                    syncRadarVectorLayer: vector.syncRadarVectorLayer,
                    removeRadarVectorLayer: vector.removeRadarVectorLayer,
                    ensureRadar: (...args) => runtime.ensureRadar(...args),
                    setRadarFrame: (...args) => runtime.setRadarFrame(...args)
                });
                Object.assign(runtime, renderer);
                const playback = createRadarPlayback({
                    state, CONFIG, STORAGE, $, $$, t, meteonexaText, escapeHTML, clamp, debounce,
                    normalizeLocation, withLoader, saveJSON, addRecent, updateSelectedLocationUI, fullLocationLabel,
                    loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage,
                    persistLocalSettings, fetchJSON, currentHourlyIndex, sleep, appLocale, formatLocationLocalTime,
                    showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData,
                    lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion,
                    initRadarMap: (...args) => runtime.initRadarMap(...args),
                    activeRadarFrames: renderer.activeRadarFrames,
                    setRadarStatus: renderer.setRadarStatus,
                    updateRadarModeUI: renderer.updateRadarModeUI,
                    renderRadarMap: renderer.renderRadarMap,
                    drawForecastRadarLayer: renderer.drawForecastRadarLayer,
                    loadForecastRadar: renderer.loadForecastRadar,
                    loadLiveRadar: renderer.loadLiveRadar,
                    updateRadarStats: renderer.updateRadarStats,
                    scheduleRadarRefresh: renderer.scheduleRadarRefresh,
                    syncRadarVectorMap: vector.syncRadarVectorMap
                });
                Object.assign(runtime, playback);
                const {
                    clearRadarFallbackCanvas, initRadarVectorMap, activateRadarInitialFallback,
                    scheduleRadarInitialFallback, syncRadarVectorMap, resizeRadarVectorMap
                } = vector;
                const {
                    renderRadarCameraPreview, scheduleRadarRender, renderRadarMap
                } = renderer;
                const { ensureRadar, zoomRadar } = playback;

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

                runtime.initRadarMap = initRadarMap;

                return Object.freeze({
                    syncRadarVectorLayer: vector.syncRadarVectorLayer,
                    removeRadarVectorLayer: vector.removeRadarVectorLayer,
                    selectRadarLocation,
                    performRadarCitySearch,
                    useGpsFromRadar,
                    setRadarMode: renderer.setRadarMode,
                    renderRadarMap: renderer.renderRadarMap,
                    drawForecastRadarLayer: renderer.drawForecastRadarLayer,
                    ensureRadar: playback.ensureRadar,
                    setRadarFrame: playback.setRadarFrame,
                    stopRadarAnimation: playback.stopRadarAnimation,
                    toggleRadarAnimation: playback.toggleRadarAnimation,
                    stepRadar: playback.stepRadar,
                    zoomRadar: playback.zoomRadar
                });
            }

            provided.radarController = Object.freeze({ create });
        }
    });
}

export const serviceNames = PROVIDES;
