const BUILD = '20.1';
const manifest = globalThis.METEONEXA_ASSET_MANIFEST || {};
const appRoot = new URL('./', document.baseURI);

function assetUrl(logical) {
    const path = String(manifest[logical] || logical || '').trim();
    if (!path) throw new Error(`METEONEXA_ASSET_MISSING:${logical}`);
    return new URL(path, appRoot).href;
}

async function waitForGlobals(names, timeoutMs = 8000) {
    const startedAt = performance.now();
    while (true) {
        const missing = names.filter(name => globalThis[name] == null);
        if (!missing.length) return;
        if (performance.now() - startedAt >= timeoutMs) {
            throw new Error(`METEONEXA_BOOTSTRAP_DEPENDENCY_TIMEOUT:${missing.join(',')}`);
        }
        await new Promise(resolve => setTimeout(resolve, 10));
    }
}

async function waitForServices(services, names, timeoutMs = 8000) {
    const startedAt = performance.now();
    while (true) {
        const missing = names.filter(name => !services.has(name));
        if (!missing.length) return;
        if (performance.now() - startedAt >= timeoutMs) {
            throw new Error(`METEONEXA_BOOTSTRAP_SERVICE_TIMEOUT:${missing.join(',')}`);
        }
        await new Promise(resolve => setTimeout(resolve, 10));
    }
}

function loadClassic(logical) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = assetUrl(logical);
        script.async = false;
        script.dataset.meteonexaBootstrap = BUILD;
        script.addEventListener('load', () => resolve(script), { once: true });
        script.addEventListener('error', () => reject(new Error(`METEONEXA_SCRIPT_LOAD_FAILED:${logical}`)), { once: true });
        document.head.append(script);
    });
}

