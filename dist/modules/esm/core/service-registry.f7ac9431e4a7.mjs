const SERVICE_GLOBALS = Object.freeze({
    core: 'MeteoNexaCore',
    runtimeState: 'MeteoNexaRuntimeState',
    tooltips: 'MeteoNexaTooltips',
    weatherUtils: 'MeteoNexaWeatherUtils',
    i18nPreferences: 'MeteoNexaI18nPreferences',
    security: 'MeteoNexaSecurity',
    i18n: 'MeteoNexaI18n',
    authDomain: 'MeteoNexaAuthDomain',
    auth: 'MeteoNexaAuth',
    navigation: 'MeteoNexaNavigation',
    guestAccess: 'MeteoNexaGuestAccess',
    uiVisibility: 'MeteoNexaUIVisibility',
    accountDomain: 'MeteoNexaAccountDomain',
    accountSync: 'MeteoNexaAccountSync',
    authFlow: 'MeteoNexaAuthFlow',
    locations: 'MeteoNexaLocations',
    notifications: 'MeteoNexaNotifications',
    weather: 'MeteoNexaWeather',
    radar: 'MeteoNexaRadar',
    radarMotion: 'MeteoNexaRadarMotion',
    radarController: 'MeteoNexaRadarController',
    alerts: 'MeteoNexaAlerts',
    privacy: 'MeteoNexaPrivacy',
    feedback: 'MeteoNexaFeedback',
    ai: 'MeteoNexaAI',
    routeWeather: 'MeteoNexaRouteWeather',
    copilot: 'MeteoNexaCopilot',
    intelligence: 'MeteoNexaIntelligence',
    intelligenceV2: 'MeteoNexaIntelligenceV2',
    decisionTimeline: 'MeteoNexaDecisionTimeline',
    datePicker: 'MeteoNexaDatePicker',
    watchPlan: 'MeteoNexaWatchPlan',
    controls: 'MeteoNexaControls',
    metrics: 'MeteoNexaMetrics',
    analytics: 'MeteoNexaAnalytics',
    advanced: 'MeteoNexaAdvanced',
    suite: 'MeteoNexaSuite',
    suiteSupport: 'MeteoNexaSuiteSupport',
    visualization: 'MeteoNexaVisualization',
    forecastHistory: 'MeteoNexaForecastHistory',
    modelIntelligence: 'MeteoNexaModelIntelligence',
    deviceSessions: 'MeteoNexaDeviceSessions',
    appLifecycle: 'MeteoNexaAppLifecycle',
    suiteIntegrations: 'MeteoNexaSuiteIntegrations',
    appUtilities: 'MeteoNexaAppUtilities',
    suiteAssistant: 'MeteoNexaSuiteAssistant',
    loader: 'MeteoNexaLoader',
    panelLoader: 'MeteoNexaPanelLoader',
    toast: 'MeteoNexaToast',
    confirm: 'MeteoNexaConfirm',
    intelligenceQuality: 'MeteoNexaIntelligenceQuality',
    runtimeApi: 'MeteoNexaRuntimeAPI'
});

export function createServiceRegistry(host = globalThis) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_SERVICE_HOST_INVALID');
    const published = new Map();

    function normalize(name) {
        const key = String(name || '').trim();
        if (!SERVICE_GLOBALS[key]) throw new Error(`METEONEXA_SERVICE_UNKNOWN:${key}`);
        return key;
    }

    function globalName(name) {
        return SERVICE_GLOBALS[normalize(name)];
    }

    function get(name) {
        const key = normalize(name);
        if (published.has(key)) return published.get(key);
        const legacy = host[SERVICE_GLOBALS[key]];
        if (legacy != null) {
            published.set(key, legacy);
            return legacy;
        }
        return undefined;
    }

    function requireService(name) {
        const service = get(name);
        if (service == null) throw new Error(`METEONEXA_SERVICE_NOT_READY:${name}`);
        return service;
    }

    function publish(name, service, { legacy = false } = {}) {
        const key = normalize(name);
        if (service == null) throw new Error(`METEONEXA_SERVICE_INVALID:${key}`);
        const existing = published.get(key);
        if (existing != null && existing !== service) throw new Error(`METEONEXA_SERVICE_ALREADY_PUBLISHED:${key}`);
        published.set(key, service);
        if (legacy) {
            const target = SERVICE_GLOBALS[key];
            const globalExisting = host[target];
            if (globalExisting != null && globalExisting !== service) throw new Error(`METEONEXA_SERVICE_GLOBAL_CONFLICT:${key}`);
            host[target] = service;
        }
        try {
            host.document?.dispatchEvent?.(new CustomEvent('meteonexa:service-published', { detail: { name: key, legacy } }));
        } catch { }
        return service;
    }

    function exposeLegacy(name) {
        const key = normalize(name);
        const service = requireService(key);
        host[SERVICE_GLOBALS[key]] = service;
        return service;
    }


    function createDependencyView(dependencies) {
        const view = {};
        for (const name of dependencies) {
            const key = normalize(name);
            Object.defineProperty(view, key, {
                enumerable: true,
                configurable: false,
                get() { return get(key); }
            });
        }
        return Object.freeze(view);
    }

    function createProvidedView(expected) {
        const allowed = new Set(expected);
        const values = new Map();
        return {
            proxy: new Proxy(Object.create(null), {
                get(_target, prop) {
                    if (typeof prop !== 'string') return undefined;
                    if (!allowed.has(prop)) return undefined;
                    return values.get(prop);
                },
                set(_target, prop, value) {
                    if (typeof prop !== 'string' || !allowed.has(prop)) {
                        throw new Error(`METEONEXA_MODULE_SERVICE_UNDECLARED:${String(prop)}`);
                    }
                    if (value == null) throw new Error(`METEONEXA_MODULE_SERVICE_INVALID:${prop}`);
                    values.set(prop, value);
                    return true;
                },
                ownKeys() { return [...values.keys()]; },
                getOwnPropertyDescriptor(_target, prop) {
                    return values.has(prop) ? { enumerable: true, configurable: true } : undefined;
                }
            }),
            values
        };
    }

    function installModule({ provides = [], dependencies = [], factory }) {
        if (typeof factory !== 'function') throw new Error('METEONEXA_MODULE_FACTORY_REQUIRED');
        const expected = Object.freeze([...provides].map(normalize));
        if (expected.length && expected.every(name => has(name))) {
            return Object.freeze(Object.fromEntries(expected.map(name => [name, get(name)])));
        }
        const dependencyNames = Object.freeze([...dependencies].map(normalize));
        const deps = createDependencyView(dependencyNames);
        const provided = createProvidedView(expected);
        factory(host, deps, provided.proxy);
        for (const name of expected) {
            if (provided.values.has(name)) publish(name, provided.values.get(name));
        }
        const missing = expected.filter(name => !has(name));
        if (missing.length) throw new Error(`METEONEXA_MODULE_SERVICE_NOT_PUBLISHED:${missing.join(',')}`);
        return Object.freeze(Object.fromEntries(expected.map(name => [name, get(name)])));
    }

    function has(name) {
        try { return get(name) != null; } catch { return false; }
    }

    return Object.freeze({
        names: Object.freeze(Object.keys(SERVICE_GLOBALS)),
        get,
        require: requireService,
        publish,
        exposeLegacy,
        installModule,
        has,
        globalName
    });
}

export function installServiceRegistry(host = globalThis) {
    const existing = host.MeteoNexaServices;
    if (existing) return existing;
    const registry = createServiceRegistry(host);
    host.MeteoNexaServices = registry;
    return registry;
}

export { SERVICE_GLOBALS };
