#!/usr/bin/env node
import { installServiceRegistry } from '../modules/esm/core/service-registry.mjs';
import { installCore, coreService } from '../modules/esm/core/store.mjs';
import { createRuntimeState, installRuntimeState, runtimeStateService } from '../modules/esm/core/runtime-state.mjs';
import { installTooltips, tooltipsService } from '../modules/esm/core/tooltips.mjs';
import { create as createWeatherUtils, installWeatherUtils, weatherUtilsService } from '../modules/esm/core/weather-utils.mjs';
import { create as createI18nPreferences, installI18nPreferences, i18nPreferencesService } from '../modules/esm/core/i18n-preferences.mjs';

const assert = (condition, message) => {
    if (!condition) throw new Error(`P2_CORE_ESM_SMOKE:${message}`);
    console.log(`PASS ${message}`);
};

const host = {};
const services = installServiceRegistry(host);
assert(installCore(host, services) === coreService && services.get('core') === coreService && host.MeteoNexaCore === undefined, 'core installs only in internal registry');
assert(installRuntimeState(host, services) === runtimeStateService && services.get('runtimeState') === runtimeStateService && host.MeteoNexaRuntimeState === undefined, 'runtime-state installs only in internal registry');
assert(installTooltips(host, services) === tooltipsService && services.get('tooltips') === tooltipsService && host.MeteoNexaTooltips === undefined, 'tooltips install only in internal registry');
assert(installWeatherUtils(host, services) === weatherUtilsService && services.get('weatherUtils') === weatherUtilsService && host.MeteoNexaWeatherUtils === undefined, 'weather-utils install only in internal registry');
assert(installI18nPreferences(host, services) === i18nPreferencesService && services.get('i18nPreferences') === i18nPreferencesService && host.MeteoNexaI18nPreferences === undefined, 'i18n-preferences install only in internal registry');
assert([coreService, runtimeStateService, tooltipsService, weatherUtilsService, i18nPreferencesService].every(Object.isFrozen), 'core service surfaces immutable');

const previousI18n = globalThis.MeteoNexaI18n;
const previousText = globalThis.meteonexaText;
globalThis.MeteoNexaI18n = { state: { catalog: {}, supportedLanguages: [] } };
globalThis.meteonexaText = key => String(key || '');
const state = createRuntimeState({
    config: { DEFAULT_LOCATION: { name: 'Genova', admin1: 'Liguria', country: 'Italia', latitude: 44.4056, longitude: 8.9463 } },
    storage: {},
    loadJSON: (_key, fallback) => fallback,
    defaultSettings: {},
    defaultThresholds: {},
    defaultNotifications: {},
    defaultUiVisibility: {}
});
if (previousI18n === undefined) delete globalThis.MeteoNexaI18n; else globalThis.MeteoNexaI18n = previousI18n;
if (previousText === undefined) delete globalThis.meteonexaText; else globalThis.meteonexaText = previousText;
assert(state?.location?.name === 'Genova' && state?.radar && state.currentPage === 'home', 'runtime-state factory works as native module');

coreService.attachRuntimeState(state);
assert(coreService.getState().navigation.currentPage === 'home', 'core store projects authoritative runtime state');

const weather = createWeatherUtils({
    state,
    config: {},
    storage: {},
    saveJSON() {},
    appLocale: () => 'it-IT',
    t: key => String(key || ''),
    $: () => null,
    $$: () => []
});
assert(Object.isFrozen(weather) && typeof weather.normalizeLocation === 'function' && typeof weather.createPreviewWeather === 'function', 'weather utility factory works as native module');

const i18n = createI18nPreferences({
    state: { ...state, settings: { language: 'it' }, translations: {}, supportedLanguages: [{ code: 'it', label: 'IT' }] },
    storage: {},
    saveJSON() {},
    enhancedSelects: new Set(),
    $: () => null,
    $$: () => [],
    apiRequest: async () => ({}),
    requestSafeBackgroundSync() {},
    showToast() {},
    initializeEnhancedSelects() {},
    applySettings() {},
    withLoader: async (_title, _message, work) => work(),
    updateProfileUI() {},
    syncFavoriteUI() {},
    syncNotificationButton() {},
    renderRecentSearches() {},
    hasUsableLocation: () => false,
    updateSelectedLocationUI() {},
    setAuthStep() {},
    refreshAuthServerStatus() {},
    escapeHTML: value => String(value || '')
});
assert(Object.isFrozen(i18n) && typeof i18n.t === 'function' && typeof i18n.synchronizeRemotePreferences === 'function', 'i18n/preferences factory works as native module');

console.log('P2 core native ESM PASS');
