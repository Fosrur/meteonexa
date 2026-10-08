import { createSuiteRadarIntegrations } from './suite-radar-integrations.mjs';

export const serviceNames = Object.freeze(['suiteIntegrations']);
export const dependencies = Object.freeze(['runtimeApi','security','suiteSupport','suite','advanced','auth','controls','guestAccess','uiVisibility','loader','datePicker','tooltips']);

function factory(window, deps, provided) {
    const CONFIG = window.METEONEXA_CONFIG;
    if (!CONFIG) throw new Error('METEONEXA_CONFIG_NOT_LOADED');
    const APP_RUNTIME = deps.runtimeApi.get();
    const meteonexaText = window.meteonexaText;
    const { showToast, withLoader, loadWeather, syncEnhancedSelect, updateThreshold, appLocale, temperature, t, ensureRadar, removeRadarVectorLayer } = APP_RUNTIME;
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
    const {
        showLiveLightning, registerRadarArchive, destroyArchiveMap, loadRadarArchive
    } = createSuiteRadarIntegrations({
        window, deps, CONFIG, state, suite, q, qa, num, clamp, safe, currentLocale,
        fetchJson, API, setText, locationData, ensureRadar, removeRadarVectorLayer,
        isGuest, uiVisible, deviceId, toast, meteonexaText, dateValue, localTime
    });
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
        const lightningButton = q('[data-advanced-radar-layer="lightning"]');
        if (lightningButton) {
            lightningButton.addEventListener('click', () => {
                clearInterval(suite.lightningTimer);
                suite.lightningTimer = setInterval(() => {
                    if (q('#page-radar')?.classList.contains('active-page') && lightningButton.classList.contains('active'))
                        showLiveLightning().catch(() => { });
                }, 60000);
            }, { passive: true });
        }
    }
    async function refreshSuite(force = false) {
        if (!state?.weather)
            return;
        const key = `${Number(state.location?.latitude).toFixed(4)}:${Number(state.location?.longitude).toFixed(4)}`;
        if (suite.refreshRequest)
            return suite.refreshRequest;
        if (!force && suite.refreshLocationKey === key && Date.now() - suite.refreshedAt < 3000)
            return;
        suite.refreshRequest = (async () => {
            ensureUI();
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