function preloadClassic(logical) {
    const href = assetUrl(logical);
    if (document.querySelector(`link[rel="preload"][href="${href}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'preload';
    link.as = 'script';
    link.href = href;
    link.fetchPriority = 'high';
    link.dataset.meteonexaPreload = logical;
    document.head.append(link);
}

function appendStylesheet(href, logical = href) {
    if ([...document.styleSheets].some(sheet => sheet.href === href) || document.querySelector(`link[data-meteonexa-style="${logical}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.meteonexaStyle = logical;
    document.head.append(link);
}

function scheduleDeferredStyles() {
    const run = () => {
        DEFERRED_STYLES.forEach(logical => appendStylesheet(assetUrl(logical), logical));
        appendStylesheet(new URL('api/vendor-asset.php?asset=maplibre-css', appRoot).href, 'maplibre-css');
    };
    const afterPaint = () => requestAnimationFrame(() => requestAnimationFrame(run));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', afterPaint, { once: true });
    else afterPaint();
}

async function importInstall(logical, services) {
    const module = await import(assetUrl(logical));
    if (typeof module.install !== 'function') throw new Error(`METEONEXA_ESM_INSTALL_MISSING:${logical}`);
    return module.install(services, globalThis);
}

async function importPreAppModules(services) {
    // Start every network/module fetch together, then install in the declared
    // order so dependency side effects remain deterministic without a startup waterfall.
    const modules = await Promise.all(PRE_APP_ESM.map(logical => import(assetUrl(logical))));
    for (let index = 0; index < modules.length; index += 1) {
        const module = modules[index];
        const logical = PRE_APP_ESM[index];
        if (typeof module.install !== 'function') throw new Error(`METEONEXA_ESM_INSTALL_MISSING:${logical}`);
        await module.install(services, globalThis);
    }
}

const CORE_ESM = Object.freeze([
    'modules/esm/core/service-registry.mjs',
    'modules/esm/core/runtime-api.mjs',
    'modules/esm/core/store.mjs',
    'modules/esm/core/runtime-state.mjs',
    'modules/esm/core/tooltips.mjs',
    'modules/esm/core/weather-utils.mjs',
    'modules/esm/core/i18n-preferences.mjs'
]);

const PRE_APP_ESM = Object.freeze([
    'modules/esm/domains/visualization.mjs',
    'modules/esm/domains/forecast-history.mjs',
    'modules/esm/domains/model-intelligence.mjs',
    'modules/esm/domains/device-sessions.mjs',
    'modules/esm/domains/app-lifecycle.mjs',
    'modules/esm/domains/app-utilities.mjs',
    'modules/esm/domains/locations.mjs',
    'modules/esm/domains/navigation.mjs',
    'modules/esm/domains/notifications.mjs',
    'modules/esm/domains/weather.mjs',
    'modules/esm/domains/radar.mjs',
    'modules/esm/domains/alerts.mjs',
    'modules/esm/domains/auth.mjs',
    'modules/esm/domains/auth-flow.mjs',
    'modules/esm/domains/account.mjs',
    'modules/esm/domains/radar-motion.mjs',
    'modules/esm/domains/radar-controller.mjs',
    'modules/esm/domains/privacy.mjs',
    'modules/esm/domains/feedback.mjs',
    'modules/esm/domains/ai.mjs'
]);

const SUITE_ESM = Object.freeze([
    'modules/esm/domains/suite-support.mjs',
    'modules/esm/features/route-weather.mjs',
    'modules/esm/domains/suite-assistant.mjs'
]);

const DEFERRED_STYLES = Object.freeze([
    'css/advanced.css',
    'css/suite.css',
    'css/intelligence.css',
    'css/decision-timeline.css',
    'css/watch-plan.css',
    'css/copilot.css'
]);

const POST_APP_ESM = Object.freeze({
    copilot: 'modules/esm/features/copilot.mjs',
    intelligence: 'modules/esm/features/intelligence.mjs',
    decisionTimeline: 'modules/esm/features/decision-timeline.mjs',
    watchPlan: 'modules/esm/features/watch-plan.mjs',
    suiteIntegrations: 'modules/esm/domains/suite-integrations.mjs'
});

const BASE_RUNTIME_GLOBALS = Object.freeze([
    'METEONEXA_CONFIG',
    'MeteoNexaSecurity',
    'MeteoNexaI18n'
]);

const REQUIRED_PRE_APP_SERVICES = Object.freeze([
    'core', 'runtimeState', 'tooltips', 'weatherUtils', 'i18nPreferences', 'visualization', 'forecastHistory', 'modelIntelligence', 'deviceSessions', 'appLifecycle', 'appUtilities',
    'locations', 'navigation', 'notifications', 'weather', 'radar', 'alerts',
    'authDomain', 'authFlow', 'accountDomain', 'radarMotion', 'radarController',
    'privacy', 'feedback', 'ai'
]);

const PRE_APP_CLASSIC_SCRIPTS = Object.freeze([
    'js/custom-controls.js',
]);

async function installDeclaredModules(logicals, services) {
    const modules = await Promise.all(logicals.map(logical => import(assetUrl(logical))));
    for (let index = 0; index < modules.length; index += 1) {
        const module = modules[index];
        const logical = logicals[index];
        if (typeof module.install !== 'function') throw new Error(`METEONEXA_ESM_INSTALL_MISSING:${logical}`);
        await module.install(services, globalThis);
    }
}

async function installCoreModules() {
    const [registryModule, runtimeModule, storeModule, runtimeStateModule, tooltipsModule, weatherUtilsModule, i18nPreferencesModule] = await Promise.all(
        CORE_ESM.map(logical => import(assetUrl(logical)))
    );

    const services = registryModule.installServiceRegistry(globalThis);
    runtimeModule.installRuntimeApi(globalThis, { documentRef: document }, services);
    storeModule.installCore(globalThis, services);
    runtimeStateModule.installRuntimeState(globalThis, services);
    tooltipsModule.installTooltips(globalThis, services);
    weatherUtilsModule.installWeatherUtils(globalThis, services);
    i18nPreferencesModule.installI18nPreferences(globalThis, services);
    return services;
}

async function boot() {
    preloadClassic('js/app.js');
    scheduleDeferredStyles();
    const services = await installCoreModules();
    await waitForGlobals(BASE_RUNTIME_GLOBALS);

    await importPreAppModules(services);
    await waitForServices(services, REQUIRED_PRE_APP_SERVICES);

    for (const logical of PRE_APP_CLASSIC_SCRIPTS) await loadClassic(logical);

    await loadClassic('js/app.js');
    await loadClassic('js/advanced.js');
    await importInstall(POST_APP_ESM.copilot, services);
    await installDeclaredModules(SUITE_ESM, services);
    await loadClassic('js/suite.js');

    // suite.js registers the delegated Assistant/Suite interaction handlers
    // synchronously. Its load event therefore marks the point where Assistant
    // controls are genuinely clickable; later intelligence modules must not
    // delay this narrower readiness contract.
    globalThis.__METEONEXA_INTERACTIVE_READY__ = true;
    document.dispatchEvent(new CustomEvent('meteonexa:interactive-ready', {
        detail: { build: BUILD, services: services.names.length }
    }));

    await importInstall(POST_APP_ESM.suiteIntegrations, services);
    await loadClassic('js/weather-intelligence.js');
    await importInstall(POST_APP_ESM.intelligence, services);
    await importInstall(POST_APP_ESM.decisionTimeline, services);
    await importInstall(POST_APP_ESM.watchPlan, services);

    document.dispatchEvent(new CustomEvent('meteonexa:esm-bootstrap-ready', {
        detail: {
            build: BUILD,
            esmCore: CORE_ESM.length,
            preAppEsm: PRE_APP_ESM.length,
            suiteEsm: SUITE_ESM.length,
            postAppEsm: Object.keys(POST_APP_ESM).length,
            classicScripts: PRE_APP_CLASSIC_SCRIPTS.length + 4,
            services: services.names.length
        }
    }));
}

try {
    await boot();
} catch (error) {
    console.error('METEONEXA_ESM_BOOTSTRAP_FAILED', error);
    document.documentElement.classList.remove('fresh-build');
    document.dispatchEvent(new CustomEvent('meteonexa:esm-bootstrap-error', {
        detail: { build: BUILD, code: String(error?.message || 'BOOTSTRAP_FAILED').slice(0, 240) }
    }));
}
