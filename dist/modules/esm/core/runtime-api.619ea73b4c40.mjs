export const REQUIRED_RUNTIME_METHODS = Object.freeze([
    'getState', 'loadJSON', 'saveJSON', 'showToast', 'withLoader',
    'loadWeather', 'updateThreshold', 'syncEnhancedSelect', 'currentHourlyIndex',
    'temperature', 'weatherSummary', 'appLocale', 't'
]);

export function createRuntimeApiHost({ documentRef = globalThis.document } = {}) {
    let current = null;

    function publish(api) {
        if (!api || typeof api !== 'object') throw new Error('METEONEXA_RUNTIME_API_INVALID');
        const missing = REQUIRED_RUNTIME_METHODS.filter(name => typeof api[name] !== 'function');
        if (missing.length) throw new Error(`METEONEXA_RUNTIME_API_MISSING:${missing.join(',')}`);
        current = Object.freeze({ ...api });
        try {
            documentRef?.dispatchEvent?.(new CustomEvent('meteonexa:runtime-api-ready', {
                detail: { build: String(api.build || '') }
            }));
        } catch { }
        return current;
    }

    function get() {
        if (!current) throw new Error('METEONEXA_RUNTIME_API_NOT_READY');
        return current;
    }

    function optional() {
        return current;
    }

    return Object.freeze({ publish, get, optional });
}

export function installRuntimeApi(host = globalThis, options = {}, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_RUNTIME_API_HOST_INVALID');
    const existing = services?.get?.('runtimeApi');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('runtimeApi', createRuntimeApiHost(options));
}
