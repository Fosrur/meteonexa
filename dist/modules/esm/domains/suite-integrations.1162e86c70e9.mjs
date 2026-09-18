export const serviceNames = Object.freeze(['suiteIntegrations']);
export const dependencies = Object.freeze(['runtimeApi','security','suiteSupport','suite','advanced','auth','controls','guestAccess','uiVisibility','loader','datePicker']);

function factory(window, deps, provided) {
    const CONFIG = window.METEONEXA_CONFIG;
    if (!CONFIG) throw new Error('METEONEXA_CONFIG_NOT_LOADED');
    const APP_RUNTIME = deps.runtimeApi.get();
    const meteonexaText = window.meteonexaText;
    const { showToast, withLoader, loadWeather, syncEnhancedSelect, updateThreshold, appLocale, temperature, t } = APP_RUNTIME;
    const state = APP_RUNTIME.getState();
    const SECURITY = deps.security;
    if (!SECURITY) throw new Error('METEONEXA_SECURITY_NOT_LOADED');
    const serviceFacade = Object.freeze({ get(name) { return ({
        advanced: deps.advanced, auth: deps.auth, controls: deps.controls, guestAccess: deps.guestAccess,
        uiVisibility: deps.uiVisibility, loader: deps.loader, datePicker: deps.datePicker, suite: deps.suite
    })[name]; } });
    const { BUILD, q, qa, n: num, clamp, safe, currentLocale, apiMessage, toast, loader, deviceId, isGuest, fetchJson: supportFetchJson, localTime, directionName } = deps.suiteSupport.create({
        CONFIG, SERVICES: serviceFacade, SECURITY, state, appLocale, temperature, t, showToast, withLoader, meteonexaText: window.meteonexaText
    });
    const fetchJson = (url, options = {}) => supportFetchJson(url, { ...options, timeout: options.timeout || 25000 });
    const API = Object.freeze({
        radarRegister: 'api/radar/register.php', radarArchive: 'api/radar/archive.php', lightning: 'api/lightning/live.php',
        pushKey: 'api/push/public-key.php', pushSubscribe: 'api/push/subscribe.php', pushTest: 'api/push/test.php', pushUnsubscribe: 'api/push/unsubscribe.php',
        netatmoStatus: 'api/netatmo/status.php', netatmoStations: 'api/netatmo/stations.php', netatmoStart: 'api/netatmo/start.php', netatmoDisconnect: 'api/netatmo/disconnect.php'
    });
    const setText = (selector, value) => { const node = q(selector); if (node) node.textContent = value; return node; };
    const setTrustedHtml = (selector, value) => { const node = q(selector); if (node) node.innerHTML = value; return node; };
    const suiteState = { lightningTimer: null, netatmo: [], archive: [], archiveLocationId: '', archiveMap: null, archiveMapReady: null, archiveTimer: null, archiveIndex: 0, archiveFitted: false, archiveRenderToken: 0, archiveSpeed: 720, archiveOpacity: 72, refreshRequest: null, refreshedAt: 0, refreshLocationKey: '' };
    const suite = suiteState;
    const guestNotice = () => deps.guestAccess?.notify?.();
    const uiVisible = featureKey => deps.uiVisibility?.isVisible?.(featureKey) !== false;
    const dateValue = (date = new Date()) => { const d = new Date(date); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
    const locationData = () => ({ name: [state?.location?.name, state?.location?.admin1].filter(Boolean).join(', ') || String(window.meteonexaText('locations.selectonboardinglocation.location_selected')), latitude: num(state?.location?.latitude), longitude: num(state?.location?.longitude) });

    function ensureUI() {
        // Optical-flow and synoptic diagnostics were experimental and could
        // remain indefinitely pending on shared hosting. They are removed from
        // the product instead of leaving non-deterministic UI/background work.
        q('#suite-optical-panel')?.remove();
        q('#suite-synoptic-panel')?.remove();
        const archiveVisible = !isGuest() && uiVisible('section.radar.archive');
        if (!archiveVisible) {
            destroyArchiveMap();
            q('#suite-radar-archive-panel')?.remove();
        }
        if (archiveVisible && !q('#suite-radar-archive-panel')) {
            q('#page-radar')?.insertAdjacentHTML('beforeend', "" + ("" + "<article id=\"suite-radar-archive-panel\" class=\"glass-panel suite-archive-panel\"><div class=\"panel-title\"><div><span class=\"section-kicker\">" + safe(meteonexaText("suite.ensureui.radar_archive")) + "</span><h2>" + safe(meteonexaText("suite.ensureui.review_day")) + "</h2><p>" + safe(meteonexaText("suite.ensureui.radar_frames_progressively_retained_up_366_days_after")) + "</p></div><span id=\"suite-archive-status\" class=\"soft-badge\">" + safe(meteonexaText('radar.archive.status.progressive')) + "</span></div><div class=\"suite-archive-toolbar\"><label class=\"meteo-date-field suite-archive-date-field\"><span>") + safe(meteonexaText('radar.archive.date')) + "</span><input id=\"suite-archive-date\" type=\"hidden\" value=\"" + dateValue() + "\"><button class=\"meteo-date-trigger\" type=\"button\" data-meteo-date-target=\"suite-archive-date\"><svg><use href=\"#i-calendar\"/></svg><span data-meteo-date-label>" + safe(new Intl.DateTimeFormat(currentLocale()).format(new Date(`${dateValue()}T12:00:00`))) + ("" + "</span><svg class=\"date-trigger-chevron\"><use href=\"#i-chevron\"/></svg></button></label><div class=\"suite-archive-actions\"><button id=\"suite-archive-load\" class=\"button outline-button\" type=\"button\"><svg><use href=\"#i-refresh\"/></svg><span>" + safe(meteonexaText("suite.ensureui.load")) + "</span></button><button id=\"suite-archive-register\" class=\"button primary-button\" type=\"button\"><svg><use href=\"#i-radar\"/></svg><span>" + safe(meteonexaText("suite.ensureui.archive_location")) + "</span></button></div></div><div id=\"suite-archive-view\" class=\"suite-archive-view\"><div class=\"advanced-empty\">" + safe(meteonexaText("suite.ensureui.archive_starts_first_server_activation_stores_up_366")) + "</div></div></article>"));
        }
        if (!q('#suite-netatmo-panel')) {
            q('#page-devices .devices-grid')?.insertAdjacentHTML('afterend', "" + "<article id=\"suite-netatmo-panel\" class=\"glass-panel suite-netatmo-panel suite-service-panel\"><div class=\"panel-title\"><div><span class=\"section-kicker\">" + safe(meteonexaText('protocol.netatmo_oauth')) + "</span><h2>" + safe(meteonexaText('netatmo.panel.title')) + "</h2></div><span id=\"suite-netatmo-status\" class=\"soft-badge\">" + safe(meteonexaText("suite.ensureui.checking")) + "</span></div><div id=\"suite-netatmo-content\" class=\"suite-netatmo-content\"><div class=\"advanced-empty\">" + safe(meteonexaText('netatmo.checking')) + "</div></div><div class=\"suite-actions\"><button id=\"suite-netatmo-connect\" class=\"button primary-button\" type=\"button\">" + safe(meteonexaText('netatmo.connect')) + "</button><button id=\"suite-netatmo-refresh\" class=\"button outline-button\" type=\"button\">" + safe(meteonexaText("suite.ensureui.refresh_data")) + "</button><button id=\"suite-netatmo-disconnect\" class=\"button text-button\" type=\"button\">" + safe(meteonexaText('netatmo.disconnect')) + "</button></div></article>");
        }
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
        try { hideUiTooltip?.({ immediate: true }); } catch { }
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
                suite.archiveMap = new maplibregl.Map({
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
    function profileForPush() {
        try {
            return JSON.parse(localStorage.getItem('meteonexa_suite_alert_profile') || '{}');
        }
        catch {
            return {};
        }
    }
    function decodeKey(value) { const padding = '='.repeat((4 - value.length % 4) % 4), base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/'), raw = atob(base64); return Uint8Array.from([...raw].map(c => c.charCodeAt(0))); }
    async function configureWorker() {
        if (!('serviceWorker' in navigator))
            return;
        const reg = await navigator.serviceWorker.ready;
        const remotePush = Boolean(await reg.pushManager?.getSubscription?.());
        reg.active?.postMessage({ type: 'METEONEXA_CONFIGURE_BACKGROUND', payload: { deviceId, deviceKey: SECURITY.deviceKey, location: locationData(), thresholds: profileForPush(), weatherApi: CONFIG.WEATHER_API, language: state.settings.language, remotePush, serverAuthoritative: true } });
    }
    async function enableRemotePush() {
        if (isGuest()) { guestNotice(); throw new Error(meteonexaText('guest.login.required.copy')); }
        if (!('serviceWorker' in navigator) || !('PushManager' in window))
            throw new Error(meteonexaText('push.unsupported'));
        if (Notification.permission !== 'granted') {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted')
                throw new Error("" + meteonexaText("notifications.notification_permission_not_granted"));
        }
        const key = await fetchJson(API.pushKey), reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub)
            sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeKey(key.publicKey) });
        await fetchJson(API.pushSubscribe, { method: 'POST', body: { deviceId, subscription: sub.toJSON(), location: locationData(), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, language: state.settings.language, profile: profileForPush() } });
        await configureWorker();
        q('#suite-push-status') && (q('#suite-push-status').textContent = meteonexaText("suite.enableremotepush.remote_push_active"));
        const delivery = q('#notification-delivery-status'), note = q('#notification-delivery-note');
        if (delivery)
            delivery.textContent = meteonexaText('push.delivery.remote');
        if (note)
            note.textContent = meteonexaText('push.delivery.closed');
        return sub;
    }
    async function syncRemotePush() {
        if (isGuest()) return null;
        if (!('serviceWorker' in navigator) || !('PushManager' in window))
            return null;
        const reg = await navigator.serviceWorker.ready, sub = await reg.pushManager.getSubscription();
        if (!sub)
            return null;
        await fetchJson(API.pushSubscribe, { method: 'POST', body: { deviceId, subscription: sub.toJSON(), location: locationData(), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, language: state.settings.language, profile: profileForPush() } });
        await configureWorker();
        return sub;
    }
    async function testRemotePush() { await enableRemotePush(); await fetchJson(API.pushTest, { method: 'POST', body: { deviceId }, timeout: 25000 }); toast(meteonexaText('push.test.sent'), meteonexaText("suite.testremotepush.push_service_accepted_remote_notification")); }
    async function disableRemotePush() { const reg = await navigator.serviceWorker?.ready, sub = await reg?.pushManager?.getSubscription(); await sub?.unsubscribe(); await fetchJson(API.pushUnsubscribe, { method: 'POST', body: { deviceId } }).catch(() => { }); q('#suite-push-status') && (q('#suite-push-status').textContent = meteonexaText("suite.disableremotepush.disabled")); }
    async function inspectPush() {
        if (isGuest()) return;
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            const node = q('#suite-push-status');
            if (node)
                node.textContent = "" + meteonexaText("notifications.updatenotificationcenterui.not_supported");
            return;
        }
        const reg = await navigator.serviceWorker.ready, sub = await reg.pushManager.getSubscription();
        {
            const node = q('#suite-push-status');
            if (node)
                node.textContent = sub ? meteonexaText("suite.enableremotepush.remote_push_active") : meteonexaText("suite.inspectpush.inactive");
        }
        if (sub)
            await syncRemotePush();
        else
            await configureWorker();
    }
    async function refreshNetatmo(loadData = true) {
        if (isGuest()) return null;
        const statusNode = q('#suite-netatmo-status'), connectButton = q('#suite-netatmo-connect'), contentNode = q('#suite-netatmo-content');
        if (!statusNode || !connectButton || !contentNode)
            return null;
        const params = new URLSearchParams({ deviceId });
        try {
            const status = await fetchJson(`${API.netatmoStatus}?${params}`);
            statusNode.textContent = !status.configured ? meteonexaText('netatmo.credentials.missing') : status.connected ? meteonexaText('netatmo.connected') : meteonexaText('netatmo.disconnected');
            connectButton.disabled = !status.configured;
            if (!status.connected) {
                contentNode.innerHTML = `<div class="advanced-empty">${safe(status.configured ? meteonexaText('netatmo.connect.copy') : meteonexaText('netatmo.configure.copy'))}</div>`;
                return status;
            }
            if (loadData) {
                const data = await fetchJson(`${API.netatmoStations}?${params}`, { timeout: 30000 });
                suite.netatmo = data.stations || [];
                contentNode.innerHTML = suite.netatmo.length ? suite.netatmo.map(row => `<article><div><strong>${safe(row.name)}</strong><small>${safe(row.type)} · ${row.observedAt ? safe(localTime(row.observedAt)) : '--'}</small></div><span>${row.temperature != null ? `${Math.round(num(row.temperature) * 10) / 10}°C` : '--'}</span><span>${row.humidity != null ? safe(meteonexaText('netatmo.relative_humidity.inline', { value: Math.round(num(row.humidity)) })) : '--'}</span><span>${row.wind != null ? `${Math.round(num(row.wind))} km/h` : '--'}</span></article>`).join('') : "" + "<div class=\"advanced-empty\">" + safe(meteonexaText("suite.refreshnetatmo.no_netatmo_module_returned_by_account")) + "</div>";
            }
            return status;
        }
        catch (error) {
            statusNode.textContent = error.code === 'NETATMO_NOT_CONFIGURED' ? meteonexaText('netatmo.credentials.missing') : meteonexaText("suite.refreshnetatmo.error");
            contentNode.innerHTML = `<div class="advanced-empty">${safe(error.message)}</div>`;
            return null;
        }
    }
    async function connectNetatmo() { const returnUrl = `${location.pathname}#devices`; const data = await fetchJson(API.netatmoStart, { method: 'POST', body: { deviceId, return: returnUrl, language: state?.settings?.language || document.documentElement.lang || 'it' } }); if (data?.authorizeUrl) location.href = data.authorizeUrl; }
    async function disconnectNetatmo() { await fetchJson(API.netatmoDisconnect, { method: 'POST', body: { deviceId } }); await refreshNetatmo(false); }
    function bind() {
        q('#suite-archive-register')?.addEventListener('click', () => registerRadarArchive(true).catch(() => { }));
        q('#suite-archive-load')?.addEventListener('click', () => loadRadarArchive().catch(e => toast(meteonexaText("suite.bind.archive_unavailable"), e.message, 'warning')));
        q('#suite-archive-date')?.addEventListener('change', () => loadRadarArchive().catch(() => { }));
        q('#suite-push-enable')?.addEventListener('click', () => enableRemotePush().then(() => toast(meteonexaText("suite.enableremotepush.remote_push_active"), meteonexaText('push.backend.closed'))).catch(e => toast(meteonexaText("suite.bind.push_not_enabled"), e.message, 'warning')));
        q('#suite-push-test')?.addEventListener('click', () => testRemotePush().catch(e => toast(meteonexaText('push.test.failed'), e.message, 'warning')));
        q('#suite-push-disable')?.addEventListener('click', () => disableRemotePush().catch(() => { }));
        q('#notification-primary')?.addEventListener('click', () => setTimeout(() => {
            if (Notification.permission === 'granted')
                enableRemotePush().catch(() => { });
        }, 1000), true);
        document.addEventListener('click', event => {
            const layer = event.target.closest?.('[data-advanced-radar-layer]');
            if (layer && layer.dataset.advancedRadarLayer !== 'lightning') {
                clearInterval(suite.lightningTimer);
                const map = state?.radar?.vectorMap;
                if (map)
                    removeLightningLayers(map);
            }
            if (event.target.closest?.('[data-alert-profile]'))
                setTimeout(() => syncRemotePush().catch(() => { }), 700);
        }, true);
        document.addEventListener('change', event => {
            if (['threshold-rain', 'threshold-wind', 'threshold-heat', 'suite-cold', 'suite-pollen', 'suite-wave'].includes(event.target?.id))
                setTimeout(() => syncRemotePush().catch(() => { }), 500);
        });
        q('#suite-netatmo-connect')?.addEventListener('click', () => loader(meteonexaText('netatmo.connect'), meteonexaText('netatmo.checking'), connectNetatmo, 420).catch(e => toast(meteonexaText('provider.netatmo'), e.message, 'warning')));
        q('#suite-netatmo-refresh')?.addEventListener('click', () => loader(meteonexaText('suite.ensureui.refresh_data'), meteonexaText('netatmo.checking'), () => refreshNetatmo(true), 360));
        q('#suite-netatmo-disconnect')?.addEventListener('click', () => loader(meteonexaText('netatmo.disconnect'), meteonexaText('netatmo.checking'), disconnectNetatmo, 420).catch(e => toast(meteonexaText('provider.netatmo'), e.message, 'warning')));
        const old = q('[data-advanced-radar-layer="lightning"]');
        if (old) {
            const fresh = old.cloneNode(true);
            const oldLabel = meteonexaText('lightning.index');
            const newLabel = meteonexaText('lightning.live');
            [...fresh.childNodes].forEach(node => {
                if (node.nodeType === Node.TEXT_NODE && node.textContent?.includes(oldLabel)) node.textContent = node.textContent.replace(oldLabel, newLabel);
                node.querySelectorAll?.('*').forEach(child => { if (child.childNodes.length === 1 && child.firstChild?.nodeType === Node.TEXT_NODE && child.textContent?.includes(oldLabel)) child.textContent = child.textContent.replace(oldLabel, newLabel); });
            });
            old.replaceWith(fresh);
            fresh.addEventListener('click', () => {
                clearInterval(suite.lightningTimer);
                showLiveLightning().then(count => toast(meteonexaText('lightning.live'), meteonexaText("suite.bind.value_lightning_strikes_detected_last_5_minutes", { p0: count }))).catch(e => toast(meteonexaText("suite.bind.live_lightning_unavailable"), e.message, 'warning'));
                suite.lightningTimer = setInterval(() => {
                    if (q('#page-radar')?.classList.contains('active') && fresh.classList.contains('active'))
                        showLiveLightning().catch(() => { });
                }, 60000);
            });
        }
    }
    async function refreshSuite(force = false) {
        if (!state?.weather)
            return;
        const key = `${Number(state.location?.latitude).toFixed(4)}:${Number(state.location?.longitude).toFixed(4)}`;
        if (suite.refreshRequest)
            return suite.refreshRequest;
        // The primary advanced engine and the lifecycle wrapper can both reach this
        // function during navigation. Keep one diagnostics hydration per location.
        if (!force && suite.refreshLocationKey === key && Date.now() - suite.refreshedAt < 3000)
            return;
        suite.refreshRequest = (async () => {
            ensureUI();
            // Advanced now has a single deterministic forecast engine. The removed
            // experimental optical/synoptic panels no longer start background tasks.
            if (!isGuest()) registerRadarArchive(false).catch(() => { });
            suite.refreshLocationKey = key;
            suite.refreshedAt = Date.now();
        })();
        try {
            return await suite.refreshRequest;
        }
        finally {
            suite.refreshRequest = null;
        }
    }
    async function onPage(page) {
        if (page === 'advanced')
            refreshSuite(false).catch(error => console.warn('ADVANCED_DIAGNOSTICS_BACKGROUND_FAILED', error));
        if (page === "radar" && !isGuest()) {
            await registerRadarArchive(false).catch(() => { });
            await loadRadarArchive().catch(() => { });
        }
        if (page === 'devices') {
            if (q('#suite-push-panel'))
                await inspectPush();
            if (q('#suite-netatmo-panel'))
                await refreshNetatmo(true);
        }
    }
    let unregisterAdvancedLifecycle = null;
    function patchLifecycle() {
        const advanced = deps.advanced;
        if (!advanced || unregisterAdvancedLifecycle)
            return;
        if (typeof advanced.registerLifecycleHook !== 'function')
            throw new Error('METEONEXA_ADVANCED_LIFECYCLE_API_NOT_READY');
        unregisterAdvancedLifecycle = advanced.registerLifecycleHook(Object.freeze({
            onPage,
            afterRefresh: () => refreshSuite(false),
            locationChanged() {
                suite.archive = [];
                destroyArchiveMap();
                suite.refreshedAt = 0;
                suite.refreshLocationKey = '';
                setTimeout(() => refreshSuite(true), 500);
            }
        }));
    }
    let initialized = false;
    function initialize() {
        if (initialized) return;
        initialized = true;
        ensureUI();
        bind();
        patchLifecycle();
        if (!isGuest()) inspectPush().catch(() => { });
        setTimeout(() => {
            if (state.currentPage === 'advanced' && state?.weather && !suite.refreshedAt)
                refreshSuite(false);
        }, 350);
    }
    document.addEventListener('meteonexa:ready', initialize, { once: true });
    if (state.bootComplete === true) queueMicrotask(initialize);

    const suiteService = Object.assign(deps.suite, { build: BUILD, refreshDiagnostics: refreshSuite, showLiveLightning, enableRemotePush, syncRemotePush, loadRadarArchive, refreshNetatmo, syncVisibility: ensureUI });
    provided.suiteIntegrations = Object.freeze({ refreshDiagnostics: refreshSuite, showLiveLightning, enableRemotePush, syncRemotePush, loadRadarArchive, refreshNetatmo, syncVisibility: ensureUI, suite: suiteService });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
