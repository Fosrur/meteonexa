const BUILD = '20.1';
const subscribers = new Set();
const eventSubscribers = new Map();
let runtimeState = null;
let revision = 0;
let updatedAt = Date.now();
const overlays = {
    navigation: { pendingPage: null, error: '' },
    weather: { lastError: '' },
    radar: { loadReason: '', error: '', lastUpdatedAt: '' },
    alerts: { severe: null, official: null, unread: null },
    feedback: { sending: false, lastResult: null },
    ai: { mode: 'local', busy: false, lastToolRun: null, error: '' },
    intelligence: { raw: null, nowcastV2: null, nowcastV4: null, confidenceV2: null, twin: null },
    route: { raw: null, summary: null }
};

const cloneObject = value => value && typeof value === 'object' && !Array.isArray(value) ? { ...value } : value;
const numberOr = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;

function runtime() {
    return runtimeState && typeof runtimeState === 'object' ? runtimeState : {};
}

function projectState() {
    const state = runtime();
    const session = state.session || null;
    const severeEvents = Array.isArray(state.severeWeather?.events) ? state.severeWeather.events : [];
    const officialRelevant = Array.isArray(state.officialAlerts?.relevant) ? state.officialAlerts.relevant : [];
    const derivedUnread = Math.max(0, numberOr(state.alertWeatherUnreadCount) + numberOr(state.notificationInboxUnread));
    return Object.freeze({
        build: BUILD,
        revision,
        updatedAt,
        navigation: Object.freeze({
            currentPage: String(state.currentPage || 'home'),
            pendingPage: overlays.navigation.pendingPage,
            busy: numberOr(state.loaderDepth) > 0,
            error: overlays.navigation.error
        }),
        weather: Object.freeze({
            data: state.weather || null,
            air: state.air || null,
            location: state.location ? { ...state.location } : null,
            source: String(state.weather?.source || 'none'),
            fetchedAt: numberOr(state.weather?.fetchedAt),
            lastError: overlays.weather.lastError
        }),
        radar: Object.freeze({
            loading: state.radar?.loading === true,
            loaded: state.radar?.loaded === true,
            mode: String(state.radar?.mode || state.settings?.radarMode || 'live'),
            motion: state.radar?.motion || null,
            liveAvailable: state.radar?.liveAvailable === true,
            forecastAvailable: state.radar?.forecastAvailable === true,
            frameCount: numberOr(state.radar?.frames?.length),
            loadReason: overlays.radar.loadReason,
            error: overlays.radar.error,
            lastUpdatedAt: overlays.radar.lastUpdatedAt
        }),
        alerts: Object.freeze({
            severe: overlays.alerts.severe ?? severeEvents,
            official: overlays.alerts.official ?? officialRelevant,
            unread: overlays.alerts.unread ?? derivedUnread
        }),
        auth: Object.freeze({
            type: session?.type || null,
            authenticated: session?.type === 'email',
            serverVerified: state.authServerVerified === true,
            name: String(session?.name || '')
        }),
        privacy: Object.freeze({ notice: cloneObject(state.privacyNotice) }),
        feedback: Object.freeze({ ...overlays.feedback }),
        ai: Object.freeze({ ...overlays.ai }),
        intelligence: Object.freeze({ ...overlays.intelligence }),
        route: Object.freeze({ ...overlays.route })
    });
}

function emit(name, detail = {}) {
    const set = eventSubscribers.get(name);
    if (set) {
        for (const listener of [...set]) {
            try { listener(detail); } catch (error) { console.warn('METEONEXA_EVENT_SUBSCRIBER_FAILED', name, error); }
        }
    }
    try { document.dispatchEvent(new CustomEvent(`meteonexa:${name}`, { detail })); } catch { }
}

function notify(action, previous, next) {
    for (const listener of [...subscribers]) {
        try { listener(next, previous, action); } catch (error) { console.warn('METEONEXA_STORE_SUBSCRIBER_FAILED', error); }
    }
}

function commit(action, previous = projectState()) {
    revision += 1;
    updatedAt = Date.now();
    const next = projectState();
    notify(action, previous, next);
    emit('store:changed', { action, state: next });
    return next;
}

function assignRuntime(domain, value) {
    const state = runtimeState;
    if (!state || !value || typeof value !== 'object') return;
    if (domain === 'navigation' && Object.prototype.hasOwnProperty.call(value, 'currentPage')) {
        state.currentPage = String(value.currentPage || 'home');
    } else if (domain === 'weather') {
        if (Object.prototype.hasOwnProperty.call(value, 'data')) state.weather = value.data || null;
        if (Object.prototype.hasOwnProperty.call(value, 'air')) state.air = value.air || null;
        if (Object.prototype.hasOwnProperty.call(value, 'location')) state.location = value.location ? { ...value.location } : null;
    } else if (domain === 'radar' && state.radar && typeof state.radar === 'object') {
        for (const key of ['loading','loaded','mode','motion','liveAvailable','forecastAvailable']) {
            if (Object.prototype.hasOwnProperty.call(value, key)) state.radar[key] = value[key];
        }
    } else if (domain === 'auth') {
        if (Object.prototype.hasOwnProperty.call(value, 'serverVerified')) state.authServerVerified = value.serverVerified === true;
    } else if (domain === 'privacy' && Object.prototype.hasOwnProperty.call(value, 'notice')) {
        state.privacyNotice = value.notice || null;
    }
}

function patch(domain, value, actionType = `${domain}/patch`) {
    if (!domain || !value || typeof value !== 'object') return projectState();
    const previous = projectState();
    assignRuntime(domain, value);
    if (domain in overlays) {
        const overlayPatch = { ...value };
        if (domain === 'navigation') delete overlayPatch.currentPage;
        if (domain === 'weather') {
            delete overlayPatch.data;
            delete overlayPatch.air;
            delete overlayPatch.location;
            delete overlayPatch.source;
            delete overlayPatch.fetchedAt;
        }
        if (domain === 'radar') {
            for (const key of ['loading','loaded','mode','motion','liveAvailable','forecastAvailable','frameCount']) delete overlayPatch[key];
        }
        if (domain === 'auth' || domain === 'privacy') {
            // These domains are represented entirely by the authoritative runtime state.
        } else {
            overlays[domain] = { ...overlays[domain], ...overlayPatch };
        }
    }
    return commit({ type: actionType, domain, payload: value }, previous);
}

function attachRuntimeState(reference) {
    runtimeState = reference && typeof reference === 'object' ? reference : null;
    revision += 1;
    updatedAt = Date.now();
    const state = projectState();
    emit('core-ready', { build: BUILD, state });
    return state;
}

function subscribe(listener, { immediate = false } = {}) {
    subscribers.add(listener);
    if (immediate) {
        const state = projectState();
        try { listener(state, state, { type: 'store/immediate' }); } catch { }
    }
    return () => subscribers.delete(listener);
}

function on(name, listener) {
    if (!eventSubscribers.has(name)) eventSubscribers.set(name, new Set());
    eventSubscribers.get(name).add(listener);
    return () => eventSubscribers.get(name)?.delete(listener);
}

function select(selector) {
    const state = projectState();
    try { return typeof selector === 'function' ? selector(state) : state?.[selector]; } catch { return undefined; }
}

export const coreService = Object.freeze({
    build: BUILD,
    getState: projectState,
    attachRuntimeState,
    patch,
    subscribe,
    select,
    events: Object.freeze({ emit, on })
});

export function installCore(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_CORE_HOST_INVALID');
    const existing = services?.get?.('core');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('core', coreService);
}
