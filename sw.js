'use strict';
const BUILD = '20.1';
const SHELL_REVISION = 'rc2-stabilization-02-ci-esm';
const SHELL_CACHE = `meteonexa-shell-v${BUILD}-${SHELL_REVISION}`;
const RUNTIME_CACHE = `meteonexa-runtime-v${BUILD}`;
const BACKGROUND_CONFIG_KEY = './__meteonexa_background_config__';
const CATALOG_KEY_PREFIX = './__meteonexa_catalog__';
try { importScripts(`./asset-manifest.js?v=${BUILD}`); } catch { }
const ASSET_MANIFEST = self.METEONEXA_ASSET_MANIFEST || {};
const asset = logical => ASSET_MANIFEST[logical] || `./${logical}?v=${BUILD}`;
const CRITICAL_SHELL = [
    './', './index.html',
    asset('styles.css'), asset('advanced.css'), asset('light-theme.css'),
    asset('boot-clock.js'), asset('i18n-runtime.js'), asset('config.js'), asset('security-runtime.js'), asset('custom-controls.js'), asset('product-metrics.js'), asset('analytics.js'),
    asset('modules/esm/bootstrap.mjs'), asset('modules/esm/core/service-registry.mjs'), asset('modules/esm/core/runtime-api.mjs'), asset('modules/esm/core/store.mjs'), asset('modules/esm/core/runtime-state.mjs'), asset('modules/esm/core/tooltips.mjs'), asset('modules/esm/core/weather-utils.mjs'), asset('modules/esm/core/i18n-preferences.mjs'),
    asset('modules/esm/domains/visualization.mjs'), asset('modules/esm/domains/forecast-history.mjs'), asset('modules/esm/domains/model-intelligence.mjs'), asset('modules/esm/domains/device-sessions.mjs'), asset('modules/esm/domains/app-lifecycle.mjs'), asset('modules/esm/domains/app-utilities.mjs'), asset('modules/esm/domains/locations.mjs'), asset('modules/esm/domains/navigation.mjs'), asset('modules/esm/domains/notifications.mjs'), asset('modules/esm/domains/weather.mjs'), asset('modules/esm/domains/radar.mjs'), asset('modules/esm/domains/alerts.mjs'), asset('modules/esm/domains/auth.mjs'), asset('modules/esm/domains/auth-flow.mjs'), asset('modules/esm/domains/account.mjs'), asset('modules/esm/domains/radar-motion.mjs'), asset('modules/esm/domains/radar-controller.mjs'), asset('modules/esm/domains/privacy.mjs'), asset('modules/esm/domains/feedback.mjs'), asset('modules/esm/domains/ai.mjs'),
    asset('modules/esm/domains/suite-support.mjs'), asset('modules/esm/domains/suite-assistant.mjs'), asset('modules/esm/domains/suite-integrations.mjs'), asset('modules/esm/features/route-weather.mjs'), asset('modules/esm/features/copilot.mjs'), asset('modules/esm/features/intelligence.mjs'), asset('modules/esm/features/decision-timeline.mjs'), asset('modules/esm/features/watch-plan.mjs'),
    asset('app.js'), asset('advanced.js'), asset('suite.js'), asset('weather-intelligence.js'),
    './offline.html', './assets/i18n/it.json',
    `./assets/logo.png?v=${BUILD}`, `./assets/logo-full.png?v=${BUILD}`,
    `./assets/icons/icon-192.png?v=${BUILD}`, `./assets/icons/favicon-32.png?v=${BUILD}`, `./favicon.ico?v=${BUILD}`
];
const OPTIONAL_SHELL = [
    asset('suite.css'), asset('intelligence.css'), asset('decision-timeline.css'), asset('watch-plan.css'), asset('copilot.css'), asset('standalone.css'),
    './privacy.html', './cookie-policy.html', './robots.txt', './sitemap.xml',
    './assets/i18n/en.json', './assets/i18n/fr.json', './assets/i18n/es.json', './assets/i18n/de.json',
    asset('privacy-context.js'), asset('page-i18n.js'), asset('offline.js'), asset('protected-page.js'),
    './vendor-asset.php?asset=maplibre-css', './vendor-asset.php?asset=maplibre-js', './vendor-asset.php?asset=italy-regions', './vendor-asset.php?asset=italy-metros',
    './assets/data/world-lines.json',
    `./assets/icons/icon-512.png?v=${BUILD}`, `./assets/icons/maskable-512.png?v=${BUILD}`, `./assets/icons/apple-touch-icon.png?v=${BUILD}`
];
const APP_SHELL = [...CRITICAL_SHELL, ...OPTIONAL_SHELL];
const BACKGROUND_WEATHER_ORIGIN = 'https://api.open-meteo.com';
const BACKGROUND_WEATHER_PATH = '/v1/forecast';
function swScope() { return new URL(self.registration?.scope || './', self.location.href); }
function isUrlInsideScope(url) { const scope = swScope(); return url.origin === scope.origin && url.pathname.startsWith(scope.pathname); }
function safeTargetUrl(value, fallback = './#notifications') {
    const scope = swScope();
    try {
        const candidate = new URL(String(value || fallback), scope);
        if (isUrlInsideScope(candidate)) return candidate.href;
    }
    catch { }
    return new URL(fallback, scope).href;
}
function trustedMessageSource(event) {
    const sourceUrl = String(event?.source?.url || '');
    if (!sourceUrl) return false;
    try { return isUrlInsideScope(new URL(sourceUrl)); }
    catch { return false; }
}
function safeBackgroundWeatherApi(value) {
    try {
        const url = new URL(String(value || ''));
        if (url.origin !== BACKGROUND_WEATHER_ORIGIN || url.pathname !== BACKGROUND_WEATHER_PATH || url.username || url.password) return null;
        return `${BACKGROUND_WEATHER_ORIGIN}${BACKGROUND_WEATHER_PATH}`;
    }
    catch { return null; }
}
function boundedNumber(value, min, max, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? Math.max(min, Math.min(max, number)) : fallback;
}
function sanitizeBackgroundConfig(payload, previous = null) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
    const location = payload.location && typeof payload.location === 'object' ? payload.location : null;
    const latitude = boundedNumber(location?.latitude, -90, 90, NaN);
    const longitude = boundedNumber(location?.longitude, -180, 180, NaN);
    const weatherApi = safeBackgroundWeatherApi(payload.weatherApi);
    const deviceId = String(payload.deviceId || '').trim();
    const deviceKey = String(payload.deviceKey || '').trim();
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !weatherApi || !/^[A-Za-z0-9._-]{12,96}$/.test(deviceId) || !/^[A-Za-z0-9_-]{32,128}$/.test(deviceKey)) return null;
    const rawThresholds = payload.thresholds && typeof payload.thresholds === 'object' ? payload.thresholds : {};
    const thresholds = {
        rain: boundedNumber(rawThresholds.rain, 0, 100, 70),
        wind: boundedNumber(rawThresholds.wind, 0, 250, 60),
        cold: boundedNumber(rawThresholds.cold, -50, 30, 2),
        heat: boundedNumber(rawThresholds.heat, 0, 70, 35),
    };
    const name = String(location?.name || '').replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, 120);
    const sanitized = { location: { latitude, longitude, name }, thresholds, weatherApi, language: normalizeLanguage(payload.language), deviceId, deviceKey, remotePush: payload.remotePush === true, serverAuthoritative: payload.serverAuthoritative === true };
    const lastNotice = payload?.lastNotice && typeof payload.lastNotice === 'object' ? payload.lastNotice : previous?.lastNotice;
    if (lastNotice && typeof lastNotice === 'object' && typeof lastNotice.key === 'string' && Number.isFinite(Number(lastNotice.at))) {
        sanitized.lastNotice = { key: lastNotice.key.slice(0, 180), at: Number(lastNotice.at) };
    }
    return sanitized;
}
function isStaticCacheable(url) {
    if (!isUrlInsideScope(url)) return false;
    const scope = swScope();
    return APP_SHELL.some(entry => {
        const allowed = new URL(entry, scope);
        return url.origin === allowed.origin && url.pathname === allowed.pathname && url.search === allowed.search;
    });
}
const normalizeLanguage = value => { const code = String(value || '').toLowerCase().split(/[-_]/)[0]; return ['it', 'en', 'fr', 'es', 'de'].includes(code) ? code : 'it'; };
const interpolate = (value, params = {}) => { let output = String(value ?? ''); Object.entries(params).forEach(([name, replacement]) => { output = output.replaceAll(`{${name}}`, String(replacement)).replaceAll(`\${${name}}`, String(replacement)); }); return output; };
async function saveJson(key, value) { const cache = await caches.open(RUNTIME_CACHE); await cache.put(key, new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } })); }
async function loadJson(key) { const cache = await caches.open(RUNTIME_CACHE), response = await cache.match(key); return response ? response.json().catch(() => null) : null; }
async function loadCatalog(language = 'it') {
    const locale = normalizeLanguage(language), key = `${CATALOG_KEY_PREFIX}${locale}`;
    const stored = await loadJson(key) || {};
    const cachedCatalog = stored?.catalog && typeof stored.catalog === 'object' ? stored.catalog : (stored && typeof stored === 'object' ? stored : {});
    const cachedVersion = typeof stored?.version === 'string' ? stored.version : '';
    try {
        const params = new URLSearchParams({ language: locale, _: String(Date.now()) });
        if (cachedVersion && Object.keys(cachedCatalog).length) params.set('translationsUpdatedAt', cachedVersion);
        const response = await fetch(`./api/i18n.php?${params}`, { cache: 'no-store', credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP_${response.status}`);
        const data = await response.json();
        if (data?.translations && typeof data.translations === 'object') {
            await saveJson(key, { version: String(data.translationsUpdatedAt || ''), catalog: data.translations });
            return data.translations;
        }
        if (Object.keys(cachedCatalog).length) return cachedCatalog;
        throw new Error('CATALOG_EMPTY');
    }
    catch {
        if (Object.keys(cachedCatalog).length) return cachedCatalog;
        try {
            const staticResponse = await fetch(`./assets/i18n/${locale}.json`, { cache: 'force-cache', credentials: 'omit' });
            if (staticResponse.ok) {
                const staticData = await staticResponse.json();
                if (staticData?.translations && typeof staticData.translations === 'object') return staticData.translations;
            }
        } catch {}
        return cachedCatalog;
    }
}
async function swText(key, params = {}, language = 'it') { const catalog = await loadCatalog(language); return interpolate(catalog[key] ?? key, params); }
function immutableAssetRequest(url) {
    return /\/dist\/.+\.[a-f0-9]{12}\.(?:css|js|mjs)$/i.test(new URL(url, swScope()).pathname);
}
async function cacheShellEntries(entries, { priorityLanguage = '' } = {}) {
    const cache = await caches.open(SHELL_CACHE);
    const language = normalizeLanguage(priorityLanguage);
    const prioritized = [...entries].sort((a, b) => {
        const preferred = `./assets/i18n/${language}.json`;
        return Number(b === preferred) - Number(a === preferred);
    });
    const queue = [...prioritized];
    const workers = Array.from({ length: Math.min(4, queue.length) }, async () => {
        while (queue.length) {
            const url = queue.shift();
            if (!url) break;
            try {
                const request = new Request(url, { cache: immutableAssetRequest(url) ? 'force-cache' : 'reload' });
                const response = await fetch(request);
                if (response.ok) await cache.put(request, response.clone());
            } catch { }
        }
    });
    await Promise.all(workers);
}
async function warmOptionalShell(language = '') {
    await cacheShellEntries(OPTIONAL_SHELL, { priorityLanguage: language });
}
self.addEventListener('install', event => { event.waitUntil((async () => { await cacheShellEntries(CRITICAL_SHELL); await self.skipWaiting(); })()); });
self.addEventListener('activate', event => { event.waitUntil((async () => { const keys = await caches.keys(); await Promise.all(keys.filter(key => key.startsWith('meteonexa-') && ![SHELL_CACHE, RUNTIME_CACHE].includes(key)).map(key => caches.delete(key))); await self.clients.claim(); })()); });
self.addEventListener('fetch', event => { const request = event.request; if (request.method !== 'GET')
    return; const url = new URL(request.url), sameOrigin = url.origin === self.location.origin; if (!sameOrigin)
    return; const isApi = /\/api\//.test(url.pathname); if (isApi) {
    event.respondWith((async () => { try {
        return await fetch(request, { cache: 'no-store', credentials: 'same-origin' });
    }
    catch {
        const config = await loadBackgroundConfig(), message = await swText('sw.api.unavailable', {}, config?.language);
        return new Response(JSON.stringify({ ok: false, message }), { status: 503, headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' } });
    } })());
    return;
} if (request.mode === 'navigate') {
    const fallback = url.pathname.endsWith('/privacy.html') ? './privacy.html' : url.pathname.endsWith('/cookie-policy.html') ? './cookie-policy.html' : url.pathname.endsWith('/offline.html') ? './offline.html' : './index.html';
    event.respondWith(networkFirst(request, fallback, 8000, false, true));
    return;
} event.respondWith(networkFirst(request, null, 7000, isStaticCacheable(url))); });
async function fetchWithTimeout(request, timeoutMs) { const controller = new AbortController(), timer = setTimeout(() => controller.abort(), timeoutMs); try {
    return await fetch(request, { signal: controller.signal, cache: 'no-store' });
}
finally {
    clearTimeout(timer);
} }
async function networkFirst(request, fallbackUrl, timeoutMs, allowCacheWrite = true, ignoreSearchOnRead = false) { const cache = await caches.open(SHELL_CACHE); try {
    const response = await fetchWithTimeout(request, timeoutMs);
    if (allowCacheWrite && response.ok && isStaticCacheable(new URL(request.url)))
        await cache.put(request, response.clone());
    return response;
}
catch {
    const cached = await cache.match(request, { ignoreSearch: ignoreSearchOnRead });
    if (cached)
        return cached;
    if (fallbackUrl) {
        const fallback = await cache.match(fallbackUrl);
        if (fallback)
            return fallback;
    }
    return new Response('', { status: 504 });
} }
async function saveBackgroundConfig(payload) { const previous = await loadBackgroundConfig(); const sanitized = sanitizeBackgroundConfig(payload, previous); if (!sanitized) return false; await saveJson(BACKGROUND_CONFIG_KEY, sanitized); return true; }
async function loadBackgroundConfig() { return await loadJson(BACKGROUND_CONFIG_KEY); }
async function clearBackgroundConfig() { const cache = await caches.open(RUNTIME_CACHE); return cache.delete(BACKGROUND_CONFIG_KEY); }
async function pullServerNotification(config) {
    if (!config?.deviceId || !config?.deviceKey || !self.registration?.showNotification) return false;
    try {
        const response = await fetch('./api/push/pending.php', {
            method: 'POST', cache: 'no-store', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-MeteoNexa-Device-Key': config.deviceKey, 'X-MeteoNexa-Device-Id': config.deviceId },
            body: JSON.stringify({ deviceId: config.deviceId })
        });
        if (!response.ok) return false;
        const data = await response.json();
        const notice = data?.notification;
        if (!notice) return false;
        const appName = await swText('app.name', {}, config.language);
        const title = String(notice.title || appName).slice(0, 180);
        const body = String(notice.body || '').slice(0, 600);
        const tag = String(notice.tag || 'meteonexa-server-alert').replace(/[^A-Za-z0-9._:-]/g, '-').slice(0, 180);
        await self.registration.showNotification(title, {
            body,
            icon: `./assets/icons/icon-192.png?v=${BUILD}`,
            badge: `./assets/icons/favicon-32.png?v=${BUILD}`,
            tag,
            renotify: false,
            data: { url: safeTargetUrl(notice.url || './#notifications') },
            vibrate: [120, 80, 120]
        });
        return true;
    } catch { return false; }
}
async function backgroundWeatherCheck() {
    const config = await loadBackgroundConfig();
    if (!config?.location || !self.registration?.showNotification) return;
    // authenticated background alerts have one authority: the
    // server-side Smart Alert engine. The worker only transports a queued
    // decision and never re-computes the weather from Open-Meteo.
    if (config.serverAuthoritative === true || config.remotePush === true) {
        await pullServerNotification(config);
        return;
    }
    // Compatibility-only fallback for old/local configurations. Current
    // authenticated clients always set serverAuthoritative=true.
    if (!config.weatherApi) return;
    const language = normalizeLanguage(config.language), params = new URLSearchParams({ latitude: String(config.location.latitude), longitude: String(config.location.longitude), minutely_15: 'precipitation', hourly: 'temperature_2m,precipitation_probability,wind_gusts_10m,weather_code', forecast_hours: '24', timezone: 'auto', wind_speed_unit: 'kmh' });
    let weather;
    try {
        const weatherApi = safeBackgroundWeatherApi(config.weatherApi);
        if (!weatherApi) return;
        const response = await fetch(`${weatherApi}?${params}`, { cache: 'no-store', credentials: 'omit' });
        if (!response.ok) return;
        weather = await response.json();
    } catch { return; }
    const minute = weather.minutely_15 || {}, points = (minute.time || []).map((time, index) => ({ time, mm: Number(minute.precipitation?.[index] || 0) })), first = points.findIndex(point => point.mm >= .05), thresholds = config.thresholds || {}, maxRain = Math.max(0, ...(weather.hourly?.precipitation_probability || []).map(Number)), maxWind = Math.max(0, ...(weather.hourly?.wind_gusts_10m || []).map(Number)), minTemp = Math.min(99, ...(weather.hourly?.temperature_2m || []).map(Number));
    let notice = null;
    if (first >= 0) {
        const minutes = Math.max(0, Math.round((new Date(points[first].time).getTime() - Date.now()) / 60000));
        if (minutes <= 30) notice = { key: `rain:${points[first].time}`, title: await swText('sw.rain.title', { minutes }, language), body: await swText('push.rain.body', { location: config.location.name }, language), url: './#advanced' };
    }
    if (!notice && maxWind >= Number(thresholds.wind || 60)) notice = { key: `wind:${Math.round(maxWind / 5) * 5}:${new Date().toISOString().slice(0, 10)}`, title: await swText('sw.gust.title', {}, language), body: await swText('sw.gust.body', { value: Math.round(maxWind), location: config.location.name }, language), url: './#notifications' };
    if (!notice && minTemp <= Number(thresholds.cold ?? 2)) notice = { key: `cold:${Math.round(minTemp)}:${new Date().toISOString().slice(0, 10)}`, title: await swText('sw.frost.title', {}, language), body: await swText('sw.frost.body', { value: Math.round(minTemp), location: config.location.name }, language), url: './#notifications' };
    if (!notice && maxRain >= Number(thresholds.rain || 70)) notice = { key: `rain-day:${Math.round(maxRain / 5) * 5}:${new Date().toISOString().slice(0, 10)}`, title: await swText('sw.threshold.rain.title', {}, language), body: await swText('sw.threshold.rain.body', { value: Math.round(maxRain), location: config.location.name }, language), url: './#notifications' };
    if (!notice) return;
    const last = config.lastNotice || {};
    if (last.key === notice.key && Date.now() - Number(last.at || 0) < 10800000) return;
    config.lastNotice = { key: notice.key, at: Date.now() };
    await saveBackgroundConfig(config);
    await self.registration.showNotification(notice.title, { body: notice.body, icon: `./assets/icons/icon-192.png?v=${BUILD}`, badge: `./assets/icons/favicon-32.png?v=${BUILD}`, tag: `meteonexa-${notice.key}`, renotify: false, data: { url: safeTargetUrl(notice.url) }, vibrate: [120, 80, 120] });
}
self.addEventListener('periodicsync', event => { if (event.tag === 'meteonexa-weather-check')
    event.waitUntil(backgroundWeatherCheck()); });
self.addEventListener('sync', event => {
    if (event.tag === 'meteonexa-weather-check') {
        event.waitUntil(backgroundWeatherCheck());
        return;
    }
    if (event.tag === 'meteonexa-safe-sync') {
        event.waitUntil((async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            windows.forEach(client => client.postMessage({ type: 'METEONEXA_SAFE_SYNC' }));
        })());
    }
});
self.addEventListener('push', event => { event.waitUntil((async () => { let payload = null; try {
    payload = event.data?.json() || null;
}
catch {
    payload = null;
} const config = await loadBackgroundConfig(), language = config?.language; if (!payload && config?.deviceId) {
    try {
        const response = await fetch('./api/push/pending.php', { method: 'POST', cache: 'no-store', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...(config.deviceKey ? { 'X-MeteoNexa-Device-Key': config.deviceKey, 'X-MeteoNexa-Device-Id': config.deviceId } : {}) }, body: JSON.stringify({ deviceId: config.deviceId }) });
        if (response.status === 401 || response.status === 403) return;
        const data = await response.json();
        payload = data?.notification || null;
    }
    catch { }
} const appName = await swText('app.name', {}, language); payload ||= { title: appName, body: await swText('sw.update.body', {}, language), url: './#notifications', tag: 'meteonexa-push' }; const title = String(payload.title || appName).slice(0, 180), body = String(payload.body || await swText('sw.update.body', {}, language)).slice(0, 600), tag = String(payload.tag || 'meteonexa-push').replace(/[^A-Za-z0-9._:-]/g, '-').slice(0, 180); await self.registration.showNotification(title, { body, icon: `./assets/icons/icon-192.png?v=${BUILD}`, badge: `./assets/icons/favicon-32.png?v=${BUILD}`, tag, renotify: payload.renotify !== false, data: { url: safeTargetUrl(payload.url) }, vibrate: [120, 80, 120] }); })()); });
self.addEventListener('notificationclick', event => { event.notification.close(); const target = safeTargetUrl(event.notification.data?.url); event.waitUntil((async () => { const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true }); for (const client of windows) {
    if ('focus' in client) {
        await client.navigate(target).catch(() => null);
        return client.focus();
    }
} return self.clients.openWindow?.(target); })()); });
self.addEventListener('message', event => { if (!trustedMessageSource(event)) return; if (event.data?.type === 'SKIP_WAITING')
    return event.waitUntil(self.skipWaiting()); if (event.data?.type === 'WARM_OPTIONAL_SHELL')
    return event.waitUntil(warmOptionalShell(event.data?.language || '')); if (event.data?.type === 'CLEAR_APP_CACHES')
    return event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('meteonexa-')).map(key => caches.delete(key))))); if (event.data?.type === 'METEONEXA_CONFIGURE_BACKGROUND')
    return event.waitUntil(saveBackgroundConfig(event.data.payload || {})); if (event.data?.type === 'METEONEXA_CLEAR_BACKGROUND')
    return event.waitUntil(clearBackgroundConfig()); if (event.data?.type === 'METEONEXA_RUN_BACKGROUND_CHECK')
    return event.waitUntil(backgroundWeatherCheck()); if (event.data?.type !== 'SHOW_NOTIFICATION')
    return; const payload = event.data.payload || {}; event.waitUntil((async () => { const config = await loadBackgroundConfig(); const appName = await swText('app.name', {}, config?.language); const title = String(payload.title || appName).slice(0, 180), body = String(payload.body || await swText('sw.update.title', {}, config?.language)).slice(0, 600), tag = String(payload.tag || 'meteonexa-message').replace(/[^A-Za-z0-9._:-]/g, '-').slice(0, 180); await self.registration.showNotification(title, { body, icon: `./assets/icons/icon-192.png?v=${BUILD}`, badge: `./assets/icons/favicon-32.png?v=${BUILD}`, tag, data: { url: safeTargetUrl(payload.url) } }); })()); });
