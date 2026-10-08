export const serviceNames = Object.freeze(['radarLayers']);
export const dependencies = Object.freeze(['runtimeApi']);

const SOURCE_ID = 'meteonexa-radar-v2-source';
const LAYERS = Object.freeze({
    cloud: { variable: 'cloud_cover', unit: '%', renderer: 'cloud', min: 0, max: 100 },
    temperature: { variable: 'temperature_2m', unit: '°C', renderer: 'temperature', min: -10, max: 40 },
    wind: { variable: 'wind_speed_10m,wind_direction_10m', unit: 'km/h', renderer: 'wind', min: 0, max: 90 },
    pressure: { variable: 'surface_pressure', unit: 'hPa', renderer: 'pressure', min: 970, max: 1045 },
    snow: { variable: 'snowfall', unit: 'cm', renderer: 'snow', min: 0, max: 4 },
    air: { variable: 'european_aqi,pm2_5', unit: 'AQI', renderer: 'air', min: 0, max: 200 },
    marine: { variable: 'wave_height,sea_surface_temperature', unit: 'm', renderer: 'marine', min: 0, max: 6 }
});

function installLayerService(window, runtime) {
    const state = runtime.getState();
    const CONFIG = window.METEONEXA_CONFIG;
    const text = key => String(window.meteonexaText?.(key) || key);
    const q = selector => window.document.querySelector(selector);
    const qa = selector => [...window.document.querySelectorAll(selector)];
    const labelFor = type => ({
        cloud: text('visualization.take.cloud_cover'),
        temperature: text('history.yrain.temperature'),
        wind: text('visualization.take.wind'),
        pressure: text('visualization.take.pressure'),
        snow: text('history.renderhistory.snow'),
        air: text('suite.showsuitemaplayer.air_quality'),
        marine: text('suite.showsuitemaplayer.waves_sea')
    })[type] || type;
    const apiFor = type => type === 'air' ? CONFIG.AIR_QUALITY_API : type === 'marine' ? CONFIG.MARINE_API : CONFIG.WEATHER_API;
    const toNumber = value => Number.isFinite(Number(value)) ? Number(value) : null;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    let activeRequest = 0;

    function clearMapLayers() {
        const map = state?.radar?.vectorMap;
        if (!map) return;
        ['meteonexa-radar-v2-wind-arrows', 'meteonexa-radar-v2-symbols', 'meteonexa-radar-v2-points', 'meteonexa-radar-v2-heat'].forEach(id => {
            try { if (map.getLayer(id)) map.removeLayer(id); } catch { }
        });
        try { if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID); } catch { }
    }

    function clearLegend() {
        q('.radar-layer-v2-legend')?.remove();
    }

    function setLegend(type, status, value = '') {
        clearLegend();
        const root = q('#radar-map');
        if (!root) return;
        const node = window.document.createElement('div');
        node.className = `radar-layer-v2-legend is-${status}`;
        node.dataset.radarLayerStatus = status;
        node.innerHTML = `<div><strong>${escapeHtml(labelFor(type))}</strong><span>${escapeHtml(value)}</span></div><small>${escapeHtml(statusText(status))}</small>`;
        root.append(node);
    }

    function statusText(status) {
        if (status === 'loading') return text('suite.showsuitemaplayer.loading_layer');
        if (status === 'error') return text('advanced.setadvancedradarlayer.unavailable');
        if (status === 'empty') return text('suite.showsuitemaplayer.no_data_available');
        return text('suite.showsuitemaplayer.point_data_interpolated_grid_these_not_radar_observations');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
    }

    function gridCoordinates(type) {
        const latitude = Number(state?.location?.latitude);
        const longitude = Number(state?.location?.longitude);
        const size = type === 'marine' ? 5 : 7;
        const stepLat = type === 'marine' ? .65 : .34;
        const stepLon = stepLat / Math.max(.45, Math.cos(latitude * Math.PI / 180));
        const half = (size - 1) / 2;
        const rows = [];
        for (let y = -half; y <= half; y += 1) {
            for (let x = -half; x <= half; x += 1) {
                rows.push({ latitude: latitude + y * stepLat, longitude: longitude + x * stepLon });
            }
        }
        return rows;
    }

    async function fetchJson(url) {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 16000);
        try {
            const response = await window.fetch(url, { cache: 'no-store', credentials: 'omit', signal: controller.signal, headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(`HTTP_${response.status}`);
            return payload;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    async function loadLayerData(type) {
        const config = LAYERS[type];
        if (!config) throw new Error('RADAR_LAYER_UNKNOWN');
        const coords = gridCoordinates(type);
        const params = new URLSearchParams({
            latitude: coords.map(row => row.latitude.toFixed(4)).join(','),
            longitude: coords.map(row => row.longitude.toFixed(4)).join(','),
            timezone: 'GMT'
        });
        if (type === 'marine') {
            params.set('hourly', config.variable);
            params.set('forecast_hours', '1');
        } else {
            params.set('current', config.variable);
            if (type === 'wind') params.set('wind_speed_unit', 'kmh');
        }
        const raw = await fetchJson(`${apiFor(type)}?${params}`);
        const rows = Array.isArray(raw) ? raw : [raw];
        return { type, config, coords, rows };
    }

    function featureRows(data) {
        const { type, config, coords, rows } = data;
        return rows.flatMap((row, index) => {
            const coordinate = coords[index];
            if (!coordinate) return [];
            const source = type === 'marine' ? row?.hourly : row?.current;
            const key = type === 'marine' ? 'wave_height' : type === 'air' ? 'european_aqi' : type === 'wind' ? 'wind_speed_10m' : config.variable;
            const rawValue = type === 'marine' ? source?.[key]?.[0] : source?.[key];
            const value = toNumber(rawValue);
            if (value === null) return [];
            const direction = type === 'wind' ? toNumber(row?.current?.wind_direction_10m) : null;
            const label = type === 'temperature' || type === 'pressure' || type === 'wind' || type === 'air'
                ? `${Math.round(value)} ${config.unit}`
                : `${value.toFixed(type === 'marine' ? 1 : 0)} ${config.unit}`;
            return [{
                type: 'Feature',
                properties: { value, direction: direction ?? 0, label },
                geometry: { type: 'Point', coordinates: [coordinate.longitude, coordinate.latitude] }
            }];
        });
    }

    function addSource(map, features) {
        map.addSource(SOURCE_ID, { type: 'geojson', data: { type: 'FeatureCollection', features } });
    }

    function addHeat(map, config, type) {
        const palette = type === 'cloud'
            ? ['rgba(210,225,240,0)', 'rgba(210,225,240,.35)', 'rgba(245,248,252,.82)']
            : type === 'temperature'
                ? ['rgba(52,122,235,0)', 'rgba(255,210,80,.55)', 'rgba(232,72,60,.85)']
                : type === 'air'
                    ? ['rgba(76,175,80,0)', 'rgba(255,193,7,.55)', 'rgba(229,57,53,.82)']
                    : ['rgba(70,135,220,0)', 'rgba(80,210,190,.5)', 'rgba(255,100,120,.82)'];
        map.addLayer({
            id: 'meteonexa-radar-v2-heat', type: 'heatmap', source: SOURCE_ID,
            paint: {
                'heatmap-weight': ['interpolate', ['linear'], ['get', 'value'], config.min, 0, config.max, 1],
                'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 3, .9, 8, 1.8],
                'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 3, 32, 8, 70],
                'heatmap-opacity': .82,
                'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'], 0, palette[0], .42, palette[1], 1, palette[2]]
            }
        });
    }

    function addPoints(map, config, type) {
        const middle = (config.min + config.max) / 2;
        map.addLayer({
            id: 'meteonexa-radar-v2-points', type: 'circle', source: SOURCE_ID,
            paint: {
                'circle-radius': type === 'marine' ? ['interpolate', ['linear'], ['get', 'value'], 0, 5, config.max, 18] : 5,
                'circle-color': ['interpolate', ['linear'], ['get', 'value'], config.min, '#3979d5', middle, '#4fd2bb', config.max, '#f35f75'],
                'circle-opacity': type === 'pressure' ? .42 : .66,
                'circle-stroke-width': 1,
                'circle-stroke-color': 'rgba(255,255,255,.75)'
            }
        });
    }

    function addSymbols(map, type) {
        if (type === 'wind') {
            map.addLayer({
                id: 'meteonexa-radar-v2-wind-arrows', type: 'symbol', source: SOURCE_ID,
                layout: { 'text-field': '↑', 'text-size': 20, 'text-rotate': ['get', 'direction'], 'text-rotation-alignment': 'map', 'text-allow-overlap': true },
                paint: { 'text-color': '#8ed7ff', 'text-halo-color': 'rgba(3,16,34,.92)', 'text-halo-width': 2 }
            });
        }
        map.addLayer({
            id: 'meteonexa-radar-v2-symbols', type: 'symbol', source: SOURCE_ID,
            layout: { 'text-field': ['get', 'label'], 'text-size': 11, 'text-offset': type === 'wind' ? [0, 1.65] : [0, 0], 'text-allow-overlap': type === 'wind' },
            paint: { 'text-color': '#fff', 'text-halo-color': 'rgba(3,16,34,.92)', 'text-halo-width': 2 }
        });
    }

    function render(type, data) {
        const features = featureRows(data);
        const values = features.map(feature => Number(feature.properties.value)).filter(Number.isFinite);
        const max = Math.max(...values);
        const min = Math.min(...values);
        if (!values.length) {
            clearMapLayers();
            setLegend(type, 'empty');
            return { features: 0, empty: true };
        }
        if (type === 'snow' && max <= 0) {
            clearMapLayers();
            setLegend(type, 'ready', '0 cm');
            return { features: values.length, empty: false, zero: true };
        }
        const map = state?.radar?.vectorMap;
        if (!map || !state?.radar?.vectorMapReady) throw new Error('RADAR_MAP_NOT_READY');
        clearMapLayers();
        addSource(map, features);
        if (type === 'cloud' || type === 'temperature' || type === 'air') addHeat(map, data.config, type);
        if (type === 'pressure' || type === 'marine' || type === 'snow') addPoints(map, data.config, type);
        if (type === 'wind') {
            addPoints(map, data.config, type);
            addSymbols(map, type);
        } else if (type === 'pressure' || type === 'marine' || type === 'snow' || type === 'temperature') {
            addSymbols(map, type);
        }
        const decimals = type === 'marine' ? 1 : 0;
        setLegend(type, 'ready', `${min.toFixed(decimals)}–${max.toFixed(decimals)} ${data.config.unit}`);
        map.triggerRepaint?.();
        return { features: features.length, empty: false };
    }

    async function waitForVectorMap() {
        const deadline = Date.now() + 6500;
        while (!state?.radar?.vectorMap || !state?.radar?.vectorMapReady) {
            if (state?.radar?.vectorMapFailed || Date.now() >= deadline) throw new Error('RADAR_MAP_NOT_READY');
            await new Promise(resolve => window.setTimeout(resolve, 100));
        }
    }

    async function show(type) {
        const config = LAYERS[type];
        if (!config) throw new Error('RADAR_LAYER_UNKNOWN');
        const request = ++activeRequest;
        qa('[data-suite-map-layer]').forEach(button => button.classList.toggle('active', button.dataset.suiteMapLayer === type));
        qa('[data-advanced-radar-layer]').forEach(button => button.classList.remove('active'));
        setLegend(type, 'loading');
        try {
            const initialization = runtime.ensureRadar?.(false, { silent: true });
            Promise.resolve(initialization).catch(() => {});
            const data = await loadLayerData(type);
            if (request !== activeRequest) return { cancelled: true };
            const features = featureRows(data);
            if (features.length && !(type === 'snow' && features.every(feature => feature.properties.value <= 0))) {
                await waitForVectorMap();
                if (request !== activeRequest) return { cancelled: true };
                runtime.removeRadarVectorLayer?.();
                state.radar.mode = 'live';
                state.radar.presentationLayer = `forecast-${type}`;
            }
            const result = render(type, data);
            if (request !== activeRequest) return { cancelled: true };
            const source = q('#radar-source');
            if (source) source.textContent = `${labelFor(type)} · ${text('radar.updateradarmodeui.open_meteo_forecast')}`;
            return result;
        } catch (error) {
            if (request !== activeRequest) return { cancelled: true };
            clearMapLayers();
            setLegend(type, 'error', error?.message || '');
            runtime.showToast?.(text('advanced.setadvancedradarlayer.unavailable'), error?.message || text('suite.showsuitemaplayer.no_data_available'), 'warning');
            throw error;
        }
    }

    function remove() {
        activeRequest += 1;
        clearMapLayers();
        clearLegend();
        qa('[data-suite-map-layer]').forEach(button => button.classList.remove('active'));
    }

    return Object.freeze({ show, remove, clear: remove, loadLayerData, render });
}

function factory(window, deps, provided) {
    provided.radarLayers = installLayerService(window, deps.runtimeApi.get());
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
