'use strict';
const CONFIG = window.METEONEXA_CONFIG;
const SERVICES = window.MeteoNexaServices;
if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
const RUNTIME_API = SERVICES.require('runtimeApi');
const APP_BUILD = '20.1';
const APP_RELEASE_LABEL = '20.1.1';
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const QUERY = new URLSearchParams(location.search);
const PREVIEW_MODE = QUERY.has('preview');
const APP_PREVIEW = QUERY.has('app') || QUERY.has('preview');
const STORAGE = Object.freeze({
    session: 'meteonexa_v3_session',
    location: 'meteonexa_v3_location',
    weather: 'meteonexa_v3_weather',
    air: 'meteonexa_v3_air',
    favorites: 'meteonexa_v3_favorites',
    settings: 'meteonexa_v3_settings',
    thresholds: 'meteonexa_v3_thresholds',
    recent: 'meteonexa_v3_recent',
    privacyNotice: 'meteonexa_privacy_notice_v2',
    notifications: 'meteonexa_notifications_v1',
    alertReads: 'meteonexa_alert_reads_v1',
    intelligenceSnapshot: 'meteonexa_v14_intelligence_snapshot',
    preferencePending: 'meteonexa_preferences_pending_v1',
    personalWeather: 'meteonexa_personal_weather_v1',
    briefingDaily: 'meteonexa_ai_briefing_daily_v1',
    briefingHistory: 'meteonexa_ai_briefing_history_v1',
    proactiveInsight: 'meteonexa_ai_proactive_insight_v1',
    personalPending: 'meteonexa_personal_weather_pending_v1',
    radarMotion: 'meteonexa_radar_motion_v1',
    modelWeights: 'meteonexa_model_weights_v1'
});
const SESSION_FLAGS = Object.freeze({ forceAuth: 'meteonexa_force_auth_v1', cacheReset: 'meteonexa_cache_reset_v1' });
const CACHE_SENTINEL = Object.freeze({ cacheName: 'mo-auth-sentinel-v1', requestUrl: './__mo_cache_sentinel_v1__', localMarker: 'meteonexa_cache_sentinel_expected_v1' });
let mobileFocusGuardUntil = 0;
let guestFocusGuardTimer = 0;
let guestTouchActivationArmed = false;
let guestTouchActivationAt = 0;
let buildChanged = false;
try {
    const previousBuild = localStorage.getItem('meteonexa_app_build');
    buildChanged = previousBuild !== APP_BUILD;
    if (buildChanged) {
        // Keep the last successful weather/air snapshot across releases so the
        // installed PWA remains useful offline immediately after an update.
        // Versioned code/data migrations must invalidate a cache explicitly
        // instead of deleting the user's last known forecast on every build.
        localStorage.removeItem('meteonexa_privacy_consent_v1');
        localStorage.setItem('meteonexa_app_build', APP_BUILD);
    }
}
catch { }
const CACHE_RESET_QUERY = 'cacheReset';
const requestedCacheReset = QUERY.has(CACHE_RESET_QUERY);
if (requestedCacheReset) {
    try {
        sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
        sessionStorage.setItem(SESSION_FLAGS.cacheReset, '1');
        localStorage.removeItem(STORAGE.session);
    } catch { }
    try {
        const cleanResetUrl = new URL(location.href);
        cleanResetUrl.searchParams.delete(CACHE_RESET_QUERY);
        history.replaceState(null, '', `${cleanResetUrl.pathname}${cleanResetUrl.search}${cleanResetUrl.hash}`);
    } catch { }
}
const DEFAULT_SETTINGS = Object.freeze({ unit: 'celsius', language: 'it', theme: 'system', refresh: 1, reduceMotion: false, radarMode: 'live' });
const DEFAULT_THRESHOLDS = Object.freeze({ rain: 70, wind: 60, heat: 35 });
const DEFAULT_NOTIFICATIONS = Object.freeze({ enabled: true, severe: true, rain: true, lastSent: {} });
const DEFAULT_UI_VISIBILITY = Object.freeze({
    'page.home': { guest: true, authenticated: true },
    'page.radar': { guest: true, authenticated: true },
    'page.favorites': { guest: true, authenticated: true },
    'page.details': { guest: true, authenticated: true },
    'page.intelligence': { guest: true, authenticated: true },
    'page.advanced': { guest: true, authenticated: true },
    'page.route': { guest: false, authenticated: true },
    'page.history': { guest: false, authenticated: true },
    'page.alerts': { guest: false, authenticated: false },
    'page.feedback': { guest: true, authenticated: true },
    'page.devices': { guest: false, authenticated: true },
    'feature.assistant': { guest: true, authenticated: true },
    'feature.notifications': { guest: false, authenticated: true },
    'feature.integrations': { guest: false, authenticated: true },
    'section.radar.archive': { guest: false, authenticated: true },
    'feature.impact': { guest: true, authenticated: true },
    'feature.nowcast.pro': { guest: true, authenticated: true },
    'feature.forecast.explain': { guest: true, authenticated: true },
    'feature.model.accuracy': { guest: false, authenticated: true },
    'feature.route.intelligence': { guest: false, authenticated: true },
    'feature.ai.briefing': { guest: false, authenticated: true },
    'feature.ai.proactive': { guest: false, authenticated: true },
    'feature.radar.predictive': { guest: true, authenticated: true },
    'feature.smart.demo': { guest: true, authenticated: true },
    'feature.smart.alerts': { guest: false, authenticated: true },
    'feature.official.alerts': { guest: true, authenticated: true },
    'feature.hyperlocal': { guest: false, authenticated: true },
    'feature.intelligence.consensus': { guest: true, authenticated: true },
    'feature.intelligence.skill': { guest: false, authenticated: true },
    'feature.intelligence.change': { guest: false, authenticated: true },
    'feature.intelligence.explainability': { guest: true, authenticated: true },
    'feature.intelligence.celltracking': { guest: false, authenticated: true },
    'feature.intelligence.decision': { guest: true, authenticated: true },
    'feature.intelligence.locations': { guest: false, authenticated: true },
    'feature.qa': { guest: false, authenticated: true }
});
const state = SERVICES.require('runtimeState').create({
    config: CONFIG,
    storage: STORAGE,
    loadJSON,
    buildChanged,
    defaultSettings: DEFAULT_SETTINGS,
    defaultThresholds: DEFAULT_THRESHOLDS,
    defaultNotifications: DEFAULT_NOTIFICATIONS,
    defaultUiVisibility: DEFAULT_UI_VISIBILITY
});
const enhancedSelects = new Map();
let pendingConfirmRequest = null;
const { initializeUiTooltips } = SERVICES.require('tooltips');
try { SERVICES.get('core')?.attachRuntimeState?.(state); } catch { }

function loadJSON(key, fallback) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    }
    catch {
        return fallback;
    }
}
function saveJSON(key, value) {
    try {
        if (value === null || value === undefined)
            localStorage.removeItem(key);
        else
            localStorage.setItem(key, JSON.stringify(value));
    }
    catch (error) {
        console.warn("" + meteonexaText("app.savejson.unable_save_local_data"), error);
    }
}
const {
    browserTheme, appLocale, t, translateDOM, initializeI18nObserver, persistLocalSettings,
    markPreferencesPending, loadRemotePreferences, saveRemotePreferences, synchronizeRemotePreferences,
    rebuildLanguageOptions, setWelcomeLanguageMenu, setSettingsLanguageMenu, changeApplicationLanguage
} = SERVICES.require('i18nPreferences').create({
    state, storage: STORAGE, saveJSON, enhancedSelects, $, $$,
    apiRequest: (...args) => apiRequest(...args),
    requestSafeBackgroundSync: (...args) => requestSafeBackgroundSync(...args),
    showToast: (...args) => showToast(...args),
    initializeEnhancedSelects: (...args) => initializeEnhancedSelects(...args),
    syncEnhancedSelect: (...args) => syncEnhancedSelect(...args),
    applySettings: (...args) => applySettings(...args),
    withLoader: (...args) => withLoader(...args),
    updateProfileUI: (...args) => updateProfileUI(...args),
    syncFavoriteUI: (...args) => syncFavoriteUI(...args),
    syncNotificationButton: (...args) => syncNotificationButton(...args),
    renderRecentSearches: (...args) => renderRecentSearches(...args),
    hasUsableLocation: (...args) => hasUsableLocation(...args),
    updateSelectedLocationUI: (...args) => updateSelectedLocationUI(...args),
    setAuthStep: (...args) => setAuthStep(...args),
    refreshAuthServerStatus: (...args) => refreshAuthServerStatus(...args),
    escapeHTML: (...args) => escapeHTML(...args)
});
const {
    escapeHTML,
    clamp,
    optionalFiniteNumber,
    sleep,
    debounce,
    capitalize,
    nowTime,
    isValidTimeZone,
    resolveLocationTimeZone,
    formatUtcOffsetSeconds,
    timeZoneOffsetLabel,
    formatLocationLocalTime,
    formatTimeZoneName,
    locationTimeZoneSummary,
    applyWeatherTimeZoneMetadata,
    refreshTimeZoneLabels,
    localizedLocationPart,
    fullLocationLabel,
    shortLocationLabel,
    locationKey,
    normalizeLocationIdentityPart,
    locationIdentity,
    sameLocation,
    uniqueLocations,
    formatDay,
    formatShortDate,
    formatClock,
    localDateHourKey,
    localSeriesIndex,
    windDirection,
    convertTemp,
    temperature,
    unitLabel,
    metricNoteHumidity,
    metricNotePressure,
    metricNoteVisibility,
    uvLabel,
    weatherMeta,
    weatherArt,
    normalizeLocation,
    createPreviewWeather,
    createPreviewAir
} = SERVICES.require('weatherUtils').create({
    state, config: CONFIG, storage: STORAGE, saveJSON, appLocale, t, $, $$
});
let loaderSafetyTimer = null;
let loaderActionButton = null;
let loaderActionButtonWasDisabled = false;
let lastUserActionButton = null;
let lastUserActionAt = 0;
document.addEventListener('click', event => {
    const button = event.target?.closest?.('button');
    if (!button) return;
    lastUserActionButton = button;
    lastUserActionAt = performance.now();
}, true);
function clearNavigationLoadingArtifacts() {
    $$('.nav-link, .mobile-nav-link, [data-page]').forEach(button => {
        const hadLoader = button.classList.contains('button-loading');
        if (hadLoader) button.classList.remove('button-loading');
        if (button.getAttribute('aria-busy') === 'true') button.removeAttribute('aria-busy');
        // Navigation visibility is controlled with hidden/access rules, never disabled.
        if (hadLoader && button.disabled) button.disabled = false;
    });
}
function setActionButtonLoading(active) {
    clearNavigationLoadingArtifacts();
    if (active) {
        const candidate = lastUserActionButton && performance.now() - lastUserActionAt < 650 ? lastUserActionButton : null;
        // Navigation has its own global loader; never turn a menu item into a disabled spinner.
        if (!candidate || candidate.disabled || candidate.matches('[data-no-preloader], [data-page], .nav-link, .mobile-nav-link')) return;
        loaderActionButton = candidate;
        loaderActionButtonWasDisabled = candidate.disabled;
        candidate.classList.add('button-loading');
        candidate.setAttribute('aria-busy', 'true');
        candidate.disabled = true;
        return;
    }
    if (!loaderActionButton) return;
    loaderActionButton.classList.remove('button-loading');
    loaderActionButton.removeAttribute('aria-busy');
    loaderActionButton.disabled = loaderActionButtonWasDisabled;
    loaderActionButton = null;
    loaderActionButtonWasDisabled = false;
}
function setLoader(active, title = "" + meteonexaText("app.setloader.weather_update"), copy = "" + meteonexaText("app.setloader.preparing_latest_data")) {
    const loader = $('#global-loader');
    if (!loader)
        return;
    const activeDialog = $$('dialog.app-dialog[open]').at(-1);
    if (active && activeDialog && loader.parentElement !== activeDialog)
        activeDialog.appendChild(loader);
    if (!active && loader.parentElement !== document.body)
        document.body.appendChild(loader);
    $('#loader-title').textContent = t(title);
    $('#loader-copy').textContent = t(copy);
    loader.classList.toggle('active', active);
    loader.setAttribute('aria-hidden', String(!active));
    document.body.classList.toggle('operation-loading', active);
    if (active) setActionButtonLoading(true);
    else setActionButtonLoading(false);
    if (loaderSafetyTimer) {
        clearTimeout(loaderSafetyTimer);
        loaderSafetyTimer = null;
    }
    if (active) {
        loaderSafetyTimer = setTimeout(() => {
            state.loaderDepth = 0;
            forceReleaseGlobalLoader();
        }, 8000);
    }
}
function forceReleaseGlobalLoader() {
    if (loaderSafetyTimer) {
        clearTimeout(loaderSafetyTimer);
        loaderSafetyTimer = null;
    }
    const loader = $('#global-loader');
    loader?.classList.remove('active');
    loader?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('operation-loading');
    try { setActionButtonLoading(false); }
    catch (error) {
        console.warn('LOADER_ACTION_RELEASE_FAILED', error);
        loaderActionButton = null;
        loaderActionButtonWasDisabled = false;
        clearNavigationLoadingArtifacts();
    }
    if (loader && loader.parentElement !== document.body) document.body.appendChild(loader);
}
async function withLoader(title, copy, task, minDuration = 420) {
    const startedAt = performance.now();
    state.loaderDepth += 1;
    try {
        setLoader(true, title, copy);
        return await task();
    }
    finally {
        const remaining = minDuration - (performance.now() - startedAt);
        if (remaining > 0) await sleep(remaining);
        state.loaderDepth = Math.max(0, state.loaderDepth - 1);
        if (state.loaderDepth === 0) {
            try { setLoader(false); }
            catch (error) {
                console.warn('LOADER_RELEASE_FAILED', error);
                forceReleaseGlobalLoader();
            }
        }
    }
}
SERVICES.publish('loader', Object.freeze({
    run: (title, copy, task, minDuration = 420) => withLoader(title, copy, task, minDuration),
    active: () => state.loaderDepth > 0
}));
async function fetchJSON(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeout || CONFIG.REQUEST_TIMEOUT_MS);
    try {
        const target = new URL(url, location.href);
        const sameOrigin = target.origin === location.origin;
        // Public account-independent endpoints (forecast fusion/severe monitor)
        // must be able to opt out of both cookies and device-proof headers even
        // though they are same-origin. Older builds accepted `credentials:omit`
        // at call sites but silently ignored it here.
        const credentialMode = options.credentials || (sameOrigin ? 'same-origin' : 'omit');
        const includeSecurityHeaders = sameOrigin && credentialMode !== 'omit' && options.securityHeaders !== false;
        const response = await fetch(target.toString(), {
            signal: controller.signal,
            headers: { Accept: 'application/json', ...(includeSecurityHeaders ? (SERVICES.get('security')?.headers?.() || {}) : {}) },
            credentials: credentialMode,
            cache: 'no-store'
        });
        if (!response.ok)
            throw new Error(meteonexaText("suite.apimessage.service_unavailable_value", { p0: response.status }));
        return await response.json();
    }
    catch (error) {
        if (error?.name === 'AbortError')
            throw new Error(meteonexaText('network.request.timeout'));
        if (error instanceof TypeError)
            throw new Error(meteonexaText('network.request.failed'));
        throw error;
    }
    finally {
        clearTimeout(timeout);
    }
}
function detectDevice() {
    const ua = navigator.userAgent.toLowerCase();
    const width = innerWidth;
    let device = width < 600 ? 'mobile' : width < 1025 ? 'tablet' : 'desktop';
    if (/ipad|tablet/.test(ua))
        device = 'tablet';
    else if (/iphone|android.+mobile/.test(ua))
        device = 'mobile';
    const platform = /iphone|ipad|mac/.test(ua) ? 'apple' : /android/.test(ua) ? 'android' : /windows/.test(ua) ? 'windows' : 'other';
    const standalone = Boolean(window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);
    document.documentElement.dataset.device = device;
    document.documentElement.dataset.platform = platform;
    document.documentElement.dataset.displayMode = standalone ? 'standalone' : 'browser';
    document.documentElement.classList.toggle('is-standalone', standalone);
    document.body?.classList.toggle('is-standalone', standalone);
}
function syncEnhancedSelect(id, value) {
    const select = document.getElementById(id);
    if (!select)
        return;
    select.value = String(value);
    const instance = enhancedSelects.get(id);
    if (instance)
        instance.setChoiceByValue(String(value));
}
function initializeEnhancedSelects() {
    if (!window.Choices) {
        document.documentElement.classList.add('choices-unavailable');
        return;
    }
    const briefingHourSelect = document.getElementById('briefing-hour');
    if (briefingHourSelect && personalWeatherPrefs?.briefingHourSet !== true)
        briefingHourSelect.value = String(currentLocationHour());
    $$('.enhanced-select').forEach(select => {
        if (enhancedSelects.has(select.id))
            return;
        const instance = new window.Choices(select, {
            searchEnabled: false,
            shouldSort: false,
            itemSelectText: '',
            allowHTML: false,
            position: select.dataset.choicePosition || 'auto'
        });
        const container = instance.containerOuter?.element;
        container?.classList.add('meteonexa-choices');
        const updatePlacement = () => {
            if (!container)
                return;
            container.classList.remove('is-flipped');
            const forced = select.dataset.choicePosition;
            if (forced === 'top') {
                container.classList.add('is-flipped');
                return;
            }
            requestAnimationFrame(() => {
                const dropdown = container.querySelector('.choices__list--dropdown, .choices__list[aria-expanded]');
                if (!dropdown)
                    return;
                const footer = select.closest('.dialog-layout')?.querySelector('.dialog-footer');
                const containerRect = container.getBoundingClientRect();
                const dropdownHeight = Math.max(dropdown.scrollHeight || 0, dropdown.getBoundingClientRect().height || 0, 150);
                const lowerLimit = footer ? footer.getBoundingClientRect().top - 10 : window.innerHeight - 12;
                const availableBelow = lowerLimit - containerRect.bottom;
                const availableAbove = containerRect.top - 12;
                if (availableBelow < dropdownHeight && availableAbove > availableBelow)
                    container.classList.add('is-flipped');
            });
        };
        select.addEventListener('showDropdown', () => {
            select.closest('.glass-panel')?.classList.add('select-dropdown-open');
            updatePlacement();
        });
        select.addEventListener('hideDropdown', () => {
            select.closest('.glass-panel')?.classList.remove('select-dropdown-open');
            if (select.dataset.choicePosition !== 'top')
                container?.classList.remove('is-flipped');
        });
        enhancedSelects.set(select.id, instance);
    });
}
function applyThemePreference(preference = state.settings.theme, { persist = true, notify = true } = {}) {
    const normalized = ['system', 'light', 'dark'].includes(String(preference)) ? String(preference) : 'system';
    const resolved = normalized === 'system' ? browserTheme() : normalized;
    state.settings.theme = normalized;
    document.documentElement.dataset.theme = resolved;
    document.documentElement.style.colorScheme = resolved;
    if (document.body)
        document.body.dataset.theme = resolved;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolved === 'light' ? '#eef4f9' : '#06152f');
    try {
        const i18nState = SERVICES.get('i18n')?.state;
        if (i18nState) {
            i18nState.theme = normalized;
            i18nState.resolvedTheme = resolved;
        }
    }
    catch { }
    syncEnhancedSelect('theme-setting', normalized);
    if (persist)
        persistLocalSettings();
    if (notify)
        document.dispatchEvent(new CustomEvent('meteonexa:theme-changed', { detail: { preference: normalized, resolved } }));
    return resolved;
}
function applySettings({ rerender = true } = {}) {
    const theme = applyThemePreference(state.settings.theme, { persist: false, notify: false });
    document.title = t("app.applysettings.meteonexa_weather_forecasts_radar_alerts");
    document.body.classList.toggle('reduce-motion', Boolean(state.settings.reduceMotion));
    syncEnhancedSelect("temperature-unit", state.settings.unit);
    syncEnhancedSelect('language-setting', state.settings.language);
    $$('[data-language-setting]').forEach(select => {
        if (select.id !== 'language-setting')
            select.value = state.settings.language;
    });
    syncEnhancedSelect('theme-setting', state.settings.theme);
    syncEnhancedSelect('refresh-setting', String(state.settings.refresh));
    $('#reduce-motion-setting').checked = Boolean(state.settings.reduceMotion);
    if ($('#radar-mode-setting'))
        syncEnhancedSelect("radar-mode-setting", state.settings.radarMode || 'live');
    persistLocalSettings();
    scheduleRefresh();
    if (rerender && state.weather)
        renderAll();
    translateDOM(document);
}
function setOnboardingView(viewId) {
    $$('.onboarding-view').forEach(view => view.classList.toggle('active', view.id === viewId));
}
function persistSessionSafely(session = state.session) {
    if (!session) {
        localStorage.removeItem(STORAGE.session);
        return;
    }
    const persisted = { ...session };
    delete persisted.email; // personal address is memory-only in the browser
    saveJSON(STORAGE.session, persisted);
}
function armGuestMobileFocusGuard(duration = 1800) {
    mobileFocusGuardUntil = Math.max(mobileFocusGuardUntil, Date.now() + duration);
    try { document.activeElement?.blur?.(); } catch { }
    const input = $('#onboarding-city');
    const locationView = $('#location-view');
    if (locationView) locationView.inert = true;
    if (input && !input.dataset.guestFocusGuard) {
        input.dataset.guestFocusGuard = '1';
        input.readOnly = true;
        input.disabled = true;
        input.setAttribute('aria-disabled','true');
    }
    document.documentElement.classList.add('mobile-focus-guard');
    clearTimeout(guestFocusGuardTimer);
    guestFocusGuardTimer = window.setTimeout(() => {
        mobileFocusGuardUntil = 0;
        document.documentElement.classList.remove('mobile-focus-guard');
        if (locationView) locationView.inert = false;
        const guarded = $('#onboarding-city');
        if (guarded?.dataset.guestFocusGuard === '1') {
            guarded.disabled = false;
            guarded.readOnly = false;
            guarded.removeAttribute('aria-disabled');
            delete guarded.dataset.guestFocusGuard;
        }
        try { document.activeElement?.blur?.(); } catch { }
    }, duration);
}
function resolveLoginWeatherScene(weather = state.weather) {
    const current = weather?.current || null;
    const fetchedAt = Number(weather?.fetchedAt || 0);
    const freshEnough = fetchedAt > 0 && Date.now() - fetchedAt <= 6 * 3600000;
    if (!current || !freshEnough) {
        const hour = new Date().getHours();
        return hour >= 20 || hour < 6 ? 'night' : 'neutral';
    }
    const code = Number(current.weather_code ?? -1);
    const isDay = Number(current.is_day ?? 1) === 1;
    if ([95,96,99].includes(code)) return 'storm';
    if ([71,73,75,77,85,86].includes(code)) return 'snow';
    if ([51,53,55,56,57,61,63,65,66,67,80,81,82].includes(code) || Number(current.rain || current.precipitation || 0) > 0) return 'rain';
    if ([45,48].includes(code)) return 'fog';
    if ([1,2,3].includes(code)) return isDay ? 'cloud' : 'night-cloud';
    if (code === 0) return isDay ? 'sun' : 'night';
    return isDay ? 'neutral' : 'night';
}
function applyLoginWeatherBackdrop(weather = state.weather) {
    const welcome = $('#welcome');
    if (!welcome) return;
    const scene = resolveLoginWeatherScene(weather);
    welcome.dataset.loginWeather = scene;
    const fetchedAt = Number(weather?.fetchedAt || 0);
    welcome.dataset.loginWeatherSource = weather?.current && fetchedAt > 0 && Date.now() - fetchedAt <= 6 * 3600000 ? 'local-cache' : 'neutral';
    welcome.dataset.loginWeatherReady = 'true';
    const versionBadge = $('#login-version-badge');
    if (versionBadge) versionBadge.textContent = `v${APP_RELEASE_LABEL}`;
}
async function startLocalSession(profile, { touchGuard = false } = {}) {
    if (touchGuard) armGuestMobileFocusGuard(2200);
    const type = ['guest', 'email'].includes(String(profile?.type || '')) ? String(profile.type) : 'guest';
    const enterSession = async () => {
        try { sessionStorage.removeItem(SESSION_FLAGS.forceAuth); } catch { }
        state.authServerVerified = type === 'email' && profile?.serverVerified === true;
        state.session = {
            type,
            name: String(profile?.name || '').slice(0, 120),
            ...(type === 'email' ? { verified: profile?.verified === true, ...(validEmail(profile?.email || '') ? { email: String(profile.email).trim().toLowerCase() } : {}) } : {}),
            at: Date.now()
        };
        persistSessionSafely();
        try { SERVICES.get('authDomain')?.publish?.(state.session, state.authServerVerified); } catch { }
        let accountHydration = null;
        if (type === 'email' && state.authServerVerified === true)
            accountHydration = await hydrateAuthenticatedAccountAfterLogin({ adoptSavedLocation: true });
        const canResumeAccount = type === 'email' && state.authServerVerified === true && Boolean(accountHydration?.hadLocalLocation || accountHydration?.adoptedLocation);
        if (canResumeAccount) {
            await showApp({ refresh: true, waitForWeather: true, forceWeather: true });
            return;
        }
        setOnboardingView('location-view');
        if (state.location && Number.isFinite(Number(state.location.latitude))) updateSelectedLocationUI();
    };
    if (type === 'guest') {
        // Guest entry is entirely local. Never make a touch/pointer transition
        // depend on the global loader or on any network request.
        await enterSession();
    } else {
        await withLoader("" + meteonexaText("app.entersession.signing"), "" + meteonexaText("app.entersession.preparing_weather_space"), enterSession, 650);
    }
    if (touchGuard) armGuestMobileFocusGuard(1400);
}
function clearFieldError(inputId) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(`${inputId}-error`);
    input?.classList.remove('is-invalid');
    input?.removeAttribute('aria-invalid');
    if (error)
        error.textContent = '';
}
function setFieldError(inputId, message) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(`${inputId}-error`);
    input?.classList.add('is-invalid');
    input?.setAttribute('aria-invalid', 'true');
    if (error)
        error.textContent = t(message);
    input?.focus();
}
function validEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(String(value).trim());
}
function isAuthenticatedSession() {
    // A missing/unknown local session is never treated as authenticated.
    return state.session?.type === 'email';
}
function hasVerifiedServerSession() {
    return isAuthenticatedSession() && state.authServerVerified === true;
}
function handleAuthRequiredResponse({ notify = true } = {}) {
    const wasAuthenticated = isAuthenticatedSession();
    state.authServerVerified = false;
    if (wasAuthenticated) {
        state.session = null;
        persistSessionSafely(null);
        try { sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1'); } catch { }
    }
    try { applyGuestAccessUI(); } catch { }
    try { syncPrivacyContextCopy(); } catch { }
    try { updateProfileUI(); } catch { }
    const now = Date.now();
    if (notify && now - Number(state.authRequiredHandledAt || 0) > 2500) {
        state.authRequiredHandledAt = now;
        showToast(meteonexaText('guest.login.required.title'), meteonexaText('api.security.auth_required'), 'warning', 5200);
    }
    try { SERVICES.get('authDomain')?.required?.(); } catch { }
    document.dispatchEvent(new CustomEvent('meteonexa:auth-required'));
}
SERVICES.publish('auth', Object.freeze({
    isAuthenticated: () => isAuthenticatedSession(),
    serverVerified: () => hasVerifiedServerSession(),
    handleAuthRequired: () => handleAuthRequiredResponse()
}));

function apiResponseMessage(data, status) {
    const raw = String(data?.message || '').trim();
    if (raw) {
        const translated = t(raw);
        return translated && translated !== raw ? translated : raw;
    }
    return meteonexaText("app.apiresponsemessage.server_error_value", { status });
}
async function apiRequest(path, payload = null, requestOptions = {}) {
    const targetUrl = new URL(path, window.location.href);
    const sameOrigin = targetUrl.origin === window.location.origin;
    const securityHeaders = sameOrigin ? (SERVICES.get('security')?.headers?.() || {}) : {};
    const options = {
        method: payload ? 'POST' : 'GET',
        headers: { Accept: 'application/json', ...securityHeaders, ...(sameOrigin && requestOptions?.headers ? requestOptions.headers : {}) },
        cache: 'no-store',
        credentials: sameOrigin ? 'same-origin' : 'omit'
    };
    if (payload) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
    }
    const controller = new AbortController();
    options.signal = controller.signal;
    const timeoutMs = clamp(Number(requestOptions?.timeout || 6500), 1000, 60000);
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(path, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok === false) {
            if (response.status === 401 && data?.code === 'AUTH_REQUIRED')
                handleAuthRequiredResponse({ notify: requestOptions?.notifyAuthRequired !== false });
            const error = new Error(apiResponseMessage(data, response.status));
            error.code = data.code || `HTTP_${response.status}`;
            error.details = data;
            throw error;
        }
        return data;
    }
    catch (error) {
        if (error.name === 'AbortError') {
            const timeoutError = new Error(meteonexaText("app.apirequest.sign_server_not_responding_try_again_shortly"));
            timeoutError.code = 'TIMEOUT';
            throw timeoutError;
        }
        if (error instanceof TypeError) {
            const networkError = new Error(meteonexaText('network.request.failed'));
            networkError.code = 'NETWORK_ERROR';
            throw networkError;
        }
        throw error;
    }
    finally {
        clearTimeout(timeout);
    }
}
const {
    isGuestSession, uiVisibilityMode, isUiFeatureVisible, firstVisiblePage, applyUiVisibility,
    loadUiVisibilityConfig, showGuestAccessNotice, applyGuestAccessUI, goToPage
} = SERVICES.require('navigation').createController({
    state, DEFAULT_UI_VISIBILITY, $, $$, clearNavigationLoadingArtifacts,
    isAuthenticatedSession: (...args) => isAuthenticatedSession(...args),
    syncPrivacyContextCopy: (...args) => syncPrivacyContextCopy(...args),
    apiRequest: (...args) => apiRequest(...args),
    showToast: (...args) => showToast(...args),
    meteonexaText: (...args) => meteonexaText(...args),
    openNotificationCenter: (...args) => openNotificationCenter(...args),
    setMobileSidebarOpen: (...args) => setMobileSidebarOpen(...args),
    renderFavorites: (...args) => renderFavorites(...args),
    ensureRadar: (...args) => ensureRadar(...args),
    drawAllDetailCharts: (...args) => drawAllDetailCharts(...args),
    refreshBugReportContext: (...args) => refreshBugReportContext(...args),
    loadHistory: (...args) => loadHistory(...args),
    renderIntelligence: (...args) => renderIntelligence(...args),
    loadIntelligence: (...args) => loadIntelligence(...args),
    scheduleThresholdsPanelFollow: (...args) => scheduleThresholdsPanelFollow(...args),
    withLoader: (...args) => withLoader(...args)
});
const {
    accountSyncDeviceId, pushAccountFavorites, scheduleAccountFavoritesPush,
    pullAccountSync, saveAccountActivityProfile, hydrateAuthenticatedAccountAfterLogin
} = SERVICES.require('accountDomain').create({
    state, storage: STORAGE, saveJSON, loadJSON,
    isGuestSession: (...args) => isGuestSession(...args),
    hasVerifiedServerSession: (...args) => hasVerifiedServerSession(...args),
    apiRequest: (...args) => apiRequest(...args),
    uniqueLocations: (...args) => uniqueLocations(...args),
    normalizeLocation: (...args) => normalizeLocation(...args),
    syncFavoriteUI: (...args) => syncFavoriteUI(...args),
    renderFavorites: (...args) => renderFavorites(...args),
    updateSelectedLocationUI: (...args) => updateSelectedLocationUI(...args),
    meteonexaText: (...args) => meteonexaText(...args)
});
const {
    setAuthStep, stopAuthResendTimer, startAuthResendTimer, refreshAuthServerStatus,
    reconcileEmailServerSession, openAuthDialog, beginEmailAccessFlow, requestEmailCode, verifyEmailCode
} = SERVICES.require('authFlow').create({
    state, storage: STORAGE, sessionFlags: SESSION_FLAGS, $, t,
    apiRequest: (...args) => apiRequest(...args),
    escapeHTML: (...args) => escapeHTML(...args),
    validEmail: (...args) => validEmail(...args),
    persistSessionSafely: (...args) => persistSessionSafely(...args),
    showToast: (...args) => showToast(...args),
    clearFieldError: (...args) => clearFieldError(...args),
    setFieldError: (...args) => setFieldError(...args),
    withLoader: (...args) => withLoader(...args),
    startLocalSession: (...args) => startLocalSession(...args)
});
const {
    updateSelectedLocationUI, getCurrentLocationData, locationErrorMessage, detectLocation,
    useCurrentLocationFromSearch, searchCities, selectOnboardingLocation, syncFavoriteUI,
    isFavorite, toggleCurrentFavorite, renderFavorites, addRecent, setLocation, renderRecentSearches,
    openSearch, handleSearchSelection, clearCommandSearch, selectCommandLocation,
    performCommandSearch, performGlobalSearch
} = SERVICES.require('locations').create({
    state, CONFIG, STORAGE, PREVIEW_MODE, $, $$, meteonexaText, escapeHTML, normalizeLocation,
    fullLocationLabel, shortLocationLabel, locationTimeZoneSummary, localizedLocationPart,
    resolveLocationTimeZone, saveJSON, fetchJSON, withLoader, showToast, weatherMeta, weatherArt,
    temperature, uniqueLocations, sameLocation, locationKey, confirmAction, scheduleAccountFavoritesPush,
    goToPage: (...args) => goToPage(...args),
    loadWeather: (...args) => loadWeather(...args)
});
const {
    isIOSDevice, isAndroidDevice, isHandheldOrTabletDevice, isStandalonePWA,
    notificationPermission, notificationEnvironment, updateNotificationCenterUI,
    updateProfileNotificationBadge, syncNotificationButton, renderNotificationOfficialAlert,
    setNotificationCenterAudience, formatNotificationInboxTime, renderNotificationInbox,
    loadNotificationInbox, updateNotificationInbox, openNotificationCenter,
    sendDeviceNotification, enableNotifications, testDeviceNotification,
    saveNotificationPreferences, notificationCandidates, evaluateLocalWeatherNotifications,
    updatePwaSettingsStatus, registerPWA, installPWA, bindNotificationEvents
} = SERVICES.require('notifications').create({
    state, STORAGE, APP_BUILD, $, meteonexaText, escapeHTML, appLocale, t, saveJSON,
    apiRequest: (...args) => apiRequest(...args),
    accountSyncDeviceId: (...args) => accountSyncDeviceId(...args),
    isGuestSession: (...args) => isGuestSession(...args),
    showGuestAccessNotice: (...args) => showGuestAccessNotice(...args),
    withLoader: (...args) => withLoader(...args),
    showToast: (...args) => showToast(...args),
    shortLocationLabel: (...args) => shortLocationLabel(...args),
    locationKey: (...args) => locationKey(...args),
    temperature: (...args) => temperature(...args),
    officialAlertStateId: (...args) => officialAlertStateId(...args),
    isAlertRead: (...args) => isAlertRead(...args),
    officialAlertReadable: (...args) => officialAlertReadable(...args),
    officialAlertLifecycleText: (...args) => officialAlertLifecycleText(...args),
    formatOfficialAlertTime: (...args) => formatOfficialAlertTime(...args),
    markAlertRead: (...args) => markAlertRead(...args),
    updateAlertBadgeCount: (...args) => updateAlertBadgeCount(...args),
    renderAlerts: (...args) => renderAlerts(...args),
    loadHomeOfficialAlerts: (...args) => loadHomeOfficialAlerts(...args),
    updateNetworkStatus: (...args) => updateNetworkStatus(...args)
});

function buildWeatherURL(locationData = state.location) {
    const params = new URLSearchParams({
        latitude: String(locationData.latitude), longitude: String(locationData.longitude),
        current: 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,rain,weather_code,cloud_cover,surface_pressure,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
        hourly: 'temperature_2m,apparent_temperature,dew_point_2m,precipitation_probability,precipitation,weather_code,relative_humidity_2m,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m,surface_pressure,uv_index,cloud_cover,is_day',
        minutely_15: 'precipitation,rain,weather_code,temperature_2m,wind_gusts_10m',
        daily: 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,sunrise,sunset,daylight_duration,sunshine_duration,uv_index_max,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max',
        timezone: 'auto', forecast_days: '10', wind_speed_unit: 'kmh'
    });
    return `${CONFIG.WEATHER_API}?${params}`;
}
function buildAirURL(locationData = state.location) {
    const params = new URLSearchParams({
        latitude: String(locationData.latitude), longitude: String(locationData.longitude),
        hourly: 'european_aqi,pm10,pm2_5,ozone,nitrogen_dioxide,carbon_monoxide,alder_pollen,birch_pollen,grass_pollen',
        timezone: 'auto', forecast_days: '3'
    });
    return `${CONFIG.AIR_QUALITY_API}?${params}`;
}
function setArticleInlineLoading(target, busy, label = '') {
    const root = typeof target === 'string' ? $(target) : target;
    if (!root || !root.matches?.('article')) return;
    let loader = root.querySelector(':scope > .article-inline-preloader');
    if (busy) {
        if (!loader) {
            loader = document.createElement('div');
            loader.className = 'article-inline-preloader';
            loader.setAttribute('role', 'status');
            loader.setAttribute('aria-live', 'polite');
            loader.innerHTML = '<span class="article-inline-spinner" aria-hidden="true"></span><strong></strong>';
            root.append(loader);
        }
        loader.querySelector('strong').textContent = label || meteonexaText('panel.loading');
        loader.hidden = false;
        root.classList.add('is-panel-loading');
        root.setAttribute('aria-busy', 'true');
    } else {
        if (loader) loader.hidden = true;
        root.classList.remove('is-panel-loading');
        root.removeAttribute('aria-busy');
    }
}
SERVICES.publish('panelLoader', Object.freeze({ set: setArticleInlineLoading }));

function severeWeatherLocationKey(locationData = state.location) {
    return `${Number(locationData?.latitude || 0).toFixed(3)}:${Number(locationData?.longitude || 0).toFixed(3)}`;
}
function severeWeatherEventRank(event = {}) {
    const severity = ({ red: 3, orange: 2, yellow: 1 })[String(event.severity || '').toLowerCase()] || 0;
    const type = ({ hail: 8, storm: 7, snow: 6, ice: 5, wind: 4, fog: 3, heat: 2 })[String(event.type || '').toLowerCase()] || 1;
    return severity * 100 + type;
}
function renderHomeSevereWeather() {
    const root = $('#home-severe-alert');
    if (!root) return;
    const monitor = state.severeWeather;
    const expectedKey = severeWeatherLocationKey();
    const age = monitor?.fetchedAt ? Date.now() - Number(monitor.fetchedAt) : Infinity;
    const usable = monitor?.authoritative === true && monitor?.degraded !== true
        && monitor?.locationKey === expectedKey && age <= 8 * 60 * 1000;
    const events = usable && Array.isArray(monitor.events) ? [...monitor.events] : [];
    if (!events.length) { root.hidden = true; return; }
    events.sort((a, b) => severeWeatherEventRank(b) - severeWeatherEventRank(a));
    const event = events[0] || {};
    root.hidden = false;
    root.dataset.severity = ['red','orange','yellow'].includes(String(event.severity || '')) ? String(event.severity) : 'yellow';
    const title = $('#home-severe-alert-title'), copy = $('#home-severe-alert-copy'), meta = $('#home-severe-alert-meta');
    if (title) title.textContent = String(event.title || meteonexaText('home.severe.fallback.title'));
    if (copy) copy.textContent = String(event.body || meteonexaText('home.severe.fallback.copy'));
    if (meta) {
        const eta = optionalFiniteNumber(event.etaMinutes);
        const startsAt = String(event.startsAt || '');
        const when = Number.isFinite(eta) && eta >= 0 && eta <= 180
            ? (eta <= 0 ? meteonexaText('home.severe.now') : meteonexaText('home.severe.arrival',{minutes:Math.round(eta)}))
            : (startsAt ? meteonexaText('home.severe.starts',{time:formatOfficialAlertTime(startsAt)}) : '');
        const confidence = Number(event.confidence);
        const confidenceText = Number.isFinite(confidence) ? meteonexaText('home.severe.confidence',{value:Math.round(confidence)}) : '';
        const votes = Number(event.modelVotes), available = Number(event.modelsAvailable), agreement = Number(event.modelAgreementPct);
        const modelText = Number.isFinite(votes) && Number.isFinite(available) && available > 0 && Number.isFinite(agreement)
            ? meteonexaText('home.severe.models',{votes:Math.round(votes),available:Math.round(available),agreement:Math.round(agreement)}) : '';
        meta.textContent = [when, confidenceText, modelText, meteonexaText('home.severe.predictive')].filter(Boolean).join(' · ');
    }
}
async function loadSevereWeatherMonitor({ force = false } = {}) {
    if (!hasUsableLocation()) return null;
    if (state.severeWeather.request) return state.severeWeather.request;
    const key = severeWeatherLocationKey();
    const age = state.severeWeather.fetchedAt ? Date.now() - state.severeWeather.fetchedAt : Infinity;
    if (!force && state.severeWeather.locationKey === key && age < 90 * 1000) {
        renderHomeSevereWeather();
        return state.severeWeather;
    }
    if (PREVIEW_MODE || !navigator.onLine) {
        renderHomeSevereWeather();
        return state.severeWeather;
    }
    const task = (async () => {
        const params = new URLSearchParams({
            lat: String(Number(state.location.latitude).toFixed(3)),
            lon: String(Number(state.location.longitude).toFixed(3)),
            lang: String(state.settings.language || 'it').slice(0,2)
        });
        try {
            const result = await fetchJSON(`api/weather/severe.php?${params}`, { timeout: 25000, credentials: 'omit' });
            const monitor = result?.monitor || {};
            state.severeWeather.events = Array.isArray(monitor.events) ? monitor.events : [];
            state.severeWeather.authoritative = monitor.authoritative === true;
            state.severeWeather.degraded = monitor.degraded === true;
            state.severeWeather.fetchedAt = Date.now();
            state.severeWeather.locationKey = key;
            renderHomeSevereWeather();
            if (state.severeWeather.authoritative && !state.severeWeather.degraded && state.severeWeather.events.length
                && !isGuestSession() && personalWeatherPrefs.proactiveEnabled === true) {
                setTimeout(() => maybeGenerateProactiveInsight({ manual: false }).catch(() => {}), 250);
            }
            return state.severeWeather;
        } catch (error) {
            console.warn('SEVERE_WEATHER_MONITOR_FAILED', error);
            // Fail silent in Panoramica. Never keep a stale severe card visible
            // after the evidence source has failed beyond its short freshness TTL.
            if (Date.now() - Number(state.severeWeather.fetchedAt || 0) > 8 * 60 * 1000) {
                state.severeWeather.events = [];
                state.severeWeather.authoritative = false;
                state.severeWeather.degraded = true;
                state.severeWeather.locationKey = key;
                renderHomeSevereWeather();
            }
            return state.severeWeather;
        }
    })();
    state.severeWeather.request = task;
    try { return await task; }
    finally { if (state.severeWeather.request === task) state.severeWeather.request = null; }
}
function scheduleSevereWeatherMonitor() {
    clearInterval(state.severeWeather.timer);
    state.severeWeather.timer = window.setInterval(() => {
        if (!document.hidden && $('#weather-app')?.hidden === false) loadSevereWeatherMonitor({ force: true }).catch(()=>{});
    }, 2 * 60 * 1000);
}

function officialAlertEventId(raw = '') {
    const text = String(raw || '');
    const map = [[/thunder|tempor/i,'thunderstorm'],[/rain|piogg/i,'rain'],[/snow|neve/i,'snow'],[/wind|vento/i,'wind'],[/ice|ghiacci/i,'ice'],[/fog|nebb/i,'fog'],[/heat|high temperature|caldo/i,'heat']];
    return (map.find(([rx]) => rx.test(text)) || [])[1] || 'weather';
}
function officialAlertReadable(warning = {}) {
    const raw = String(warning.title || warning.officialTitle || '').trim();
    const severityRaw = String(warning.severity || '').toLowerCase();
    const colorMatch = raw.match(/\b(yellow|orange|red)\b/i);
    const severity = ['yellow','orange','red'].includes(severityRaw) ? severityRaw : String(colorMatch?.[1] || 'yellow').toLowerCase();
    const eventId = officialAlertEventId(raw);
    const areaMatch = raw.match(/(?:issued\s+for\s+italy\s*[-–:]\s*|italy\s*[-–:]\s*)(.+)$/i);
    const area = areaMatch?.[1] ? String(areaMatch[1]).replace(/\s+warning.*$/i,'').trim() : '';
    const level = meteonexaText(`advanced.official.level.${severity}`);
    const event = meteonexaText(`advanced.official.event.${eventId}`);
    const label = area ? meteonexaText('advanced.official.summary.area',{level,event,area}) : meteonexaText('advanced.official.summary',{level,event});
    return { label, severity, eventId, area };
}
function officialAlertLifecycleText(warning = {}) {
    const life = warning?.lifecycle || {};
    const current = String(warning?.severity || 'yellow').toLowerCase();
    const previous = String(life.previousSeverity || '').toLowerCase();
    const parts = [];
    if (previous && previous !== current && ['yellow','orange','red'].includes(previous) && ['yellow','orange','red'].includes(current)) {
        const from = meteonexaText(`advanced.official.level.${previous}`), to = meteonexaText(`advanced.official.level.${current}`);
        parts.push(meteonexaText(meteonexaOfficialSeverityRank(current) > meteonexaOfficialSeverityRank(previous) ? 'home.official.lifecycle.escalated' : 'home.official.lifecycle.downgraded', { from, to }));
    }
    const previousEnd = Date.parse(String(life.previousEndsAt || ''));
    const currentEnd = Date.parse(String(warning?.endsAt || ''));
    if (Number.isFinite(previousEnd) && Number.isFinite(currentEnd) && currentEnd > previousEnd + 60000) {
        parts.push(meteonexaText('home.official.lifecycle.extended', { until: formatOfficialAlertTime(warning.endsAt) }));
    } else if (Number.isFinite(previousEnd) && Number.isFinite(currentEnd) && currentEnd < previousEnd - 60000) {
        parts.push(meteonexaText('home.official.lifecycle.shortened', { until: formatOfficialAlertTime(warning.endsAt) }));
    }
    if (!parts.length && ['updated','new'].includes(String(life.changeType || '')) && life.changedAt) parts.push(meteonexaText(life.changeType === 'new' ? 'home.official.lifecycle.new' : 'home.official.lifecycle.updated'));
    return parts.join(' · ');
}
function meteonexaOfficialSeverityRank(value='') { return ({green:0,yellow:1,orange:2,red:3})[String(value).toLowerCase()] ?? 0; }
function formatOfficialAlertTime(value) {
    const date = new Date(String(value || '')); if (Number.isNaN(date.getTime())) return '--';
    const locale = state.settings?.language || document.documentElement.lang || 'it';
    const timezone = state.weather?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
    try { return new Intl.DateTimeFormat(locale,{weekday:'short',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:timezone}).format(date); } catch { return date.toLocaleString(); }
}
function renderHomeOfficialAlert({ loading = false, error = false } = {}) {
    const root = $('#home-official-alert');
    if (!root) return;
    const data = state.officialAlerts;
    const expectedKey=`${Number(state.location.latitude).toFixed(3)}:${Number(state.location.longitude).toFixed(3)}`;
    const title = $('#home-official-alert-title'), copy = $('#home-official-alert-copy'), meta = $('#home-official-alert-meta');
    const guest = isGuestSession();
    if (loading || (!data && guest)) {
        root.hidden = false; root.dataset.severity = 'info'; root.dataset.state = 'loading';
        if (title) title.textContent = meteonexaText('home.official.loading.title');
        if (copy) copy.textContent = meteonexaText('home.official.loading.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:'MeteoAlarm'});
        return;
    }
    if (!data || state.officialAlertsLocationKey!==expectedKey) { root.hidden = true; return; }
    if (error || data.error === true || data.available === false) {
        root.hidden = false; root.dataset.severity = 'info'; root.dataset.state = 'unavailable';
        if (title) title.textContent = meteonexaText('home.official.unavailable.title');
        if (copy) copy.textContent = meteonexaText('home.official.unavailable.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    const rows = Array.isArray(data.relevant) ? data.relevant : [];
    const regional = Array.isArray(data.regionalAdvisories) ? data.regionalAdvisories : [];
    root.dataset.state = rows.length ? 'active' : (regional.length ? 'regional' : 'clear');
    if (!rows.length && regional.length) {
        root.hidden = false; root.dataset.severity = 'info';
        const region = String(state.location?.admin1 || state.location?.name || '').trim();
        if (title) title.textContent = meteonexaText('home.official.regional.title');
        if (copy) copy.textContent = meteonexaText('home.official.regional.copy',{location:shortLocationLabel(state.location),region});
        if (meta) meta.textContent = meteonexaText('home.official.regional.meta',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    if (!rows.length) {
        root.hidden = false;
        root.dataset.severity = 'green';
        if (title) title.textContent = meteonexaText('home.official.none.title');
        if (copy) copy.textContent = meteonexaText('home.official.none.copy',{location:shortLocationLabel(state.location)});
        if (meta) meta.textContent = meteonexaText('home.official.source',{source:String(data.source || 'MeteoAlarm')});
        return;
    }
    const rank={red:3,orange:2,yellow:1};
    const stateRank={active:3,upcoming:2,unknown:1};
    const sorted=[...rows].sort((a,b)=>((stateRank[String(b?.windowState||'unknown')]||0)-(stateRank[String(a?.windowState||'unknown')]||0))||((rank[String(b?.severity||'yellow')]||1)-(rank[String(a?.severity||'yellow')]||1)));
    const warning=sorted[0], readable=officialAlertReadable(warning);
    root.hidden=false;root.dataset.severity=readable.severity;
    if(title)title.textContent=readable.label;
    if(copy){
        const windowState=String(warning?.windowState||'unknown');
        let base='';
        if(windowState==='upcoming'){
            base=warning?.startsAt
                ? meteonexaText('home.official.upcoming.copy',{location:shortLocationLabel(state.location),start:formatOfficialAlertTime(warning.startsAt)})
                : meteonexaText('home.official.upcoming.copy_unknown',{location:shortLocationLabel(state.location)});
        }else if(windowState==='active'){
            base=rows.length>1
                ? meteonexaText('home.official.active.multiple',{count:rows.length,location:shortLocationLabel(state.location)})
                : meteonexaText('home.official.active.copy',{location:shortLocationLabel(state.location)});
        }else{
            base=meteonexaText('home.official.unknown.copy',{location:shortLocationLabel(state.location)});
        }
        const life=officialAlertLifecycleText(warning);copy.textContent=[life,base].filter(Boolean).join(' · ');
    }
    const source=String(warning.source||data.source||'MeteoAlarm');
    const snapshot=(warning.serverSnapshot===true||data.pipelineFallback===true||data.staleProviderCache===true)?meteonexaText('home.official.server_snapshot'):'';
    let validity='';
    if(String(warning.windowState||'')==='active'&&warning.endsAt)validity=meteonexaText('home.official.validity.until',{time:formatOfficialAlertTime(warning.endsAt)});
    else if(String(warning.windowState||'')==='upcoming'&&warning.startsAt&&warning.endsAt)validity=meteonexaText('home.official.validity.window',{start:formatOfficialAlertTime(warning.startsAt),end:formatOfficialAlertTime(warning.endsAt)});
    else if(String(warning.windowState||'')==='upcoming'&&warning.startsAt)validity=meteonexaText('home.official.validity.starts',{time:formatOfficialAlertTime(warning.startsAt)});
    else if(warning.validityKnown===false)validity=meteonexaText('home.official.validity.unknown');
    if(meta)meta.textContent=[meteonexaText('home.official.source',{source}),validity,snapshot].filter(Boolean).join(' · ');
}
async function loadHomeOfficialAlerts({force=false}={}) {
    if (state.officialAlertsRequest) return state.officialAlertsRequest;
    const key=`${Number(state.location.latitude).toFixed(3)}:${Number(state.location.longitude).toFixed(3)}`;
    const age=state.officialAlertsFetchedAt?Date.now()-state.officialAlertsFetchedAt:Infinity;
    const degraded=state.officialAlerts?.error===true||state.officialAlerts?.available===false;
    const cacheTtl=degraded?20*1000:5*60*1000;
    if(!force&&state.officialAlerts&&state.officialAlertsLocationKey===key&&age<cacheTtl){renderHomeOfficialAlert();return state.officialAlerts;}
    if(PREVIEW_MODE){state.officialAlerts={available:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert();return state.officialAlerts;}
    if (isGuestSession()) renderHomeOfficialAlert({ loading: true });
    const task=(async()=>{
        const params=new URLSearchParams({lat:String(state.location.latitude),lon:String(state.location.longitude),location:String(state.location.name||''),admin1:String(state.location.admin1||''),lang:String(state.settings.language||'it').slice(0,2)});if(state.session?.type==='email'&&state.authServerVerified===true&&SERVICES.get('security')?.deviceId)params.set('deviceId',SERVICES.get('security').deviceId);
        try{
            let result;
            try{result=await fetchJSON(`api/official/alerts.php?${params}`,{timeout:12000,credentials:'same-origin'});}
            catch(firstError){
                if(!isGuestSession())throw firstError;
                await sleep(450);
                result=await fetchJSON(`api/official/alerts.php?${params}`,{timeout:12000,credentials:'same-origin'});
            }
            state.officialAlerts=result?.alerts||{available:false,error:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert();return state.officialAlerts;
        }catch(error){console.warn('HOME_OFFICIAL_ALERTS_FAILED',error);state.officialAlerts={available:false,error:true,source:'MeteoAlarm',relevant:[]};state.officialAlertsFetchedAt=Date.now();state.officialAlertsLocationKey=key;renderHomeOfficialAlert({error:true});return state.officialAlerts;}
    })();
    state.officialAlertsRequest=task;
    try{return await task;}finally{if(state.officialAlertsRequest===task)state.officialAlertsRequest=null;}
}
async function loadWeather({ force = false, silent = false } = {}) {
    if (state.weatherRequest)
        return state.weatherRequest;
    const cacheAge = state.weather?.fetchedAt ? Date.now() - state.weather.fetchedAt : Infinity;
    // Only data refreshed successfully in this runtime may use the short live
    // cache. A persisted snapshot is always revalidated when the browser is
    // online, regardless of how recently the previous runtime saved it.
    if (!force && state.weather?.source === 'live' && cacheAge < 5 * 60 * 1000) {
        renderAll();
        loadForecastFusion({ force: false }).catch(()=>{});
        loadSevereWeatherMonitor({ force: false }).catch(()=>{});
        loadHomeOfficialAlerts({force:false}).catch(()=>{});
        return state.weather;
    }
    const task = async () => {
        if (!state.weather)
            state.weather = createPreviewWeather();
        if (!state.air)
            state.air = createPreviewAir();
        renderAll();
        if (PREVIEW_MODE)
            return state.weather;
        if (!navigator.onLine) {
            if (state.weather?.source !== 'preview') {
                state.weather = { ...state.weather, source: 'cache', cacheReason: 'offline' };
                saveJSON(STORAGE.weather, state.weather);
            }
            renderAll();
            showToast("" + meteonexaText("radar.task.offline_mode"), "" + meteonexaText("app.task.showing_latest_data_available_device"), 'warning');
            return state.weather;
        }

        // Run reliability helpers alongside the normal forecast. They are
        // fail-soft and never block the base provider from rendering.
        loadForecastFusion({ force }).catch(()=>{});
        loadSevereWeatherMonitor({ force }).catch(()=>{});
        const [weatherResult, airResult] = await Promise.allSettled([
            fetchJSON(buildWeatherURL()),
            fetchJSON(buildAirURL(), { timeout: 10000 })
        ]);
        if (weatherResult.status === 'fulfilled' && weatherResult.value?.current) {
            state.weather = { ...weatherResult.value, fetchedAt: Date.now(), source: 'live', cacheReason: '' };
            applyWeatherTimeZoneMetadata(state.weather);
            updateIntelligenceSnapshot(state.weather);
            saveJSON(STORAGE.weather, state.weather);
            applyLoginWeatherBackdrop(state.weather);
        }
        else if (!state.weather?.fetchedAt || state.weather?.source === 'preview') {
            state.weather = createPreviewWeather();
            showToast("" + meteonexaText("app.task.temporary_demo_data"), "" + meteonexaText("app.task.weather_service_did_not_respond_interface_remains_usable"), 'warning');
        }
        else {
            // Preserve the last known forecast, its original fetchedAt and all
            // timezone metadata, but explicitly demote it to a stale snapshot.
            // A provider/network failure must never make yesterday's live flag
            // look like a successful refresh today.
            state.weather = { ...state.weather, source: 'cache', cacheReason: 'provider' };
            saveJSON(STORAGE.weather, state.weather);
            showToast("" + meteonexaText("app.task.update_failed"), "" + meteonexaText("app.task.continuing_show_latest_saved_data"), 'warning');
        }
        if (airResult.status === 'fulfilled' && airResult.value?.hourly) {
            state.air = { ...airResult.value, fetchedAt: Date.now(), source: 'live' };
            saveJSON(STORAGE.air, state.air);
        }
        renderAll();
        loadSevereWeatherMonitor({ force }).catch(()=>{});
        loadHomeOfficialAlerts({force}).catch(()=>{});
        try { SERVICES.get('weather')?.publish?.(state.weather, state.location, state.air); } catch { }
        return state.weather;
    };
    state.weatherRequest = silent
        ? task()
        : withLoader("" + meteonexaText("app.setloader.weather_update"), meteonexaText("app.task.retrieving_forecast_air_quality_value", { location: state.location.name }), task, 650);
    try {
        return await state.weatherRequest;
    }
    finally {
        state.weatherRequest = null;
    }
}
const {
    currentHourlyIndex,
    isPrecipitationCode,
    isLiquidPrecipitationCode,
    cloudFallbackCode,
    forecastFusionLocationKey,
    forecastFusionIsAuthoritative,
    fusionRowForHour,
    minutelyEvidenceForHour,
    resolveFusedHourlyCondition,
    currentResolvedCondition,
    resolvedConditionEvidenceLabel,
    loadForecastFusion,
    weatherSummary,
    localTrustBriefReliability,
    trustBriefSevereSignal,
    trustBriefForecastSignal,
    renderTrustBrief,
    historyDateValue,
    historyDateOffset,
    historyDateObject,
    historyLocationKey,
    ensureHistoryRange,
    setHistoryRange,
    historyRangeDays,
    validateHistoryRange,
    buildHistoricalWeatherURL,
    createPreviewHistory,
    loadHistory,
    historyAverage,
    historyMaximum,
    historyTotal,
    formatHistoryDay,
    renderHistory,
    drawHistoryChart
} = SERVICES.require('forecastHistory').create({
    state, $, $$, clamp, localSeriesIndex, localDateHourKey, weatherMeta, t,
    PREVIEW_MODE, hasUsableLocation: (...args) => hasUsableLocation(...args),
    renderAll: (...args) => renderAll(...args), renderHeader: (...args) => renderHeader(...args),
    temperature, windDirection, meteonexaText, isGuestSession: (...args) => isGuestSession(...args),
    loadJSON, STORAGE, severeWeatherLocationKey: (...args) => severeWeatherLocationKey(...args),
    severeWeatherEventRank: (...args) => severeWeatherEventRank(...args), optionalFiniteNumber,
    formatOfficialAlertTime: (...args) => formatOfficialAlertTime(...args), appLocale, formatClock, CONFIG, fetchJSON,
    showToast: (...args) => showToast(...args), withLoader: (...args) => withLoader(...args), capitalize,
    fullLocationLabel, escapeHTML, weatherArt, translateDOM,
    canvasSetup: (...args) => canvasSetup(...args), convertTemp,
    drawChartAxisTitle: (...args) => drawChartAxisTitle(...args),
    chartUnitAxisLabel: (...args) => chartUnitAxisLabel(...args), unitLabel,
    registerChartInteraction: (...args) => registerChartInteraction(...args)
});

function renderAll() {
    if (!state.weather)
        return;
    updateWeatherAtmosphere();
    renderHeader();
    renderHero();
    renderNowMetrics();
    renderHourly();
    renderDaily();
    renderAirQuality();
    renderSun();
    renderInsights();
    renderTrustBrief();
    renderIntelligence();
    renderAlerts();
    renderHomeSevereWeather();
    renderHomeOfficialAlert();
    SERVICES.get('advanced')?.renderAll?.();
    queueMicrotask(evaluateLocalWeatherNotifications);
    renderDetailsTable();
    if (state.history.data)
        renderHistory();
    syncFavoriteUI();
    requestAnimationFrame(() => {
        drawHomeChart();
        drawTrendCharts();
        if (state.currentPage === 'details')
            drawAllDetailCharts();
        if (state.currentPage === 'history' && state.history.data)
            drawHistoryChart();
    });
    translateDOM(document);
}
function weatherFreshnessLabel() {
    const data = state.weather;
    const fetchedAt = Number(data?.fetchedAt || 0);
    const date = new Date(fetchedAt || Date.now());
    const cityTimeZone = resolveLocationTimeZone(state.location, data);
    const updateTime = new Intl.DateTimeFormat(appLocale(), { hour: '2-digit', minute: '2-digit', timeZone: cityTimeZone }).format(date);
    if (data?.source === 'live') {
        if (forecastFusionIsAuthoritative(data)) {
            return t('weather.reliability.live_fusion', {
                time: updateTime,
                count: Number(state.forecastFusion.freshModelsAvailable || state.forecastFusion.modelsAvailable || 0),
                total: Number(state.forecastFusion.modelsExpected || state.forecastFusion.modelsAvailable || 0)
            });
        }
        return t('weather.reliability.live_base', { time: updateTime });
    }
    if (!fetchedAt) return t('weather.reliability.cache_unknown');
    const ageMinutes = Math.max(0, Math.floor((Date.now() - fetchedAt) / 60000));
    if (ageMinutes < 60) return t('weather.reliability.cache_minutes', { count: Math.max(1, ageMinutes) });
    const ageHours = Math.floor(ageMinutes / 60);
    if (ageHours < 48) return t('weather.reliability.cache_hours', { count: ageHours });
    return t('weather.reliability.cache_days', { count: Math.floor(ageHours / 24) });
}
function renderHeader() {
    const label = shortLocationLabel(state.location);
    $('#top-location').textContent = label;
    $('#hero-location').textContent = label;
    $('#radar-location-name').textContent = label;
    const updateLabel = weatherFreshnessLabel();
    const topLocation = $('#top-location');
    const lastUpdate = $('#last-update');
    topLocation.dataset.compact = state.location.name || label;
    topLocation.dataset.tooltip = label;
    lastUpdate.textContent = updateLabel;
    lastUpdate.dataset.compact = updateLabel;
    lastUpdate.dataset.tooltip = updateLabel;
    lastUpdate.dataset.freshness = state.weather.source === 'live' ? 'live' : 'cache';
    refreshTimeZoneLabels();
    const dataStatus = $('#data-status');
    dataStatus.textContent = state.weather.source !== 'live' ? updateLabel : (navigator.onLine ? updateLabel : t("app.renderheader.latest_data_available_offline"));
    dataStatus.classList.toggle('is-stale', state.weather.source !== 'live');
    const onlineDot = $('#online-dot');
    onlineDot.classList.toggle('offline', !navigator.onLine);
    onlineDot.classList.toggle('stale', state.weather.source !== 'live');
    const cityTimeZone = resolveLocationTimeZone(state.location, state.weather);
    $('#hero-date').textContent = capitalize(new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: cityTimeZone }).format(new Date()));
}
function renderHero() {
    const data = state.weather;
    const current = data.current;
    const hourlyIndex = currentHourlyIndex(data);
    const resolved = resolveFusedHourlyCondition(data, hourlyIndex);
    const meta = resolved.meta;
    const hero = $('#hero-card');
    hero.classList.remove('weather-clear', 'weather-night', 'weather-cloud', 'weather-rain', 'weather-storm', 'weather-snow', 'weather-fog');
    hero.classList.add(`weather-${meta.kind}`);
    $('#current-temp').textContent = Math.round(convertTemp(current.temperature_2m));
    $('#current-unit').textContent = unitLabel();
    $('#current-description').textContent = meta.label;
    $('#weather-summary').textContent = weatherSummary(data);
    $('#current-feels').textContent = temperature(current.apparent_temperature);
    $('#current-wind').textContent = `${Math.round(current.wind_speed_10m || 0)} km/h ${windDirection(current.wind_direction_10m)}`;
    $('#current-rain').textContent = `${Math.round(data.hourly.precipitation_probability[hourlyIndex] || 0)}%`;
    $('#current-weather-art').innerHTML = weatherArt(resolved.code, resolved.isDay);
    $('#hero-high-low').textContent = t('weather.high_low.compact', { high: temperature(data.daily.temperature_2m_max[0]), low: temperature(data.daily.temperature_2m_min[0]) });
    $('#radar-rain-risk').textContent = `${Math.round(data.daily.precipitation_probability_max[0] || 0)}%`;
}
function renderNowMetrics() {
    const current = state.weather.current;
    const hourlyIndex = currentHourlyIndex(state.weather);
    const visibility = Number(state.weather.hourly.visibility[hourlyIndex] || 0);
    const uv = Number(state.weather.hourly.uv_index[hourlyIndex] || 0);
    $('#metric-humidity').textContent = `${Math.round(current.relative_humidity_2m || 0)}%`;
    $('#humidity-note').textContent = metricNoteHumidity(current.relative_humidity_2m || 0);
    $('#metric-pressure').textContent = `${Math.round(current.surface_pressure || 0)} hPa`;
    $('#pressure-note').textContent = metricNotePressure(current.surface_pressure || 0);
    $('#metric-visibility').textContent = `${(visibility / 1000).toFixed(1)} km`;
    $('#visibility-note').textContent = metricNoteVisibility(visibility);
    $('#metric-uv').textContent = uv.toFixed(1);
    $('#uv-note').textContent = uvLabel(uv);
    const comfort = getComfort(current.temperature_2m, current.relative_humidity_2m, current.wind_speed_10m);
    const badge = $('#comfort-badge');
    badge.textContent = comfort.label;
    badge.className = `soft-badge ${comfort.className}`;
}
function getComfort(tempC, humidity, wind) {
    const score = Math.abs(Number(tempC) - 22) + Math.abs(Number(humidity) - 50) / 12 + Math.max(0, Number(wind) - 25) / 8;
    if (score < 4)
        return { label: "" + meteonexaText("app.getcomfort.excellent_comfort"), className: 'good' };
    if (score < 8)
        return { label: "" + meteonexaText("app.getcomfort.good_comfort"), className: 'good' };
    return { label: "" + meteonexaText("app.getcomfort.variable_comfort"), className: '' };
}
function renderHourly() {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    const root = $('#hourly-strip');
    root.innerHTML = data.hourly.time.slice(start, start + 12).map((time, offset) => {
        const index = start + offset;
        const current = offset === 0;
        const resolved = resolveFusedHourlyCondition(data, index);
        const meta = resolved.meta;
        return "" + "<button class=\"hour-card " + (current ? 'current' : '') + "\" type=\"button\" data-hour-index=\"" + index + "\" aria-label=\"" + escapeHTML(meteonexaText('hour.open_detail', { time: formatClock(time), condition: meta.label })) + "\">\n      <time>" + (current ? "" + escapeHTML(meteonexaText("app.renderhourly.now")) : escapeHTML(formatClock(time))) + "</time>\n      " + weatherArt(resolved.code, resolved.isDay) + "\n      <span class=\"hour-card-condition " + (resolved.dry ? 'is-dry' : '') + "\">" + escapeHTML(resolved.dry ? meteonexaText('weather.reliability.dry_short') : meta.label) + "</span>\n      <strong>" + temperature(data.hourly.temperature_2m[index]) + "</strong>\n      <small><svg><use href=\"#i-droplet\"/></svg>" + Number(resolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " mm · " + Math.round(data.hourly.precipitation_probability[index] || 0) + "%</small>\n      <span class=\"hour-card-more\">" + escapeHTML(meteonexaText("history.renderhistory.details")) + "</span>\n    </button>";
    }).join('');
    $$('.hour-card', root).forEach(card => card.addEventListener('click', () => openHourDetail(Number(card.dataset.hourIndex))));
}
function hourMetric(label, value, note = '') {
    return `<article class="hour-detail-metric"><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong>${note ? `<span>${escapeHTML(note)}</span>` : ''}</article>`;
}
function openHourDetail(index) {
    const hourly = state.weather?.hourly;
    if (!hourly?.time?.[index])
        return;
    state.selectedHourIndex = index;
    const time = hourly.time[index];
    const resolved = resolveFusedHourlyCondition(state.weather, index);
    const meta = resolved.meta;
    const previous = Number(hourly.temperature_2m[index - 1] ?? hourly.temperature_2m[index]);
    const current = Number(hourly.temperature_2m[index] ?? 0);
    const delta = current - previous;
    const displayDelta = state.settings.unit === 'fahrenheit' ? delta * 9 / 5 : delta;
    const trend = Math.abs(delta) < .2 ? "" + meteonexaText("weather.metricnotepressure.stable") : meteonexaText('hour.trend.delta', { value: `${displayDelta > 0 ? '+' : ''}${displayDelta.toFixed(1)}` });
    const wind = Number(hourly.wind_speed_10m[index] || 0);
    const gust = Number(hourly.wind_gusts_10m?.[index] || wind);
    const visibilityKm = Number(hourly.visibility[index] || 0) / 1000;
    const dateLabel = new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${String(time).slice(0, 10)}T12:00:00Z`));
    $('#hour-detail-title').textContent = `${formatClock(time)} · ${capitalize(dateLabel)}`;
    $('#hour-detail-subtitle').textContent = `${shortLocationLabel()} · ${meta.label} · ${resolvedConditionEvidenceLabel(resolved)} · ${timeZoneOffsetLabel()}`;
    $('#hour-detail-art').innerHTML = weatherArt(resolved.code, resolved.isDay);
    $('#hour-detail-temp').textContent = temperature(hourly.temperature_2m[index]);
    $('#hour-detail-condition').textContent = resolved.dry ? t('weather.reliability.dry_condition', { condition: meta.label }) : meta.label;
    $('#hour-detail-trend').textContent = trend;
    $('#hour-detail-grid').innerHTML = [
        hourMetric("" + meteonexaText("history.renderhistory.feels_like"), temperature(hourly.apparent_temperature[index]), "" + meteonexaText("app.openhourdetail.thermal_sensation")),
        hourMetric("" + meteonexaText("history.renderhistory.rain"), `${Math.round(hourly.precipitation_probability[index] || 0)}%`, `${Number(resolved.precipitationMm || 0).toFixed(1)} mm`),
        hourMetric("" + meteonexaText("visualization.take.humidity"), `${Math.round(hourly.relative_humidity_2m[index] || 0)}%`, metricNoteHumidity(hourly.relative_humidity_2m[index] || 0)),
        hourMetric("" + meteonexaText("app.openhourdetail.dew_point"), temperature(hourly.dew_point_2m?.[index]), "" + meteonexaText("app.openhourdetail.perceived_condensation")),
        hourMetric("" + meteonexaText("visualization.take.wind"), `${Math.round(wind)} km/h ${windDirection(hourly.wind_direction_10m[index])}`, meteonexaText("app.openhourdetail.gusts_value_km_h", { value: Math.round(gust) })),
        hourMetric("" + meteonexaText("visualization.take.pressure"), `${Math.round(hourly.surface_pressure[index] || 0)} hPa`, metricNotePressure(hourly.surface_pressure[index] || 0)),
        hourMetric("" + meteonexaText("app.openhourdetail.visibility"), `${visibilityKm.toFixed(1)} km`, metricNoteVisibility(hourly.visibility[index] || 0)),
        hourMetric("" + meteonexaText("visualization.take.uv_index"), Number(hourly.uv_index[index] || 0).toFixed(1), uvLabel(hourly.uv_index[index] || 0)),
        hourMetric("" + meteonexaText("visualization.take.cloud_cover"), `${Math.round(hourly.cloud_cover[index] || 0)}%`, meta.label)
    ].join('');
    $('#hour-detail-prev').disabled = index <= 0;
    $('#hour-detail-next').disabled = index >= hourly.time.length - 1;
    const dialog = $('#hour-detail-dialog');
    if (!dialog.open)
        dialog.showModal();
}
function stepHourDetail(delta) {
    if (!Number.isInteger(state.selectedHourIndex))
        return;
    openHourDetail(clamp(state.selectedHourIndex + delta, 0, state.weather.hourly.time.length - 1));
}
function renderDaily() {
    const daily = state.weather.daily;
    const root = $('#daily-forecast');
    root.innerHTML = daily.time.slice(0, 10).map((date, index) => {
        const meta = weatherMeta(daily.weather_code[index], 1);
        return `<button class="day-row" type="button" data-day-index="${index}" aria-label="${escapeHTML(t('day.open_detail', { day: formatDay(date, index), condition: meta.label }))}">
      <div class="day-label"><strong>${escapeHTML(formatDay(date, index))}</strong><small>${escapeHTML(formatShortDate(date))}</small></div>
      ${weatherArt(daily.weather_code[index], 1)}
      <span class="day-rain"><svg><use href="#i-droplet"/></svg>${Math.round(daily.precipitation_probability_max[index] || 0)}%</span>
      <span class="day-temp"><span>${temperature(daily.temperature_2m_max[index])}</span><span>${temperature(daily.temperature_2m_min[index])}</span></span>
      <span class="day-row-chevron"><svg><use href="#i-chevron"/></svg></span>
    </button>`;
    }).join('');
    $$('.day-row', root).forEach(row => row.addEventListener('click', () => openDayDetail(Number(row.dataset.dayIndex))));
}
function formatDuration(seconds) {
    const totalMinutes = Math.max(0, Math.round(Number(seconds || 0) / 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return t("app.formatduration.value_h_value_min", { hours, minutes });
}
function dayHourlyIndices(dateValue) {
    const times = state.weather?.hourly?.time || [];
    return times.map((time, index) => ({ time, index }))
        .filter(item => String(item.time).slice(0, 10) === String(dateValue))
        .map(item => item.index);
}
function openDayDetail(index) {
    const daily = state.weather?.daily;
    if (!daily?.time?.[index])
        return;
    state.selectedDayIndex = index;
    const date = daily.time[index];
    const meta = weatherMeta(daily.weather_code[index], 1);
    const max = Number(daily.temperature_2m_max[index] || 0);
    const min = Number(daily.temperature_2m_min[index] || 0);
    const rangeC = max - min;
    const range = state.settings.unit === 'fahrenheit' ? rangeC * 9 / 5 : rangeC;
    const sunrise = formatClock(daily.sunrise[index]);
    const sunset = formatClock(daily.sunset[index]);
    const titleDate = capitalize(new Intl.DateTimeFormat(appLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`)));
    $('#day-detail-title').textContent = titleDate;
    $('#day-detail-subtitle').textContent = `${shortLocationLabel()} · ${meta.label} · ${timeZoneOffsetLabel()}`;
    $('#day-detail-art').innerHTML = weatherArt(daily.weather_code[index], 1);
    $('#day-detail-temp').textContent = `${temperature(max)} / ${temperature(min)}`;
    $('#day-detail-condition').textContent = meta.label;
    $('#day-detail-range').textContent = `${range.toFixed(1)}°`;
    $('#day-detail-grid').innerHTML = [
        hourMetric(t("history.renderhistory.rain"), `${Math.round(daily.precipitation_probability_max[index] || 0)}%`, `${Number(daily.precipitation_sum[index] || 0).toFixed(1)} mm`),
        hourMetric(t("intelligence.rendermodelcomparison.maximum_wind"), `${Math.round(daily.wind_speed_10m_max[index] || 0)} km/h`, `${t("app.opendaydetail.maximum_gust")} ${Math.round(daily.wind_gusts_10m_max[index] || 0)} km/h`),
        hourMetric(t("app.opendaydetail.maximum_uv"), Number(daily.uv_index_max[index] || 0).toFixed(1), uvLabel(daily.uv_index_max[index] || 0)),
        hourMetric(t("app.opendaydetail.sunrise_sunset"), `${sunrise} / ${sunset}`, t("app.opendaydetail.sun")),
        hourMetric(t("app.opendaydetail.daylight_duration"), formatDuration(daily.daylight_duration[index]), `${formatDuration(daily.sunshine_duration[index])} ${t("app.opendaydetail.sun").toLowerCase()}`)
    ].join('');
    const hourly = state.weather.hourly;
    const indices = dayHourlyIndices(date);
    const hourlyRoot = $('#day-detail-hourly');
    hourlyRoot.innerHTML = indices.map(hourIndex => {
        const hourResolved = resolveFusedHourlyCondition(state.weather, hourIndex);
        const hourMeta = hourResolved.meta;
        return `<button class="hour-card day-hour-card" type="button" data-hour-index="${hourIndex}" aria-label="${escapeHTML(t('hour.open_detail', { time: formatClock(hourly.time[hourIndex]), condition: hourMeta.label }))}">
      <time>${escapeHTML(formatClock(hourly.time[hourIndex]))}</time>
      ${weatherArt(hourResolved.code, hourResolved.isDay)}
      <span class="hour-card-condition ${hourResolved.dry ? 'is-dry' : ''}">${escapeHTML(hourResolved.dry ? t('weather.reliability.dry_short') : hourMeta.label)}</span>
      <strong>${temperature(hourly.temperature_2m[hourIndex])}</strong>
      <small><svg><use href="#i-droplet"/></svg>${Number(hourResolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })} mm · ${Math.round(hourly.precipitation_probability[hourIndex] || 0)}%</small>
      <span class="hour-card-more">${escapeHTML(t("history.renderhistory.details"))}</span>
    </button>`;
    }).join('');
    $$('.day-hour-card', hourlyRoot).forEach(card => card.addEventListener('click', () => {
        $('#day-detail-dialog').close();
        requestAnimationFrame(() => openHourDetail(Number(card.dataset.hourIndex)));
    }));
    $('#day-detail-prev').disabled = index <= 0;
    $('#day-detail-next').disabled = index >= daily.time.length - 1;
    const dialog = $('#day-detail-dialog');
    if (!dialog.open)
        dialog.showModal();
    translateDOM(dialog);
}
function stepDayDetail(delta) {
    if (!Number.isInteger(state.selectedDayIndex))
        return;
    openDayDetail(clamp(state.selectedDayIndex + delta, 0, state.weather.daily.time.length - 1));
}
function airCurrentValues() {
    const hourly = state.air?.hourly;
    if (!hourly?.time?.length)
        return null;
    const index = localSeriesIndex(hourly.time, { weatherData: state.air });
    return {
        aqi: Number(hourly.european_aqi?.[index] ?? 0),
        pm10: Number(hourly.pm10?.[index] ?? 0),
        pm25: Number(hourly.pm2_5?.[index] ?? 0),
        ozone: Number(hourly.ozone?.[index] ?? 0),
        no2: Number(hourly.nitrogen_dioxide?.[index] ?? 0)
    };
}
function aqiMeta(value) {
    if (value <= 20)
        return { label: "" + meteonexaText("app.aqimeta.excellent"), description: "" + meteonexaText("app.aqimeta.very_clean_air_ideal_conditions_spending_time_outdoors"), color: '#48e39a' };
    if (value <= 40)
        return { label: "" + meteonexaText("weather.metricnotevisibility.good"), description: "" + meteonexaText("app.aqimeta.air_quality_good_most_people"), color: '#70e77d' };
    if (value <= 60)
        return { label: "" + meteonexaText("intelligence.extractnowcast.moderate"), description: "" + meteonexaText("app.aqimeta.fair_air_quality_sensitive_people_may_wish_limit"), color: '#ffd25e' };
    if (value <= 80)
        return { label: "" + meteonexaText("app.aqimeta.poor"), description: "" + meteonexaText("app.aqimeta.consider_less_intense_activities_especially_if_sensitive_pollutants"), color: '#ff9d52' };
    return { label: "" + meteonexaText("app.aqimeta.very_poor"), description: "" + meteonexaText("app.aqimeta.reduce_outdoor_activities_follow_local_guidance"), color: '#ff6572' };
}
function renderAirQuality() {
    const values = airCurrentValues();
    if (!values)
        return;
    const meta = aqiMeta(values.aqi);
    $('#aqi-value').textContent = Math.round(values.aqi);
    $('#aqi-badge').textContent = meta.label;
    $('#aqi-badge').style.color = meta.color;
    $('#aqi-badge').style.background = `${meta.color}18`;
    $('#aqi-label').textContent = meteonexaText("app.renderairquality.value_air", { quality: meta.label.toLowerCase() });
    $('#aqi-description').textContent = meta.description;
    $('#pm25').textContent = `${values.pm25.toFixed(1)} µg/m³`;
    $('#pm10').textContent = `${values.pm10.toFixed(1)} µg/m³`;
    $('#ozone').textContent = `${values.ozone.toFixed(0)} µg/m³`;
    $('#no2').textContent = `${values.no2.toFixed(0)} µg/m³`;
    const pathLength = 188.5;
    const fraction = clamp(values.aqi / 120, 0, 1);
    $('#aqi-progress').style.strokeDashoffset = String(pathLength * (1 - fraction));
    $('#aqi-progress').style.stroke = meta.color;
}
function renderSun() {
    const daily = state.weather.daily;
    const sunrise = new Date(daily.sunrise[0]);
    const sunset = new Date(daily.sunset[0]);
    const now = new Date();
    const daylight = Number(daily.daylight_duration?.[0] || (sunset - sunrise) / 1000);
    $('#sunrise').textContent = formatClock(sunrise);
    $('#sunset').textContent = formatClock(sunset);
    $('#solar-noon').textContent = formatClock(new Date((sunrise.getTime() + sunset.getTime()) / 2));
    $('#daylight-duration').textContent = meteonexaText("app.rendersun.value_h_value_min_daylight", { hours: Math.floor(daylight / 3600), minutes: Math.round((daylight % 3600) / 60) });
    const progress = clamp((now - sunrise) / Math.max(1, sunset - sunrise), 0, 1);
    const x = 6 + progress * 88;
    const y = 18 + Math.sin(progress * Math.PI) * 58;
    $('#sun-position').style.left = `calc(${x}% - 15px)`;
    $('#sun-position').style.bottom = `${17 + y}px`;
}
function findBestHour(predicate, maxHours = 24) {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    let best = null;
    for (let i = start; i < Math.min(start + maxHours, data.hourly.time.length); i += 1) {
        const item = {
            index: i, time: data.hourly.time[i], temp: data.hourly.temperature_2m[i],
            rain: data.hourly.precipitation_probability[i], wind: data.hourly.wind_speed_10m[i],
            uv: data.hourly.uv_index[i], code: data.hourly.weather_code[i], isDay: data.hourly.is_day[i]
        };
        const score = predicate(item);
        if (score !== null && (!best || score > best.score))
            best = { ...item, score };
    }
    return best;
}
function renderInsights() {
    const walk = findBestHour(item => item.rain < 35 && item.temp > 12 && item.temp < 29 ? 100 - item.rain - Math.abs(item.temp - 21) * 2 - item.wind : null);
    const laundry = findBestHour(item => item.rain < 20 && item.isDay ? 100 - item.rain - item.wind / 2 : null);
    const sport = findBestHour(item => item.rain < 30 && item.uv < 6 && item.temp > 10 && item.temp < 27 ? 100 - item.rain - Math.abs(item.temp - 19) * 3 : null);
    const umbrella = findBestHour(item => item.rain >= 40 ? item.rain : null);
    const cards = [
        { icon: 'i-sun', title: "" + meteonexaText("app.renderinsights.walk"), copy: walk ? `${formatClock(walk.time)}, ${temperature(walk.temp)}` : "" + meteonexaText("app.renderinsights.better_postpone"), note: walk ? "" + meteonexaText("app.renderinsights.favorable_window") : "" + meteonexaText("app.renderinsights.variable_conditions") },
        { icon: 'i-wind', title: "" + meteonexaText("app.renderinsights.laundry"), copy: laundry ? meteonexaText("app.renderinsights.value_rain_value", { time: formatClock(laundry.time), value: Math.round(laundry.rain) }) : "" + meteonexaText("app.renderinsights.no_ideal_window"), note: laundry ? "" + meteonexaText("app.renderinsights.good_drying_conditions") : "" + meteonexaText("app.renderinsights.humidity_risk") },
        { icon: 'i-chart', title: "" + meteonexaText("app.renderinsights.outdoor_sports"), copy: sport ? `${formatClock(sport.time)}, UV ${Number(sport.uv).toFixed(1)}` : "" + meteonexaText("app.renderinsights.better_indoors"), note: sport ? "" + meteonexaText("app.renderinsights.best_comfort") : "" + meteonexaText("app.renderinsights.unfavorable_weather") },
        { icon: 'i-umbrella', title: "" + meteonexaText("intelligence.renderintelligencedecisions.umbrella"), copy: umbrella ? meteonexaText("app.renderinsights.possible_from_value", { time: formatClock(umbrella.time) }) : "" + meteonexaText("intelligence.renderintelligencedecisions.not_needed"), note: umbrella ? meteonexaText("app.renderinsights.value_probability", { value: Math.round(umbrella.rain) }) : "" + meteonexaText("app.renderinsights.low_risk") }
    ];
    $('#activity-insights').innerHTML = cards.map(card => `<article class="activity-card"><span><svg><use href="#${card.icon}"/></svg></span><strong>${escapeHTML(card.title)}</strong><small>${escapeHTML(card.copy)}</small><em>${escapeHTML(card.note)}</em></article>`).join('');
}
const { intelligenceLocationKey, numericValues, average, standardDeviation, summarizeModelForecast, summarizeServerIntelligenceModel, createPreviewIntelligenceModels, loadIntelligence, createIntelligenceSnapshot, updateIntelligenceSnapshot, extractNowcast, nowcastProReliability, confidenceFromModels, nowcastMessage, modelForecastStrip, ensembleForecastStrip, renderModelComparison, buildForecastChanges, renderIntelligenceDecisions, renderIntelligence } = SERVICES.require('modelIntelligence').create({
    state, localSeriesIndex, PREVIEW_MODE, loadForecastFusion: (...args) => loadForecastFusion(...args),
    showToast: (...args) => showToast(...args), t, shortLocationLabel, withLoader: (...args) => withLoader(...args),
    currentHourlyIndex: (...args) => currentHourlyIndex(...args), loadJSON, STORAGE, saveJSON, $, $$, clamp, formatClock, temperature, weatherMeta,
    escapeHTML, translateDOM, meteonexaText, isGuestSession: (...args) => isGuestSession(...args),
    buildAutoCalibratedEnsemble: (...args) => buildAutoCalibratedEnsemble(...args), ensembleWeightLabel: (...args) => ensembleWeightLabel(...args),
    renderVerifiedModelAccuracy: (...args) => renderVerifiedModelAccuracy(...args), loadPublicLocalAccuracy: (...args) => loadPublicLocalAccuracy(...args),
    renderPersonalImpact: (...args) => renderPersonalImpact(...args), renderStoredBriefing: (...args) => renderStoredBriefing(...args),
    renderProactiveInsight: (...args) => renderProactiveInsight(...args), loadPersonalWeatherPreferences: (...args) => loadPersonalWeatherPreferences(...args),
    maybeGenerateProactiveInsight: (...args) => maybeGenerateProactiveInsight(...args), syncVerifiedModelAccuracy: (...args) => syncVerifiedModelAccuracy(...args),
    getPersonalWeatherPrefs: () => personalWeatherPrefs
});

const IMPACT_ACTIVITY_IDS = Object.freeze(['run', 'sea', 'laundry', 'motorcycle', 'trekking', 'kids']);
let personalWeatherPrefs = (() => {
    const stored = loadJSON(STORAGE.personalWeather, {});
    return {
        activities: Array.isArray(stored.activities) ? stored.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id)) : [],
        briefingEnabled: stored.briefingEnabled === true,
        briefingHour: Number.isInteger(Number(stored.briefingHour)) ? clamp(Number(stored.briefingHour), 0, 23) : null,
        briefingHourSet: stored.briefingHourSet === true,
        proactiveEnabled: stored.proactiveEnabled === true
    };
})();
let personalWeatherLoadedMode = '';
let personalWeatherLoading = null;
let accuracySyncAt = 0;
let briefingGenerating = false;

function modelDisplayName(id) {
    const key = {
        ecmwf: 'provider.ecmwf',
        aifs: 'provider.aifs',
        icon: 'provider.icon',
        gfs: 'provider.gfs',
        meteofrance: 'provider.meteofrance',
        ukmo: 'provider.ukmo'
    }[String(id || '').toLowerCase()];
    return key ? t(key) : String(id || '');
}
function impactActivityMeta(id) {
    return {
        run: { label: 'impact.activity.run', icon: 'i-gauge' },
        sea: { label: 'impact.activity.sea', icon: 'i-sun' },
        laundry: { label: 'impact.activity.laundry', icon: 'i-wind' },
        motorcycle: { label: 'impact.activity.motorcycle', icon: 'i-route' },
        trekking: { label: 'impact.activity.trekking', icon: 'i-location' },
        kids: { label: 'impact.activity.kids', icon: 'i-star' }
    }[id] || { label: 'impact.activity.run', icon: 'i-gauge' };
}
function impactHourlyRows(hours = 12) {
    const h = state.weather?.hourly;
    if (!h?.time?.length) return [];
    const start = currentHourlyIndex(state.weather);
    return h.time.slice(start, start + hours).map((time, offset) => {
        const index = start + offset;
        return {
            time,
            temperature: Number(h.temperature_2m?.[index] ?? state.weather?.current?.temperature_2m ?? 0),
            feels: Number(h.apparent_temperature?.[index] ?? h.temperature_2m?.[index] ?? 0),
            rain: Number(h.precipitation_probability?.[index] ?? 0),
            precipitation: Number(h.precipitation?.[index] ?? 0),
            gust: Number(h.wind_gusts_10m?.[index] ?? h.wind_speed_10m?.[index] ?? 0),
            wind: Number(h.wind_speed_10m?.[index] ?? 0),
            humidity: Number(h.relative_humidity_2m?.[index] ?? 0),
            visibility: Number(h.visibility?.[index] ?? 10000) / 1000,
            uv: Number(h.uv_index?.[index] ?? 0),
            cloud: Number(h.cloud_cover?.[index] ?? 0)
        };
    });
}
function impactScoreForRow(activity, row) {
    const penalties = [];
    const add = (value, key, params = {}) => {
        if (Number(value) > 0) penalties.push({ value: Number(value), key, params });
    };
    const rainPenalty = Math.max(0, row.rain - 20) * 0.55 + Math.min(35, row.precipitation * 12);
    if (activity === 'run') {
        add(rainPenalty, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 28) * 1.25, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.feels < 5 ? (5 - row.feels) * 3.2 : row.feels > 28 ? (row.feels - 28) * 3.2 : 0, row.feels < 5 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
        add(Math.max(0, row.uv - 7) * 5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.visibility < 4 ? (4 - row.visibility) * 8 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
    }
    else if (activity === 'sea') {
        add(rainPenalty * 1.05, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 34) * 1.25, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.temperature < 22 ? (22 - row.temperature) * 4 : 0, 'impact.reason.cool_sea', { value: temperature(row.temperature) });
        add(Math.max(0, row.cloud - 80) * 0.25, 'impact.reason.cloud', { value: Math.round(row.cloud) });
        add(Math.max(0, row.uv - 9) * 4, 'impact.reason.uv', { value: row.uv.toFixed(1) });
    }
    else if (activity === 'laundry') {
        add(Math.max(0, row.rain - 5) * 0.9 + row.precipitation * 20, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.humidity - 68) * 0.8, 'impact.reason.humidity', { value: Math.round(row.humidity) });
        add(row.wind < 4 ? (4 - row.wind) * 5 : 0, 'impact.reason.no_breeze', { value: Math.round(row.wind) });
        add(Math.max(0, row.gust - 48) * 1.5, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(Math.max(0, row.cloud - 85) * 0.3, 'impact.reason.cloud', { value: Math.round(row.cloud) });
    }
    else if (activity === 'motorcycle') {
        add(rainPenalty * 1.35, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 32) * 1.6, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.visibility < 6 ? (6 - row.visibility) * 7 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
        add(row.feels < 4 ? (4 - row.feels) * 3 : row.feels > 34 ? (row.feels - 34) * 3 : 0, row.feels < 4 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    else if (activity === 'trekking') {
        add(rainPenalty * 1.15, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 34) * 1.45, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(row.visibility < 5 ? (5 - row.visibility) * 8 : 0, 'impact.reason.visibility', { value: row.visibility.toFixed(1) });
        add(Math.max(0, row.uv - 7) * 4.5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.feels < 3 ? (3 - row.feels) * 3 : row.feels > 30 ? (row.feels - 30) * 3 : 0, row.feels < 3 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    else if (activity === 'kids') {
        add(rainPenalty * 1.2, 'impact.reason.rain', { value: Math.round(row.rain) });
        add(Math.max(0, row.gust - 30) * 1.5, 'impact.reason.wind', { value: Math.round(row.gust) });
        add(Math.max(0, row.uv - 6) * 5, 'impact.reason.uv', { value: row.uv.toFixed(1) });
        add(row.feels < 7 ? (7 - row.feels) * 3 : row.feels > 30 ? (row.feels - 30) * 4 : 0, row.feels < 7 ? 'impact.reason.cold' : 'impact.reason.heat', { value: temperature(row.feels) });
    }
    const penalty = penalties.reduce((sum, item) => sum + item.value, 0);
    penalties.sort((a, b) => b.value - a.value);
    return {
        score: clamp(Math.round(100 - penalty), 0, 100),
        reason: penalties[0]?.value >= 5 ? penalties[0] : { value: 0, key: 'impact.reason.good', params: {} }
    };
}
function impactSummary(activity) {
    const rows = impactHourlyRows(12);
    if (!rows.length) return null;
    const scored = rows.map(row => ({ row, ...impactScoreForRow(activity, row) }));
    let best = null;
    for (let index = 0; index < scored.length; index += 1) {
        const pair = scored.slice(index, Math.min(scored.length, index + 2));
        const score = Math.round(pair.reduce((sum, item) => sum + item.score, 0) / Math.max(1, pair.length));
        const candidate = { score, start: pair[0].row.time, end: pair.at(-1).row.time, reason: pair.sort((a, b) => a.score - b.score)[0].reason };
        if (!best || candidate.score > best.score) best = candidate;
    }
    return best;
}
function impactLevel(score) {
    return score >= 85 ? 'great' : score >= 70 ? 'good' : score >= 50 ? 'fair' : 'poor';
}
function renderPersonalImpact() {
    const grid = $('#impact-grid');
    const chips = $('#impact-preference-chips');
    if (!grid || !chips || !state.weather) return;
    chips.innerHTML = IMPACT_ACTIVITY_IDS.map(id => {
        const meta = impactActivityMeta(id);
        const active = personalWeatherPrefs.activities.includes(id);
        return `<button type="button" class="impact-preference-chip${active ? ' active' : ''}" data-impact-preference="${id}" aria-pressed="${active ? 'true' : 'false'}"><svg><use href="#${meta.icon}"/></svg><span>${escapeHTML(t(meta.label))}</span></button>`;
    }).join('');
    const selectedActivities = personalWeatherPrefs.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id));
    const visibleActivities = selectedActivities.length ? selectedActivities : IMPACT_ACTIVITY_IDS;
    grid.dataset.filtered = selectedActivities.length ? 'true' : 'false';
    grid.innerHTML = visibleActivities.map(id => {
        const meta = impactActivityMeta(id);
        const summary = impactSummary(id);
        if (!summary) return '';
        const end = new Date(summary.end);
        end.setHours(end.getHours() + 1);
        const windowText = t('impact.best_window.value', { start: formatClock(summary.start), end: formatClock(end) });
        return `<article class="impact-card" data-impact-activity="${id}" data-level="${impactLevel(summary.score)}"><span class="impact-card-icon"><svg><use href="#${meta.icon}"/></svg></span><h3>${escapeHTML(t(meta.label))}</h3><span class="impact-window">${escapeHTML(windowText)}</span><strong class="impact-score">${summary.score}</strong><p class="impact-reason">${escapeHTML(t(summary.reason.key, summary.reason.params))}</p></article>`;
    }).join('');
}
async function loadPersonalWeatherPreferences({ force = false } = {}) {
    if (personalWeatherLoading) return personalWeatherLoading;
    const mode = isGuestSession() ? 'guest' : 'authenticated';
    if (personalWeatherLoadedMode === mode && !force) return personalWeatherPrefs;
    const task = async () => {
        if (mode === 'guest' || (navigator.onLine !== false && !hasVerifiedServerSession())) {
            personalWeatherLoadedMode = mode;
            renderPersonalImpact();
            syncBriefingControls();
            renderProactiveInsight();
            return personalWeatherPrefs;
        }
        try {
            if (navigator.onLine && loadJSON(STORAGE.personalPending, false)) {
                const synced = await synchronizePersonalWeatherPreferences({ force: true });
                if (synced) {
                    personalWeatherLoadedMode = mode;
                    renderPersonalImpact();
                    syncBriefingControls();
                    renderStoredBriefing();
                    renderProactiveInsight();
                    maybeGenerateMorningBriefing();
                    return personalWeatherPrefs;
                }
            }
            const deviceId = SERVICES.get('security')?.deviceId || '';
            const data = await apiRequest(`api/preferences/personal.php?deviceId=${encodeURIComponent(deviceId)}`);
            if (data?.preferences) {
                personalWeatherPrefs = {
                    activities: Array.isArray(data.preferences.activities) ? data.preferences.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id)) : [],
                    briefingEnabled: data.preferences.briefingEnabled === true,
                    briefingHour: Number.isInteger(Number(data.preferences.briefingHour)) ? clamp(Number(data.preferences.briefingHour), 0, 23) : null,
                    briefingHourSet: data.preferences.briefingHourSet === true,
                    proactiveEnabled: data.preferences.proactiveEnabled === true
                };
                if (!personalWeatherPrefs.briefingHourSet)
                    personalWeatherPrefs.briefingHour = currentLocationHour();
                saveJSON(STORAGE.personalWeather, personalWeatherPrefs);
            }
        }
        catch (error) {
            console.warn('PERSONAL_WEATHER_PREFERENCES_LOAD_FAILED', error);
        }
        personalWeatherLoadedMode = mode;
        renderPersonalImpact();
        syncBriefingControls();
        renderStoredBriefing();
        renderProactiveInsight();
        maybeGenerateMorningBriefing();
        return personalWeatherPrefs;
    };
    personalWeatherLoading = task();
    try { return await personalWeatherLoading; }
    finally { personalWeatherLoading = null; }
}
async function savePersonalWeatherPreferences({ notify = false, fromSync = false } = {}) {
    personalWeatherPrefs.activities = personalWeatherPrefs.activities.filter(id => IMPACT_ACTIVITY_IDS.includes(id));
    if (!personalWeatherPrefs.briefingHourSet)
        personalWeatherPrefs.briefingHour = currentLocationHour();
    personalWeatherPrefs.briefingHour = clamp(Number(personalWeatherPrefs.briefingHour ?? currentLocationHour()), 0, 23);
    personalWeatherPrefs.proactiveEnabled = personalWeatherPrefs.proactiveEnabled === true;
    saveJSON(STORAGE.personalWeather, personalWeatherPrefs);
    renderPersonalImpact();
    syncBriefingControls();
    if (isGuestSession()) {
        saveJSON(STORAGE.personalPending, false);
        return;
    }
    if (navigator.onLine !== false && !hasVerifiedServerSession()) {
        saveJSON(STORAGE.personalPending, true);
        return;
    }
    if (!navigator.onLine) {
        saveJSON(STORAGE.personalPending, true);
        requestSafeBackgroundSync().catch(() => null);
        if (notify) showToast(t('offline.saved.title'), t('offline.saved.copy'), 'warning');
        return;
    }
    try {
        const deviceId = SERVICES.get('security')?.deviceId || '';
        await apiRequest('api/preferences/personal.php', { deviceId, ...personalWeatherPrefs });
        saveJSON(STORAGE.personalPending, false);
        if (notify && !fromSync) showToast(t('personal.toast.saved.title'), t('personal.toast.saved.copy'), 'success');
    }
    catch (error) {
        saveJSON(STORAGE.personalPending, true);
        requestSafeBackgroundSync().catch(() => null);
        if (notify && !fromSync) showToast(t('personal.toast.error.title'), error.message, 'warning');
        throw error;
    }
}
async function synchronizePersonalWeatherPreferences({ force = false } = {}) {
    if (isGuestSession() || !navigator.onLine || !hasVerifiedServerSession()) return false;
    if (!force && !loadJSON(STORAGE.personalPending, false)) return false;
    try {
        await savePersonalWeatherPreferences({ notify: false, fromSync: true });
        return !loadJSON(STORAGE.personalPending, false);
    }
    catch {
        return false;
    }
}
function modelVerificationForecasts(model) {
    const hourly = model?.raw?.hourly;
    if (!hourly?.time?.length) return [];
    return [1, 3, 6, 24, 48, 72].flatMap(horizon => {
        const index = Number(model.start || 0) + horizon;
        const targetTime = hourly.time?.[index];
        if (!targetTime) return [];
        return [{
            model: model.id,
            targetTime,
            horizonHours: horizon,
            temperature: Number(hourly.temperature_2m?.[index] ?? 0),
            precipitation: Number(hourly.precipitation?.[index] ?? 0),
            windGust: Number(hourly.wind_gusts_10m?.[index] ?? hourly.wind_speed_10m?.[index] ?? 0)
        }];
    });
}
async function syncVerifiedModelAccuracy({ force = false } = {}) {
    if (isGuestSession() || !isUiFeatureVisible('feature.model.accuracy') || !state.weather || !state.intelligence.models?.length) return;
    if (!force && Date.now() - accuracySyncAt < 10 * 60 * 1000) return;
    accuracySyncAt = Date.now();
    const deviceId = SERVICES.get('security')?.deviceId || '';
    const locationKey = intelligenceLocationKey();
    const current = state.weather.current || {};
    const observation = {
        time: current.time || new Date().toISOString(),
        temperature: Number(current.temperature_2m ?? 0),
        precipitation: Number(current.precipitation ?? current.rain ?? 0),
        windGust: Number(current.wind_gusts_10m ?? current.wind_speed_10m ?? 0)
    };
    const forecasts = state.intelligence.models.flatMap(modelVerificationForecasts);
    try {
        await apiRequest('api/accuracy/models.php', { deviceId, locationKey, observation, forecasts });
        const data = await apiRequest(`api/accuracy/models.php?deviceId=${encodeURIComponent(deviceId)}&locationKey=${encodeURIComponent(locationKey)}`);
        state.intelligence.accuracy = data;
        saveJSON(STORAGE.modelWeights, data);
        state.intelligence.ensemble = buildAutoCalibratedEnsemble();
        renderVerifiedModelAccuracy(data);
        renderModelComparison();
    }
    catch (error) {
        console.warn('MODEL_ACCURACY_SYNC_FAILED', error);
        const status = $('#accuracy-status');
        if (status) status.textContent = t('accuracy.status.unavailable');
    }
}

let publicAccuracyCache={key:'',at:0,data:null};
function renderPublicLocalAccuracy(data){
    const grid=$('#accuracy-public-grid'),status=$('#accuracy-public-status'),foot=$('#accuracy-public-foot');if(!grid||!status||!foot)return;
    const metrics=data?.metrics||{};if(data?.status!=='verified'){
        status.textContent=t('accuracy.public.learning');grid.innerHTML=`<div class="accuracy-public-learning"><strong>${escapeHTML(t('accuracy.public.learning_title'))}</strong><p>${escapeHTML(t('accuracy.public.learning_copy',{samples:Number(data?.minimumSamples||20)}))}</p></div>`;foot.textContent=t('accuracy.public.privacy');return;
    }
    status.textContent=t('accuracy.public.verified');const cards=[];
    if(metrics.temperature)cards.push([t('accuracy.metric.temperature'),`${Number(metrics.temperature.maeC||0).toFixed(1)} °C`,t('accuracy.metric.mae'),metrics.temperature.samples]);
    if(metrics.rain)cards.push([t('accuracy.metric.rain'),`${Number(metrics.rain.precisionPct||0).toFixed(0)}% / ${Number(metrics.rain.recallPct||0).toFixed(0)}%`,t('accuracy.metric.precision_recall'),metrics.rain.samples]);
    if(metrics.storm)cards.push([t('accuracy.metric.storm'),`${Number(metrics.storm.falseAlarmRatioPct||0).toFixed(0)}%`,t('accuracy.metric.false_alarm'),metrics.storm.samples]);
    if(metrics.radarEta)cards.push([t('accuracy.metric.radar'),`${Number(metrics.radarEta.maeMinutes||0).toFixed(1)} min`,t('accuracy.metric.within',{value:Number(metrics.radarEta.withinTolerancePct||0).toFixed(0)}),metrics.radarEta.samples]);
    grid.innerHTML=cards.map(([label,value,note,samples])=>`<article class="accuracy-public-card"><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong><span>${escapeHTML(note)}</span><em>${escapeHTML(t('accuracy.metric.samples',{count:Number(samples||0)}))}</em></article>`).join('');
    foot.textContent=t('accuracy.public.foot',{days:Number(data.windowDays||60),count:Number(data.verifiedChecks||0)});
}
async function loadPublicLocalAccuracy({force=false}={}){
    const key=intelligenceLocationKey();if(!force&&publicAccuracyCache.key===key&&Date.now()-publicAccuracyCache.at<10*60*1000&&publicAccuracyCache.data){renderPublicLocalAccuracy(publicAccuracyCache.data);return;}
    try{const response=await fetch(`api/accuracy/public.php?locationKey=${encodeURIComponent(key)}`,{method:'GET',headers:{Accept:'application/json'},cache:'no-store',credentials:'omit'});const data=await response.json().catch(()=>({}));if(!response.ok||data.ok===false)throw new Error(data.message||`HTTP_${response.status}`);publicAccuracyCache={key,at:Date.now(),data};renderPublicLocalAccuracy(data);}catch(error){const status=$('#accuracy-public-status'),grid=$('#accuracy-public-grid');if(status)status.textContent=t('accuracy.public.unavailable');if(grid)grid.innerHTML=`<div class="advanced-empty">${escapeHTML(t('accuracy.public.unavailable_copy'))}</div>`;}
}

function modelWeightSet(metric, horizonHours = 1) {
    const accuracy = state.intelligence.accuracy || loadJSON(STORAGE.modelWeights, null) || {};
    const buckets = accuracy.weightsByHorizon || {};
    const horizon = horizonHours <= 1 ? '1' : horizonHours <= 3 ? '3' : horizonHours <= 6 ? '6' : horizonHours <= 24 ? '24' : horizonHours <= 48 ? '48' : '72';
    const selected = buckets?.[horizon]?.[metric] || accuracy.weights?.[metric] || {};
    const available = (state.intelligence.models || []).map(model => model.id);
    const valid = Object.fromEntries(Object.entries(selected).filter(([id, value]) => available.includes(id) && Number(value) > 0));
    const sum = Object.values(valid).reduce((total, value) => total + Number(value || 0), 0);
    if (sum > 0) return Object.fromEntries(Object.entries(valid).map(([id, value]) => [id, Number(value) / sum]));
    if (!available.length) return {};
    return Object.fromEntries(available.map(id => [id, 1 / available.length]));
}
function weightedModelHourlyValue(metric, offset) {
    const models = state.intelligence.models || [];
    if (!models.length) return null;
    const key = metric === 'temperature' ? 'temperature_2m' : metric === 'rain' ? 'precipitation' : 'wind_gusts_10m';
    const weights = modelWeightSet(metric, offset + 1);
    let sum = 0, weightSum = 0;
    for (const model of models) {
        const index = Number(model.start || 0) + offset;
        const hourly = model.raw?.hourly || {};
        let value = Number(hourly[key]?.[index]);
        if (!Number.isFinite(value) && metric === 'wind') value = Number(hourly.wind_speed_10m?.[index]);
        if (!Number.isFinite(value)) continue;
        const weight = Number(weights[model.id] || 0);
        if (weight <= 0) continue;
        sum += value * weight;
        weightSum += weight;
    }
    return weightSum > 0 ? sum / weightSum : null;
}
function buildAutoCalibratedEnsemble() {
    const models = state.intelligence.models || [];
    if (!models.length) return null;
    const times = models[0]?.raw?.hourly?.time || [];
    const start = Number(models[0]?.start || 0);
    const rows = [];
    for (let offset = 0; offset < 12; offset += 1) {
        const time = times[start + offset];
        if (!time) break;
        rows.push({
            time,
            temperature: weightedModelHourlyValue('temperature', offset),
            precipitation: weightedModelHourlyValue('rain', offset),
            wind: weightedModelHourlyValue('wind', offset)
        });
    }
    if (!rows.length) return null;
    const accuracyRows = Array.isArray(state.intelligence.accuracy?.rows) ? state.intelligence.accuracy.rows : [];
    const sampleCount = accuracyRows.reduce((max, row) => Math.max(max, Number(row.samples || 0)), 0);
    const calibrated = sampleCount >= Number(state.intelligence.accuracy?.minimumSamples || 6);
    const rainIndex = rows.findIndex(row => Number(row.precipitation || 0) >= .1);
    const overallWeights = modelWeightSet('overall', 3);
    const dominant = Object.entries(overallWeights).sort((a,b) => b[1] - a[1])[0]?.[0] || '';
    return {
        rows,
        calibrated,
        sampleCount,
        dominantModel: dominant,
        temperatureMax: Math.max(...rows.map(row => Number(row.temperature ?? -99))),
        precipitationTotal: rows.reduce((sum,row) => sum + Math.max(0,Number(row.precipitation || 0)),0),
        windMax: Math.max(...rows.map(row => Number(row.wind || 0))),
        firstRain: rainIndex >= 0 ? rows[rainIndex].time : '',
        weights: {
            overall: modelWeightSet('overall', 3),
            temperature: modelWeightSet('temperature', 3),
            rain: modelWeightSet('rain', 3),
            wind: modelWeightSet('wind', 3)
        }
    };
}
function ensembleWeightLabel(weights = {}) {
    const rows = Object.entries(weights).sort((a,b) => Number(b[1]) - Number(a[1])).slice(0, 2);
    return rows.map(([id,value]) => `${modelDisplayName(id)} ${Math.round(Number(value) * 100)}%`).join(' · ');
}

function renderVerifiedModelAccuracy(data) {
    const root = $('#accuracy-model-list');
    const bestRoot = $('#accuracy-best');
    const status = $('#accuracy-status');
    if (!root || !bestRoot) return;
    const rows = Array.isArray(data?.rows) ? data.rows : [];
    const minimum = Math.max(1, Number(data?.minimumSamples || 6));
    const maxSamples = rows.length ? Math.max(...rows.map(row => Number(row.samples || 0))) : 0;
    const calibrated = maxSamples >= minimum;
    const liveConfidence = confidenceFromModels();
    if (status) status.textContent = calibrated
        ? t('accuracy.status.verified')
        : t('accuracy.status.progress', { count: maxSamples, total: minimum, agreement: liveConfidence.score });
    const best = data?.best || {};
    const models = Array.isArray(state.intelligence.models) ? state.intelligence.models : [];
    if (calibrated) {
        const bestItems = [
            ['accuracy.best.overall', best.overall],
            ['accuracy.best.temperature', best.temperature],
            ['accuracy.best.rain', best.rain],
            ['accuracy.best.wind', best.wind]
        ];
        bestRoot.innerHTML = bestItems.map(([key, model]) => `<div><small>${escapeHTML(t(key))}</small><strong>${escapeHTML(model ? modelDisplayName(model) : t('accuracy.learning.short'))}</strong></div>`).join('');
    } else {
        const liveItems = [
            [t('accuracy.live.agreement'), `${liveConfidence.score}/100`],
            [t('accuracy.live.models'), String(models.length)],
            [t('accuracy.live.samples'), `${maxSamples}/${minimum}`],
            [t('accuracy.live.mode'), t('accuracy.live.relative')]
        ];
        bestRoot.innerHTML = liveItems.map(([label, value]) => `<div><small>${escapeHTML(label)}</small><strong>${escapeHTML(value)}</strong></div>`).join('');
    }
    if (!rows.length) {
        if (!models.length) {
            root.innerHTML = `<div class="advanced-empty">${escapeHTML(t('accuracy.empty'))}</div>`;
            return;
        }
        root.innerHTML = models.map(model => `<div class="accuracy-model-row accuracy-live-row"><span><small>${escapeHTML(t('accuracy.metric.model'))}</small><strong>${escapeHTML(modelDisplayName(model.id))}</strong></span><span><small>${escapeHTML(t('accuracy.live.tempmax'))}</small><strong>${Number(model.temperatureMax || 0).toFixed(1)} °C</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.live.rain24'))}</small><strong>${Number(model.precipitationTotal || 0).toFixed(1)} mm</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.live.windmax'))}</small><strong>${Number(model.windMax || 0).toFixed(0)} km/h</strong></span><span class="accuracy-score-pill"><small>${escapeHTML(t('accuracy.live.label'))}</small><strong>${escapeHTML(t('accuracy.live.now'))}</strong></span></div>`).join('');
        return;
    }
    root.innerHTML = rows.map(row => `<div class="accuracy-model-row"><span><small>${escapeHTML(t('accuracy.metric.model'))}</small><strong>${escapeHTML(modelDisplayName(row.model))}</strong></span><span><small>${escapeHTML(t('accuracy.metric.temperature'))}</small><strong>${Number(row.tempMae).toFixed(1)} °C</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.metric.rain'))}</small><strong>${Number(row.rainMae).toFixed(2)} mm</strong></span><span class="accuracy-secondary"><small>${escapeHTML(t('accuracy.metric.wind'))}</small><strong>${Number(row.windMae).toFixed(1)} km/h</strong></span><span class="accuracy-score-pill"><small>${escapeHTML(t('accuracy.metric.samples', { count: row.samples }))}</small><strong>${row.score}/100</strong></span></div>`).join('');
}

function currentLocationHour(date = new Date()) {
    const timeZone = resolveLocationTimeZone(state.location, state.weather);
    try {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(date);
        const value = Number(parts.find(part => part.type === 'hour')?.value);
        if (Number.isInteger(value)) return clamp(value, 0, 23);
    }
    catch { }
    return clamp(date.getHours(), 0, 23);
}
function effectiveBriefingHour() {
    if (personalWeatherPrefs.briefingHourSet === true && Number.isInteger(Number(personalWeatherPrefs.briefingHour)))
        return clamp(Number(personalWeatherPrefs.briefingHour), 0, 23);
    return currentLocationHour();
}
function syncBriefingControls() {
    const enabled = $('#briefing-enabled');
    const hour = $('#briefing-hour');
    const proactive = $('#proactive-enabled');
    if (enabled) enabled.checked = personalWeatherPrefs.briefingEnabled === true;
    if (proactive) proactive.checked = personalWeatherPrefs.proactiveEnabled === true;
    if (hour) {
        const selectedHour = effectiveBriefingHour();
        hour.value = String(selectedHour);
        if (typeof syncEnhancedSelect === 'function') syncEnhancedSelect('briefing-hour', hour.value);
    }
}
function briefingLocalDateKey() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
function briefingAiContext() {
    const current = state.weather?.current || {};
    const h = state.weather?.hourly || {};
    const start = currentHourlyIndex(state.weather);
    const hourlyForecast = (h.time || []).slice(start, start + 18).map((time, offset) => {
        const index = start + offset;
        return {
            time,
            condition: weatherMeta(Number(h.weather_code?.[index] ?? 0), 1).label,
            temperatureC: Number(h.temperature_2m?.[index] ?? 0),
            feelsLikeC: Number(h.apparent_temperature?.[index] ?? h.temperature_2m?.[index] ?? 0),
            rainProbability: Number(h.precipitation_probability?.[index] ?? 0),
            precipitationMm: Number(h.precipitation?.[index] ?? 0),
            windKmh: Number(h.wind_speed_10m?.[index] ?? 0),
            gustKmh: Number(h.wind_gusts_10m?.[index] ?? 0),
            humidity: Number(h.relative_humidity_2m?.[index] ?? 0),
            pressureHpa: Number(h.surface_pressure?.[index] ?? 0),
            visibilityKm: Number(h.visibility?.[index] ?? 10000) / 1000,
            uvIndex: Number(h.uv_index?.[index] ?? 0)
        };
    });
    const nowcast = extractNowcast();
    const impact = personalWeatherPrefs.activities.flatMap(id => {
        const summary = impactSummary(id);
        if (!summary) return [];
        const meta = impactActivityMeta(id);
        const end = new Date(summary.end);
        end.setHours(end.getHours() + 1);
        return [{
            activity: t(meta.label),
            score: summary.score,
            bestStart: summary.start,
            bestEnd: end.toISOString(),
            reason: t(summary.reason.key, summary.reason.params || {})
        }];
    });
    return {
        generatedAt: new Date().toISOString(),
        location: { name: shortLocationLabel(state.location), timezone: state.weather?.timezone || state.location?.timezone || '' },
        current: {
            time: current.time || new Date().toISOString(),
            condition: weatherMeta(Number(current.weather_code ?? 0), Number(current.is_day ?? 1)).label,
            temperatureC: Number(current.temperature_2m ?? 0),
            feelsLikeC: Number(current.apparent_temperature ?? current.temperature_2m ?? 0),
            precipitationMm: Number(current.precipitation ?? 0),
            windKmh: Number(current.wind_speed_10m ?? 0),
            gustKmh: Number(current.wind_gusts_10m ?? 0),
            humidity: Number(current.relative_humidity_2m ?? 0),
            pressureHpa: Number(current.surface_pressure ?? 0)
        },
        hourlyForecast,
        nowcast: {
            rainingNow: nowcast.rainingNow === true,
            expectedStart: nowcast.start || '',
            expectedEnd: nowcast.end || '',
            totalMm: Number(nowcast.total || 0),
            reliability: nowcastProReliability(nowcast)
        },
        changes: proactiveChangeMetrics(),
        radarPrediction: state.radar.motion ? {
            rainingNow: state.radar.motion.rainingNow === true,
            etaMinutes: optionalFiniteNumber(state.radar.motion.etaMinutes),
            exitMinutes: optionalFiniteNumber(state.radar.motion.exitMinutes),
            direction: String(state.radar.motion.direction || ''),
            speedKmh: Number(state.radar.motion.speedKmh || 0),
            confidence: Number(state.radar.motion.confidence || 0)
        } : {},
        ensemble: state.intelligence.ensemble ? {
            calibrated: state.intelligence.ensemble.calibrated === true,
            dominantModel: String(state.intelligence.ensemble.dominantModel || ''),
            sampleCount: Number(state.intelligence.ensemble.sampleCount || 0),
            temperatureMax: Number(state.intelligence.ensemble.temperatureMax || 0),
            precipitationTotal: Number(state.intelligence.ensemble.precipitationTotal || 0),
            windMax: Number(state.intelligence.ensemble.windMax || 0)
        } : {},
        severeEvents: state.severeWeather.authoritative === true && state.severeWeather.degraded !== true
            ? (state.severeWeather.events || []).slice(0, 4).map(event => ({
                type: String(event.type || ''), severity: String(event.severity || ''),
                confidence: Number(event.confidence || 0), title: String(event.title || ''),
                body: String(event.body || ''), startsAt: String(event.startsAt || event.start || ''),
                etaMinutes: optionalFiniteNumber(event.etaMinutes)
            })) : [],
        impact
    };
}
function briefingHistoryEntries() {
    const rows = loadJSON(STORAGE.briefingHistory, []);
    return Array.isArray(rows) ? rows.filter(row => row && typeof row.answer === 'string' && row.answer.trim()).slice(0, 7) : [];
}
function rememberBriefing(entry) {
    if (!entry?.answer) return;
    const next = [entry, ...briefingHistoryEntries().filter(row => row.answer !== entry.answer || row.date !== entry.date)].slice(0, 7);
    saveJSON(STORAGE.briefingHistory, next);
}
function briefingPreviousEntry() {
    const current = loadJSON(STORAGE.briefingDaily, null);
    if (current?.answer) return current;
    return briefingHistoryEntries()[0] || null;
}
function briefingInlineHtml(value) {
    const safe = escapeHTML(String(value || ''));
    return safe
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');
}
function briefingAnswerHtml(answer) {
    const lines = String(answer || '').replace(/\r/g, '').split('\n');
    let html = '<div class="briefing-rich">';
    let inList = false;
    let contentLines = 0;
    const closeList = () => {
        if (!inList) return;
        html += '</ul>';
        inList = false;
    };
    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) {
            closeList();
            continue;
        }
        const bullet = line.match(/^[-•]\s+(.+)$/);
        if (bullet) {
            if (!inList) {
                html += '<ul>';
                inList = true;
            }
            html += `<li>${briefingInlineHtml(bullet[1])}</li>`;
            contentLines += 1;
            continue;
        }
        closeList();
        const heading = line.match(/^(?:#{1,4}\s*)?\*\*([^*]+)\*\*\s*:?\s*(.*)$/);
        if (heading) {
            const label = briefingInlineHtml(heading[1].trim());
            const rest = String(heading[2] || '').trim();
            if (contentLines === 0 && !rest)
                html += `<h3>${label}</h3>`;
            else
                html += `<h4>${label}</h4>`;
            if (rest) html += `<p>${briefingInlineHtml(rest)}</p>`;
            contentLines += 1;
            continue;
        }
        const stripped = line.replace(/^#{1,4}\s+/, '').replace(/^\*\*(.+)\*\*$/, '$1');
        html += `<p>${briefingInlineHtml(stripped)}</p>`;
        contentLines += 1;
    }
    closeList();
    html += '</div>';
    return html;
}
function localBriefingPreview() {
    const current = state.weather?.current || {};
    const daily = state.weather?.daily || {};
    const times = Array.isArray(daily.time) ? daily.time : [];
    const temp = Number(current.temperature_2m);
    const condition = weatherMeta(Number(current.weather_code ?? 0), Number(current.is_day ?? 1)).label;
    let risk = null;
    for (let index = 0; index < Math.min(4, times.length); index += 1) {
        const probability = Math.round(Number(daily.precipitation_probability_max?.[index] || 0));
        const code = Number(daily.weather_code?.[index] || 0);
        const rainMm = Number(daily.precipitation_sum?.[index] || 0);
        if ([95,96,99].includes(code) || probability >= 50 || rainMm >= 1.5) {
            risk = { index, probability, code, rainMm, date: times[index] };
            break;
        }
    }
    const location = shortLocationLabel(state.location);
    const temperatureText = Number.isFinite(temp) ? temperature(temp) : '--';
    if (risk) {
        const phenomenon = [95,96,99].includes(risk.code) ? t('briefing.local.storm') : t('briefing.local.rain');
        return {
            title: t('briefing.local.title'),
            copy: t('briefing.local.risk', { location, condition, temperature: temperatureText, date: formatShortDate(risk.date), phenomenon, probability: risk.probability })
        };
    }
    return {
        title: t('briefing.local.title'),
        copy: t('briefing.local.clear', { location, condition, temperature: temperatureText })
    };
}
function renderStoredBriefing() {
    const output = $('#briefing-output');
    if (!output) return;
    const stored = loadJSON(STORAGE.briefingDaily, null);
    output.classList.remove('is-thinking');
    if (stored?.answer && stored.date === briefingLocalDateKey()) {
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('briefing.ready.title'))}</strong>${briefingAnswerHtml(stored.answer)}</div>`;
        return;
    }
    if (!state.weather) return;
    const preview = localBriefingPreview();
    output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(preview.title)}</strong><p>${escapeHTML(preview.copy)}</p><small class="proactive-meta">${escapeHTML(t('briefing.local.ai_note'))}</small></div>`;
}

async function generateAiBriefing({ manual = true } = {}) {
    if (briefingGenerating || isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession())) return;
    briefingGenerating = true;
    SERVICES.get('panelLoader')?.set?.('#ai-briefing-panel', true, t('panel.loading'));
    const output = $('#briefing-output');
    if (output) {
        output.classList.add('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('briefing.thinking.title'))}</strong><p>${escapeHTML(t('briefing.thinking.copy'))}</p></div>`;
    }
    const activities = personalWeatherPrefs.activities.map(id => t(impactActivityMeta(id).label)).join(', ') || t('briefing.activities.none');
    const previous = briefingPreviousEntry();
    const previousAnswer = String(previous?.answer || '').trim();
    const prompt = [
        t('briefing.ai.prompt.v2', { location: shortLocationLabel(state.location), activities }),
        previousAnswer ? t('briefing.ai.previous_hint') : ''
    ].filter(Boolean).join('\n');
    try {
        const result = await apiRequest('api/ai/chat.php', {
            mode: 'briefing',
            message: prompt,
            language: state.settings.language,
            messages: previousAnswer ? [{ role: 'assistant', content: previousAnswer.slice(0, 1800) }] : [],
            context: briefingAiContext()
        }, { timeout: 55000 });
        const answer = String(result?.answer || '').trim();
        if (!answer) throw new Error(t('briefing.error.empty'));
        const oldDaily = loadJSON(STORAGE.briefingDaily, null);
        if (oldDaily?.answer) rememberBriefing(oldDaily);
        const next = {
            date: briefingLocalDateKey(),
            answer,
            provider: result.provider || '',
            model: result.model || '',
            at: Date.now()
        };
        saveJSON(STORAGE.briefingDaily, next);
        renderStoredBriefing();
        if (manual) showToast(t('briefing.toast.ready.title'), t('briefing.toast.ready.copy'), 'success');
    }
    catch (error) {
        if (output) {
            output.classList.remove('is-thinking');
            output.innerHTML = `<span><svg><use href="#i-alert"/></svg></span><div><strong>${escapeHTML(t('briefing.error.title'))}</strong><p>${escapeHTML(error.message || t('briefing.error.copy'))}</p></div>`;
        }
        if (manual) showToast(t('briefing.error.title'), error.message || t('briefing.error.copy'), 'warning');
    }
    finally {
        briefingGenerating = false;
        SERVICES.get('panelLoader')?.set?.('#ai-briefing-panel', false);
    }
}
function maybeGenerateMorningBriefing() {
    if (isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession()) || !personalWeatherPrefs.briefingEnabled || !isUiFeatureVisible('feature.ai.briefing') || !state.weather) return;
    const now = new Date();
    if (currentLocationHour(now) < effectiveBriefingHour()) return;
    const stored = loadJSON(STORAGE.briefingDaily, null);
    if (stored?.date === briefingLocalDateKey() && stored?.answer) {
        renderStoredBriefing();
        return;
    }
    generateAiBriefing({ manual: false });
}


let proactiveGenerating = false;
function proactiveStoredInsight() {
    const value = loadJSON(STORAGE.proactiveInsight, null);
    return value && typeof value === 'object' ? value : null;
}
function proactiveChangeMetrics() {
    const current = state.intelligence.currentSnapshot || createIntelligenceSnapshot();
    const previous = state.intelligence.previousSnapshot;
    if (!current || !previous || current.locationKey !== previous.locationKey) return {};
    const rainTimingShiftMinutes = current.firstRain && previous.firstRain
        ? Math.round((new Date(current.firstRain).getTime() - new Date(previous.firstRain).getTime()) / 60000)
        : null;
    return {
        comparedAt: previous.fetchedAt ? new Date(Number(previous.fetchedAt)).toISOString() : null,
        rainTimingShiftMinutes,
        rainAccumulationDeltaMm: Number((current.rain24 - previous.rain24).toFixed(2)),
        maxGustDeltaKmh: Number((current.windMax24 - previous.windMax24).toFixed(1)),
        maxTemperatureDeltaC: Number((current.tempMax24 - previous.tempMax24).toFixed(1)),
        stabilityScore: confidenceFromModels().score
    };
}
function proactiveTriggerSnapshot() {
    const triggers = [];
    let severity = 0;
    if (state.severeWeather.authoritative === true && state.severeWeather.degraded !== true) {
        for (const event of (state.severeWeather.events || []).slice(0, 2)) {
            const label = String(event.title || event.body || '').trim();
            if (label) triggers.push(label);
            const rank = { red: 99, orange: 94, yellow: 86 }[String(event.severity || '').toLowerCase()] || 86;
            severity = Math.max(severity, rank, Number(event.confidence || 0));
        }
    }
    const nowcast = extractNowcast();
    const nowMinutes = nowcast.firstWet >= 0 && nowcast.start
        ? Math.max(0, Math.round((new Date(nowcast.start).getTime() - Date.now()) / 60000))
        : null;
    if (nowcast.rainingNow) {
        triggers.push(t('proactive.trigger.raining'));
        severity = Math.max(severity, 88);
    } else if (Number.isFinite(nowMinutes) && nowMinutes <= 45) {
        triggers.push(t('proactive.trigger.rain_soon', { minutes: nowMinutes }));
        severity = Math.max(severity, 82 - Math.min(30, nowMinutes / 2));
    }
    const motion = state.radar.motion;
    const radarEta = optionalFiniteNumber(motion?.etaMinutes);
    if (motion && radarEta !== null && radarEta <= 45 && Number(motion.confidence || 0) >= 55) {
        triggers.push(t('proactive.trigger.radar_eta', { minutes: Math.round(radarEta), confidence: motion.confidence }));
        severity = Math.max(severity, 84);
    }
    const metrics = proactiveChangeMetrics();
    if (Number.isFinite(Number(metrics.rainTimingShiftMinutes)) && Math.abs(Number(metrics.rainTimingShiftMinutes)) >= 30) {
        triggers.push(t('proactive.trigger.rain_shift', {
            minutes: Math.abs(Number(metrics.rainTimingShiftMinutes)),
            direction: Number(metrics.rainTimingShiftMinutes) < 0 ? t('change.direction.earlier') : t('change.direction.later')
        }));
        severity = Math.max(severity, 68);
    }
    if (Math.abs(Number(metrics.maxGustDeltaKmh || 0)) >= 12) {
        triggers.push(t('proactive.trigger.wind_change', { value: Math.round(Math.abs(Number(metrics.maxGustDeltaKmh))) }));
        severity = Math.max(severity, 64);
    }
    const selectedImpacts = personalWeatherPrefs.activities.flatMap(id => {
        const summary = impactSummary(id);
        return summary ? [{ id, summary }] : [];
    });
    for (const item of selectedImpacts) {
        if (item.summary.score <= 55) {
            triggers.push(t('proactive.trigger.activity_risk', { activity: t(impactActivityMeta(item.id).label), score: item.summary.score }));
            severity = Math.max(severity, 72);
        } else if (item.summary.score >= 88) {
            triggers.push(t('proactive.trigger.activity_window', { activity: t(impactActivityMeta(item.id).label), time: formatClock(item.summary.start) }));
            severity = Math.max(severity, 54);
        }
    }
    const confidence = confidenceFromModels();
    if (confidence.score < 58) {
        triggers.push(t('proactive.trigger.low_confidence', { score: confidence.score }));
        severity = Math.max(severity, 62);
    }
    const fingerprintBase = [
        intelligenceLocationKey(),
        Math.floor(Date.now() / (30 * 60 * 1000)),
        ...triggers.map(value => String(value).slice(0, 90))
    ].join('|');
    let hash = 2166136261;
    for (let i = 0; i < fingerprintBase.length; i += 1) {
        hash ^= fingerprintBase.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    return {
        triggers,
        severity: Math.round(severity),
        fingerprint: (hash >>> 0).toString(16),
        metrics
    };
}
function renderProactiveInsight() {
    const panel = $('#proactive-ai-panel');
    const output = $('#proactive-output');
    const status = $('#proactive-status');
    if (!panel || !output) return;
    const enabled = personalWeatherPrefs.proactiveEnabled === true;
    if (status) {
        status.textContent = enabled ? t('proactive.status.on') : t('proactive.status.off');
        status.classList.toggle('active', enabled);
    }
    const stored = proactiveStoredInsight();
    if (stored?.answer) {
        output.classList.remove('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.ready.title'))}</strong>${briefingAnswerHtml(stored.answer)}<small class="proactive-meta">${escapeHTML(t('proactive.ready.meta', { time: formatClock(new Date(stored.at || Date.now())) }))}</small></div>`;
        return;
    }
    output.classList.remove('is-thinking');
    if (state.weather && !isGuestSession()) {
        const snapshot = proactiveTriggerSnapshot();
        const triggers = Array.isArray(snapshot.triggers) ? snapshot.triggers.slice(0, 3) : [];
        const body = triggers.length
            ? `<ul class="proactive-local-list">${triggers.map(item => `<li>${escapeHTML(item)}</li>`).join('')}</ul>`
            : `<p>${escapeHTML(t('proactive.local.clear'))}</p>`;
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.local.title'))}</strong>${body}<small class="proactive-meta">${escapeHTML(t(enabled ? 'proactive.local.enabled_note' : 'proactive.local.disabled_note'))}</small></div>`;
        return;
    }
    output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t(enabled ? 'proactive.monitoring.title' : 'proactive.empty.title'))}</strong><p>${escapeHTML(t(enabled ? 'proactive.monitoring.copy' : 'proactive.empty.copy'))}</p></div>`;
}

async function maybeGenerateProactiveInsight({ manual = false, force = false } = {}) {
    if (proactiveGenerating || isGuestSession() || (navigator.onLine !== false && !hasVerifiedServerSession()) || !isUiFeatureVisible('feature.ai.proactive')) return null;
    if (!personalWeatherPrefs.proactiveEnabled && !manual) return null;
    if (!navigator.onLine) {
        if (manual) showToast(t('proactive.offline.title'), t('proactive.offline.copy'), 'warning');
        return null;
    }
    const trigger = proactiveTriggerSnapshot();
    if (!manual && trigger.severity < 55) {
        renderProactiveInsight();
        return null;
    }
    const previous = proactiveStoredInsight();
    const sameFingerprint = previous?.fingerprint === trigger.fingerprint;
    const recent = previous?.at && Date.now() - Number(previous.at) < 3 * 60 * 60 * 1000;
    if (!force && !manual && sameFingerprint && recent) return previous;
    const dayKey = briefingLocalDateKey();
    const dailyCount = previous?.day === dayKey ? Number(previous.dailyCount || 0) : 0;
    if (!manual && dailyCount >= 3) return previous;

    proactiveGenerating = true;
    SERVICES.get('panelLoader')?.set?.('#proactive-ai-panel', true, t('panel.loading'));
    const output = $('#proactive-output');
    if (output) {
        output.classList.add('is-thinking');
        output.innerHTML = `<span><svg><use href="#i-spark"/></svg></span><div><strong>${escapeHTML(t('proactive.thinking.title'))}</strong><p>${escapeHTML(t('proactive.thinking.copy'))}</p></div>`;
    }
    const triggerText = trigger.triggers.length ? trigger.triggers.join(' | ') : t('proactive.trigger.none');
    const prompt = t('proactive.ai.prompt', { location: shortLocationLabel(state.location), triggers: triggerText });
    try {
        const result = await apiRequest('api/ai/chat.php', {
            mode: 'proactive',
            message: prompt,
            language: state.settings.language,
            messages: previous?.answer ? [{ role: 'assistant', content: String(previous.answer).slice(0, 1200) }] : [],
            context: briefingAiContext()
        }, { timeout: 50000 });
        const answer = String(result?.answer || '').trim();
        if (!answer) throw new Error(t('proactive.error.empty'));
        const next = {
            answer,
            at: Date.now(),
            day: dayKey,
            dailyCount: dailyCount + 1,
            fingerprint: trigger.fingerprint,
            severity: trigger.severity,
            provider: result.provider || '',
            model: result.model || ''
        };
        saveJSON(STORAGE.proactiveInsight, next);
        renderProactiveInsight();
        if (manual) showToast(t('proactive.toast.ready.title'), t('proactive.toast.ready.copy'), 'success');
        else showToast(t('proactive.toast.new.title'), t('proactive.toast.new.copy'), 'info', 5200);
        return next;
    }
    catch (error) {
        if (output) {
            output.classList.remove('is-thinking');
            output.innerHTML = `<span><svg><use href="#i-alert"/></svg></span><div><strong>${escapeHTML(t('proactive.error.title'))}</strong><p>${escapeHTML(error.message || t('proactive.error.copy'))}</p></div>`;
        }
        if (manual) showToast(t('proactive.error.title'), error.message || t('proactive.error.copy'), 'warning');
        return null;
    }
    finally {
        proactiveGenerating = false;
        SERVICES.get('panelLoader')?.set?.('#proactive-ai-panel', false);
    }
}

let thresholdsFollowRaf = 0;
let thresholdsFollowObserver = null;
function updateThresholdsPanelFollow() {
    thresholdsFollowRaf = 0;
    const panel = $('#thresholds-panel');
    const dashboard = $('#page-alerts .alerts-dashboard');
    const page = $('#page-alerts');
    if (!panel || !dashboard || !page)
        return;
    const desktop = window.matchMedia('(min-width: 1101px)').matches;
    if (!desktop || state.currentPage !== 'alerts' || !page.classList.contains('active-page')) {
        panel.style.removeProperty('--threshold-follow-y');
        return;
    }
    const dashboardRect = dashboard.getBoundingClientRect();
    const topbarRect = $('.topbar')?.getBoundingClientRect();
    const topGuard = Math.max(0, topbarRect?.bottom || 0) + 18;
    const desired = topGuard - dashboardRect.top;
    const maxOffset = Math.max(0, dashboard.scrollHeight - panel.offsetHeight);
    const offset = clamp(desired, 0, maxOffset);
    panel.style.setProperty('--threshold-follow-y', `${Math.round(offset)}px`);
}
function scheduleThresholdsPanelFollow() {
    if (thresholdsFollowRaf)
        return;
    thresholdsFollowRaf = requestAnimationFrame(updateThresholdsPanelFollow);
}
function initializeThresholdsPanelFollow() {
    document.addEventListener('scroll', scheduleThresholdsPanelFollow, { passive: true, capture: true });
    window.addEventListener('resize', scheduleThresholdsPanelFollow, { passive: true });
    window.visualViewport?.addEventListener('resize', scheduleThresholdsPanelFollow, { passive: true });
    window.visualViewport?.addEventListener('scroll', scheduleThresholdsPanelFollow, { passive: true });
    if ('ResizeObserver' in window) {
        thresholdsFollowObserver = new ResizeObserver(scheduleThresholdsPanelFollow);
        const panel = $('#thresholds-panel');
        const dashboard = $('#page-alerts .alerts-dashboard');
        const mainColumn = $('#page-alerts .alerts-main-column');
        if (panel)
            thresholdsFollowObserver.observe(panel);
        if (dashboard)
            thresholdsFollowObserver.observe(dashboard);
        if (mainColumn)
            thresholdsFollowObserver.observe(mainColumn);
    }
    scheduleThresholdsPanelFollow();
}
function pruneAlertReadState() {
    const now = Date.now();
    const cutoff = now - 14 * 86400000;
    const entries = Object.entries(state.alertReads || {})
        .filter(([, value]) => Number(value) >= cutoff)
        .sort((a, b) => Number(b[1]) - Number(a[1]))
        .slice(0, 160);
    state.alertReads = Object.fromEntries(entries);
    saveJSON(STORAGE.alertReads, state.alertReads);
}
function alertStateId(key) {
    return `${locationKey()}:${String(key || '')}`;
}
function isAlertRead(id) {
    return Boolean(id && Number(state.alertReads?.[id] || 0) > 0);
}
function markAlertRead(id) {
    if (!id) return;
    state.alertReads ||= {};
    state.alertReads[id] = Date.now();
    pruneAlertReadState();
}
function officialAlertStateId(warning) {
    if (!warning) return '';
    const raw = officialAlertEventId(warning.id || warning.providerAlertId || warning.identifier || warning.title || 'official');
    // A material revision (severity/validity change) becomes unread again, while
    // repeated fetches of the same official warning keep the read state.
    const starts = String(warning.startsAt || '').slice(0, 16);
    const ends = String(warning.endsAt || '').slice(0, 16);
    const severity = String(warning.severity || warning.level || '').toLowerCase();
    return alertStateId(`official:${raw}:${starts}:${ends}:${severity}`);
}
function activeOfficialAlertUnreadCount() {
    const rows = Array.isArray(state.officialAlerts?.relevant) ? state.officialAlerts.relevant : [];
    if (!rows.length) return 0;
    return rows.reduce((count, warning) => count + (isAlertRead(officialAlertStateId(warning)) ? 0 : 1), 0);
}
function updateAlertBadgeCount() {
    const localUnread = Math.max(0, Number(state.alertWeatherUnreadCount || 0)) + activeOfficialAlertUnreadCount();
    const inboxUnread = !isGuestSession() && state.notificationInboxLoaded ? Math.max(0, Number(state.notificationInboxUnread || 0)) : 0;
    const total = Math.max(0, localUnread + inboxUnread);
    const label = total > 99 ? '99+' : String(total);
    const navAlertCount = $('#alert-count');
    if (navAlertCount) { navAlertCount.textContent = label; navAlertCount.hidden = total === 0; }
    const mobileAlertCount = $('#mobile-alert-count');
    if (mobileAlertCount) { mobileAlertCount.textContent = label; mobileAlertCount.hidden = total === 0; }
}
function markAllCurrentAlertsRead() {
    $$('[data-alert-id]', $('#alerts-list')).forEach(node => markAlertRead(String(node.dataset.alertId || '')));
    const officialRows = Array.isArray(state.officialAlerts?.relevant) ? state.officialAlerts.relevant : [];
    officialRows.forEach(warning => markAlertRead(officialAlertStateId(warning)));
    renderAlerts();
    renderNotificationOfficialAlert();
}
function renderAlerts() {
    if (!$('#alerts-summary')) return;
    if (!state.weather?.daily) {
        state.alertWeatherUnreadCount = 0;
        updateAlertBadgeCount();
        return;
    }
    pruneAlertReadState();
    const daily = state.weather.daily;
    const alerts = [];
    const days = daily.time.slice(0, 7).map((date, index) => {
        const when = formatDay(date, index);
        const shortDate = formatShortDate(date);
        const rain = Number(daily.precipitation_probability_max[index] || 0);
        const rainMm = Number(daily.precipitation_sum[index] || 0);
        const wind = Number(daily.wind_gusts_10m_max[index] || 0);
        const heat = Number(daily.temperature_2m_max[index] || 0);
        const low = Number(daily.temperature_2m_min[index] || 0);
        const code = Number(daily.weather_code[index]);
        const storm = [95, 96, 99].includes(code);
        const ratios = {
            rain: state.thresholds.rain > 0 ? rain / state.thresholds.rain : 0,
            wind: state.thresholds.wind > 0 ? wind / state.thresholds.wind : 0,
            heat: state.thresholds.heat > 0 ? heat / state.thresholds.heat : 0,
            storm: storm ? 1.35 : 0
        };
        const maxRatio = Math.max(ratios.rain, ratios.wind, ratios.heat, ratios.storm);
        const level = maxRatio >= 1.15 ? 'danger' : maxRatio >= 1 ? 'warning' : maxRatio >= .72 ? 'watch' : 'calm';
        const levelLabel = level === 'danger' ? "" + meteonexaText("app.renderalerts.high.variant_2") : level === 'warning' ? "" + meteonexaText("app.renderalerts.caution") : level === 'watch' ? "" + meteonexaText("app.renderalerts.watch") : "" + meteonexaText("app.renderalerts.normal");
        if (storm)
            alerts.push({ key: `${date}:storm`, level: 'danger', icon: 'i-bell', title: "" + meteonexaText("notifications.notificationcandidates.possible_thunderstorms"), copy: "" + meteonexaText("app.renderalerts.thunderstorms_forecast_check_official_local_updates"), when });
        if (rain >= state.thresholds.rain)
            alerts.push({ key: `${date}:rain:${state.thresholds.rain}`, level: 'warning', icon: 'i-umbrella', title: "" + meteonexaText("app.renderalerts.high_rain_probability"), copy: meteonexaText("app.renderalerts.value_probability_value_mm_forecast", { value: Math.round(rain), mm: rainMm.toFixed(1) }), when });
        if (wind >= state.thresholds.wind)
            alerts.push({ key: `${date}:wind:${state.thresholds.wind}`, level: 'danger', icon: 'i-wind', title: "" + meteonexaText("app.renderalerts.strong_wind_gusts"), copy: meteonexaText("notifications.gusts_forecast_up_value_km_h", { value: Math.round(wind) }), when });
        if (heat >= state.thresholds.heat)
            alerts.push({ key: `${date}:heat:${state.thresholds.heat}`, level: 'warning', icon: 'i-sun', title: "" + meteonexaText("app.renderalerts.extreme_heat"), copy: meteonexaText("notifications.notificationcandidates.forecast_high_value", { value: temperature(heat) }), when });
        return { date, when, shortDate, rain, rainMm, wind, heat, low, code, level, levelLabel, maxRatio };
    });
    alerts.forEach(alert => { alert.id = alertStateId(alert.key); alert.read = isAlertRead(alert.id); });
    const unreadAlerts = alerts.filter(alert => !alert.read);
    state.alertWeatherUnreadCount = unreadAlerts.length;
    const severity = { calm: 0, watch: 1, warning: 2, danger: 3 };
    const peak = days.reduce((best, day) => severity[day.level] > severity[best.level] || (severity[day.level] === severity[best.level] && day.maxRatio > best.maxRatio) ? day : best, days[0]);
    const summary = $('#alerts-summary');
    const summaryLevel = alerts.some(item => item.level === 'danger') ? 'danger' : alerts.length ? 'warning' : peak?.level === 'watch' ? 'watch' : 'success';
    summary?.classList.remove('success', 'watch', 'warning', 'danger');
    summary?.classList.add(summaryLevel);
    const summaryTitle = alerts.length === 0
        ? (peak?.level === 'watch' ? "" + meteonexaText("app.renderalerts.stable_conditions_one_day_watch") : "" + meteonexaText("app.renderalerts.no_critical_issues_detected"))
        : unreadAlerts.length === 0 ? meteonexaText('alerts.all_read.title')
        : unreadAlerts.length === 1 ? meteonexaText('alerts.unread.one') : meteonexaText('alerts.unread.many', { count: unreadAlerts.length });
    const summaryText = alerts.length === 0
        ? "" + meteonexaText("app.renderalerts.next_seven_days_remain_below_set_limits_monitoring") : unreadAlerts.length === 0 ? meteonexaText('alerts.all_read.copy') : "" + meteonexaText("app.renderalerts.review_highlighted_events_adjust_thresholds_needs");
    $('#alerts-summary-title').textContent = summaryTitle;
    $('#alerts-summary-text').textContent = summaryText;
    $('#alerts-event-total').textContent = String(alerts.length);
    $('#alerts-peak-day').textContent = peak?.when || '--';
    $('#alerts-peak-level').textContent = peak?.levelLabel || "" + meteonexaText("weather.uvlabel.low");
    $('#alert-events-count').textContent = meteonexaText(alerts.length === 1 ? 'app.renderalerts.value_event' : 'app.renderalerts.value_events', { count: alerts.length });
    $('#alert-monitor-badge').textContent = meteonexaText("history.renderhistory.value_days_analyzed", { count: days.length });
    $('#alert-days').innerHTML = days.map(day => "" + "\n    <article class=\"alert-day-card " + day.level + "\" tabindex=\"0\" aria-label=\"" + escapeHTML(meteonexaText("app.renderalerts.value_value_risk", { day: day.when, level: day.levelLabel })) + "\">\n      <div class=\"alert-day-head\"><span><strong>" + escapeHTML(day.when) + "</strong><small>" + escapeHTML(day.shortDate) + "</small></span><em>" + escapeHTML(day.levelLabel) + "</em></div>\n      <div class=\"alert-day-weather\"><span class=\"alert-day-art\">" + weatherArt(day.code, 1) + "</span><div><strong>" + temperature(day.heat) + "</strong><small>" + escapeHTML(meteonexaText('temperature.minimum.inline', { value: temperature(day.low) })) + "</small></div></div>\n      <div class=\"alert-day-metrics\">\n        <span><svg><use href=\"#i-umbrella\"/></svg><b>" + Math.round(day.rain) + "%</b><small>" + day.rainMm.toFixed(1) + " mm</small></span>\n        <span><svg><use href=\"#i-wind\"/></svg><b>" + Math.round(day.wind) + "</b><small>km/h</small></span>\n        <span><svg><use href=\"#i-sun\"/></svg><b>" + temperature(day.heat) + "</b><small>" + escapeHTML(meteonexaText("app.renderalerts.high")) + "</small></span>\n      </div>\n      <div class=\"alert-risk-track\"><i style=\"--risk:" + Math.min(100, Math.round(day.maxRatio * 74)) + "%\"></i></div>\n    </article>").join('');
    if (alerts.length) {
        $('#alerts-list').innerHTML = alerts.slice(0, 10).map(alert => `<article class="alert-card ${alert.level}${alert.read ? ' is-read' : ''}" data-alert-id="${escapeHTML(alert.id)}"><span class="alert-card-icon"><svg><use href="#${alert.icon}"/></svg></span><div class="alert-card-main"><h3>${escapeHTML(alert.title)}</h3><p>${escapeHTML(alert.copy)}</p></div><div class="alert-card-actions">${alert.read ? '' : '<i class="alert-unread-dot" aria-hidden="true"></i>'}<span class="alert-when">${escapeHTML(alert.when)}</span>${alert.read ? '' : `<button class="alert-read-button" data-alert-mark-read type="button" aria-label="${escapeHTML(meteonexaText('alerts.mark_read'))}" title="${escapeHTML(meteonexaText('alerts.mark_read'))}"><svg><use href="#i-eye"/></svg></button>`}</div></article>`).join('');
    }
    else {
        $('#alerts-list').innerHTML = "" + "<div class=\"alerts-empty-state\"><span><svg><use href=\"#i-shield\"/></svg></span><div><strong>" + escapeHTML(meteonexaText("app.renderalerts.everything_under_control")) + "</strong><p>" + escapeHTML(meteonexaText("app.renderalerts.no_event_exceeds_current_thresholds_can_still_review")) + "</p></div></div>";
    }
    const markAll = $('#alerts-mark-all-read');
    if (markAll) markAll.hidden = unreadAlerts.length === 0 && activeOfficialAlertUnreadCount() === 0;
    updateAlertBadgeCount();
    scheduleThresholdsPanelFollow();
}

function renderDetailsTable() {
    const data = state.weather;
    const start = currentHourlyIndex(data);
    const rows = data.hourly.time.slice(start, start + 24).map((time, offset) => {
        const i = start + offset;
        const rowId = `hour-details-${i}`;
        const resolved = resolveFusedHourlyCondition(data, i);
        return "" + "<div class=\"hourly-table-row\" data-hour-row>\n      <strong class=\"hourly-time\">" + (offset === 0 ? "" + escapeHTML(meteonexaText("app.renderhourly.now")) : formatClock(time)) + "</strong>\n      <span class=\"condition-cell\">" + weatherArt(resolved.code, resolved.isDay) + "<b>" + escapeHTML(resolved.meta.label) + "</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("history.yrain.temperature")) + "</small><b>" + temperature(data.hourly.temperature_2m[i]) + "</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("history.renderhistory.rain")) + "</small><b>" + Number(resolved.precipitationMm || 0).toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + " mm · " + Math.round(data.hourly.precipitation_probability[i] || 0) + "%</b></span>\n      <span class=\"hourly-primary-value\"><small>" + escapeHTML(meteonexaText("visualization.take.wind")) + "</small><b>" + Math.round(data.hourly.wind_speed_10m[i] || 0) + " km/h</b></span>\n      <button class=\"hourly-row-toggle\" type=\"button\" aria-expanded=\"false\" aria-controls=\"" + rowId + "\" aria-label=\"" + escapeHTML(meteonexaText('hour.expand', { time: offset === 0 ? meteonexaText('hour.current_conditions') : formatClock(time) })) + "\"><svg><use href=\"#i-chevron\"/></svg></button>\n      <div class=\"hourly-row-details\" id=\"" + rowId + "\" hidden>\n        <span><small>" + escapeHTML(meteonexaText("history.renderhistory.feels_like")) + "</small><strong>" + temperature(data.hourly.apparent_temperature[i]) + "</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("visualization.take.humidity")) + "</small><strong>" + Math.round(data.hourly.relative_humidity_2m[i] || 0) + "%</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("app.openhourdetail.visibility")) + "</small><strong>" + (Number(data.hourly.visibility[i] || 0) / 1000).toFixed(1) + " km</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("history.renderhistory.gusts")) + "</small><strong>" + Math.round(data.hourly.wind_gusts_10m[i] || 0) + " km/h</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("visualization.take.pressure")) + "</small><strong>" + Math.round(data.hourly.surface_pressure[i] || 0) + " hPa</strong></span>\n        <span><small>" + escapeHTML(meteonexaText("app.openhourdetail.dew_point")) + "</small><strong>" + temperature(data.hourly.dew_point_2m[i]) + "</strong></span>\n      </div>\n    </div>";
    }).join('');
    const root = $('#hourly-table');
    root.innerHTML = "" + "<div class=\"hourly-table-row header\"><span>" + escapeHTML(meteonexaText("app.currentsharepayload.time")) + "</span><span>" + escapeHTML(meteonexaText("history.renderhistory.condition")) + "</span><span>" + escapeHTML(meteonexaText("app.renderdetailstable.temp")) + "</span><span>" + escapeHTML(meteonexaText("history.renderhistory.rain")) + "</span><span>" + escapeHTML(meteonexaText("visualization.take.wind")) + "</span><span aria-hidden=\"true\"></span></div>" + rows;
    $$('.hourly-row-toggle', root).forEach(button => button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!expanded));
        const details = document.getElementById(button.getAttribute('aria-controls'));
        if (details)
            details.hidden = expanded;
        button.closest('[data-hour-row]')?.classList.toggle('expanded', !expanded);
    }));
}
const {
    canvasSetup, hideChartTooltip, registerChartInteraction, drawChartAxisTitle, chartUnitAxisLabel,
    drawHomeChart, drawDetailChart, drawAllDetailCharts, initializeWeatherFX, resizeWeatherFX,
    updateWeatherAtmosphere, drawMotionChart, drawTrendCharts, initializeChartObservers,
    lonLatToWorld, worldToLonLat, radarTileUrl, analyzeRadarMotion, renderRadarMotion,
    syncRadarVectorLayer, removeRadarVectorLayer, selectRadarLocation, performRadarCitySearch, useGpsFromRadar, setRadarMode,
    renderRadarMap, drawForecastRadarLayer, ensureRadar, setRadarFrame, stopRadarAnimation,
    toggleRadarAnimation, stepRadar, zoomRadar
} = SERVICES.require('visualization').create({
    state, CONFIG, STORAGE, $, $$, clamp, appLocale, capitalize, meteonexaText, escapeHTML, temperature, convertTemp, unitLabel,
    formatClock, windDirection, currentHourlyIndex, t, currentResolvedCondition, isUiFeatureVisible, intelligenceLocationKey,
    localSeriesIndex, drawHistoryChart, saveJSON, debounce, normalizeLocation, withLoader, addRecent, updateSelectedLocationUI,
    fullLocationLabel, loadWeather, renderAll, searchCities, getCurrentLocationData, locationErrorMessage, persistLocalSettings,
    fetchJSON, sleep, formatLocationLocalTime, showToast, drawRadarBaseMap, loadRadarBaseData, loadRadarAdminData
});
async function loadRadarBaseData() {
    if (state.radar.baseData)
        return state.radar.baseData;
    if (state.radar.baseLoading)
        return state.radar.baseLoading;
    state.radar.baseLoading = fetch(CONFIG.BASEMAP_DATA, { cache: 'force-cache' })
        .then(response => response.ok ? response.json() : Promise.reject(new Error("" + meteonexaText("app.loadradarbasedata.base_map_unavailable"))))
        .then(data => { state.radar.baseData = data; renderRadarMap(); return data; })
        .catch(error => { console.warn("" + meteonexaText("app.loadradarbasedata.local_vector_base_map_unavailable"), error); return null; })
        .finally(() => { state.radar.baseLoading = null; });
    return state.radar.baseLoading;
}
function geoFeatureName(feature, fallback = '') {
    const properties = feature?.properties || {};
    return properties.reg_name || properties.prov_name || properties.name || properties.NAME_1 || fallback;
}
function walkGeoRings(geometry, callback) {
    if (!geometry)
        return;
    if (geometry.type === 'Polygon') {
        (geometry.coordinates || []).forEach(ring => callback(ring));
        return;
    }
    if (geometry.type === 'MultiPolygon') {
        (geometry.coordinates || []).forEach(polygon => (polygon || []).forEach(ring => callback(ring)));
        return;
    }
    if (geometry.type === 'GeometryCollection') {
        (geometry.geometries || []).forEach(item => walkGeoRings(item, callback));
    }
}
function compactGeoCollection(collection, stride = 4) {
    if (!collection?.features?.length)
        return null;
    const features = collection.features.map(feature => {
        const rings = [];
        let minLon = Infinity, minLat = Infinity, maxLon = -Infinity, maxLat = -Infinity;
        walkGeoRings(feature.geometry, ring => {
            if (!Array.isArray(ring) || ring.length < 3)
                return;
            const sampled = [];
            ring.forEach((pair, index) => {
                const lon = Number(pair?.[0]), lat = Number(pair?.[1]);
                if (!Number.isFinite(lon) || !Number.isFinite(lat))
                    return;
                minLon = Math.min(minLon, lon);
                maxLon = Math.max(maxLon, lon);
                minLat = Math.min(minLat, lat);
                maxLat = Math.max(maxLat, lat);
                if (index === 0 || index === ring.length - 1 || index % stride === 0)
                    sampled.push([lon, lat]);
            });
            if (sampled.length >= 3) {
                const first = sampled[0], last = sampled[sampled.length - 1];
                if (first[0] !== last[0] || first[1] !== last[1])
                    sampled.push([...first]);
                rings.push(sampled);
            }
        });
        const bbox = Array.isArray(feature.bbox) && feature.bbox.length >= 4
            ? feature.bbox.map(Number)
            : [minLon, minLat, maxLon, maxLat];
        const center = bbox.every(Number.isFinite)
            ? { lon: (bbox[0] + bbox[2]) / 2, lat: (bbox[1] + bbox[3]) / 2 }
            : null;
        return { name: geoFeatureName(feature), rings, center };
    }).filter(feature => feature.rings.length);
    return { features };
}
async function loadRadarAdminData() {
    if (state.radar.adminData.regions || state.radar.adminData.metros)
        return state.radar.adminData;
    if (state.radar.adminLoading)
        return state.radar.adminLoading;
    const sources = [CONFIG.ITALY_REGIONS_DATA, CONFIG.ITALY_METRO_DATA];
    state.radar.adminLoading = Promise.allSettled(sources.map(url => fetchJSON(url, { timeout: 22000 })))
        .then(results => {
        if (results[0].status === 'fulfilled')
            state.radar.adminData.regions = compactGeoCollection(results[0].value, 5);
        if (results[1].status === 'fulfilled')
            state.radar.adminData.metros = compactGeoCollection(results[1].value, 3);
        renderRadarMap();
        return state.radar.adminData;
    })
        .catch(error => {
        console.warn(meteonexaText("app.loadradaradmindata.italian_administrative_boundaries_unavailable"), error);
        return state.radar.adminData;
    })
        .finally(() => { state.radar.adminLoading = null; });
    return state.radar.adminLoading;
}
function drawRadarAdministrativeLayer(ctx, overlay, centerWorld, zoom, width, height, options = {}) {
    if (!overlay?.features?.length)
        return;
    const { stroke = 'rgba(79,224,209,.72)', fill = 'rgba(79,224,209,.025)', width: lineWidth = 1.2, dash = [], labelsAt = 6.2, labelColor = '#a5f5e9' } = options;
    ctx.save();
    ctx.strokeStyle = stroke;
    ctx.fillStyle = fill;
    ctx.lineWidth = lineWidth;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.setLineDash(dash);
    for (const feature of overlay.features) {
        let hasVisiblePath = false;
        ctx.beginPath();
        for (const ring of feature.rings) {
            let started = false;
            let previous = null;
            for (const coordinates of ring) {
                const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, zoom, width, height);
                const visible = point.x > -220 && point.x < width + 220 && point.y > -220 && point.y < height + 220;
                const jump = previous && Math.abs(point.x - previous.x) > width * .68;
                if (!visible || jump) {
                    started = false;
                    previous = point;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                    hasVisiblePath = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
                previous = point;
            }
            if (started)
                ctx.closePath();
        }
        if (hasVisiblePath) {
            if (fill !== 'transparent')
                ctx.fill('evenodd');
            ctx.stroke();
        }
    }
    ctx.setLineDash([]);
    if (zoom >= labelsAt) {
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = `800 ${zoom >= 7.5 ? 12 : 10}px Inter, system-ui, sans-serif`;
        for (const feature of overlay.features) {
            if (!feature.center || !feature.name)
                continue;
            const point = radarCanvasPoint(feature.center.lat, feature.center.lon, centerWorld, zoom, width, height);
            if (point.x < 34 || point.x > width - 34 || point.y < 34 || point.y > height - 34)
                continue;
            ctx.lineWidth = 4;
            ctx.strokeStyle = 'rgba(3,16,34,.88)';
            ctx.strokeText(feature.name, point.x, point.y);
            ctx.fillStyle = labelColor;
            ctx.fillText(feature.name, point.x, point.y);
        }
    }
    ctx.restore();
}
function radarCanvasPoint(lat, lon, centerWorld, zoom, width, height) {
    const pointWorld = lonLatToWorld(lat, lon, zoom);
    const worldSize = 256 * 2 ** zoom;
    let dx = pointWorld.x - centerWorld.x;
    if (dx > worldSize / 2)
        dx -= worldSize;
    if (dx < -worldSize / 2)
        dx += worldSize;
    return { x: width / 2 + dx, y: height / 2 + pointWorld.y - centerWorld.y };
}
function drawRadarBaseMap() {
    const canvas = $('#radar-base-canvas');
    const container = $('#radar-map');
    if (!canvas || !container)
        return;
    const rect = container.getBoundingClientRect();
    const width = Math.max(1, Math.round(rect.width)), height = Math.max(1, Math.round(rect.height));
    const dpr = Math.min(2, devicePixelRatio || 1);
    if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const useVectorMap = Boolean(state.radar.vectorMapReady);
    const center = state.radar.center || { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
    const centerWorld = lonLatToWorld(center.lat, center.lon, state.radar.zoom);
    if (!useVectorMap) {
        const bg = ctx.createLinearGradient(0, 0, width, height);
        bg.addColorStop(0, '#071b36');
        bg.addColorStop(.52, '#0a2747');
        bg.addColorStop(1, '#041327');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, width, height);
        const glow = ctx.createRadialGradient(width * .52, height * .48, 0, width * .52, height * .48, Math.max(width, height) * .64);
        glow.addColorStop(0, 'rgba(17,135,255,.18)');
        glow.addColorStop(.55, 'rgba(26,91,164,.07)');
        glow.addColorStop(1, 'rgba(0,0,0,.18)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, width, height);
        ctx.strokeStyle = 'rgba(76,178,255,.09)';
        ctx.lineWidth = 1;
        for (let lat = -80; lat <= 80; lat += 10) {
            ctx.beginPath();
            let started = false;
            for (let lon = -180; lon <= 180; lon += 3) {
                const point = radarCanvasPoint(lat, lon, centerWorld, state.radar.zoom, width, height);
                if (point.x < -80 || point.x > width + 80 || point.y < -80 || point.y > height + 80) {
                    started = false;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
            }
            ctx.stroke();
        }
        for (let lon = -180; lon < 180; lon += 10) {
            ctx.beginPath();
            let started = false;
            for (let lat = -82; lat <= 82; lat += 2) {
                const point = radarCanvasPoint(lat, lon, centerWorld, state.radar.zoom, width, height);
                if (point.x < -80 || point.x > width + 80 || point.y < -80 || point.y > height + 80) {
                    started = false;
                    continue;
                }
                if (!started) {
                    ctx.moveTo(point.x, point.y);
                    started = true;
                }
                else
                    ctx.lineTo(point.x, point.y);
            }
            ctx.stroke();
        }
        const drawSegments = (segments, stroke, lineWidth) => {
            if (!segments)
                return;
            ctx.strokeStyle = stroke;
            ctx.lineWidth = lineWidth;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            for (const segment of segments) {
                ctx.beginPath();
                let started = false, previous = null;
                for (const coordinates of segment) {
                    const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, state.radar.zoom, width, height);
                    const visible = point.x > -160 && point.x < width + 160 && point.y > -160 && point.y < height + 160;
                    const jump = previous && Math.abs(point.x - previous.x) > width * .65;
                    if (!visible || jump) {
                        started = false;
                        previous = point;
                        continue;
                    }
                    if (!started) {
                        ctx.moveTo(point.x, point.y);
                        started = true;
                    }
                    else
                        ctx.lineTo(point.x, point.y);
                    previous = point;
                }
                ctx.stroke();
            }
        };
        drawSegments(state.radar.baseData?.coastlines, 'rgba(95,210,255,.48)', 1.35);
        drawSegments(state.radar.baseData?.countries, 'rgba(166,215,255,.20)', .75);
        drawRadarAdministrativeLayer(ctx, state.radar.adminData.regions, centerWorld, state.radar.zoom, width, height, {
            stroke: 'rgba(77,232,207,.68)', fill: 'rgba(44,205,181,.018)', width: 1.25, labelsAt: 6.1, labelColor: '#a3f3e5'
        });
        drawRadarAdministrativeLayer(ctx, state.radar.adminData.metros, centerWorld, state.radar.zoom, width, height, {
            stroke: 'rgba(174,128,255,.82)', fill: 'rgba(151,101,255,.025)', width: 1.4, dash: [5, 4], labelsAt: 7.0, labelColor: '#dcc8ff'
        });
    }
    else {
        const shade = ctx.createLinearGradient(0, 0, 0, height);
        shade.addColorStop(0, 'rgba(2,14,29,.08)');
        shade.addColorStop(.6, 'rgba(2,17,34,.13)');
        shade.addColorStop(1, 'rgba(1,10,23,.24)');
        ctx.fillStyle = shade;
        ctx.fillRect(0, 0, width, height);
        const drawFallbackSegments = (segments, stroke, lineWidth) => {
            if (!segments)
                return;
            ctx.strokeStyle = stroke;
            ctx.lineWidth = lineWidth;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            for (const segment of segments) {
                ctx.beginPath();
                let started = false, previous = null;
                for (const coordinates of segment) {
                    const point = radarCanvasPoint(coordinates[1], coordinates[0], centerWorld, state.radar.zoom, width, height);
                    const visible = point.x > -140 && point.x < width + 140 && point.y > -140 && point.y < height + 140;
                    const jump = previous && Math.abs(point.x - previous.x) > width * .68;
                    if (!visible || jump) {
                        started = false;
                        previous = point;
                        continue;
                    }
                    if (!started) {
                        ctx.moveTo(point.x, point.y);
                        started = true;
                    }
                    else
                        ctx.lineTo(point.x, point.y);
                    previous = point;
                }
                ctx.stroke();
            }
        };
        drawFallbackSegments(state.radar.baseData?.coastlines, 'rgba(101,218,255,.24)', .9);
        drawFallbackSegments(state.radar.baseData?.countries, 'rgba(181,226,255,.13)', .65);
    }
    const cx = width / 2, cy = height / 2;
    ctx.strokeStyle = 'rgba(45,169,255,.24)';
    ctx.lineWidth = 1.2;
    [55, 110, 175].forEach(radius => { ctx.beginPath(); ctx.arc(cx, cy, radius, 0, Math.PI * 2); ctx.stroke(); });
    const angle = (performance.now() / 2600) % (Math.PI * 2);
    const sweep = ctx.createRadialGradient(cx, cy, 10, cx, cy, 210);
    sweep.addColorStop(0, 'rgba(61,211,255,.15)');
    sweep.addColorStop(1, 'rgba(61,211,255,0)');
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(angle);
    ctx.fillStyle = sweep;
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.arc(0, 0, 210, -.18, .18);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
    ctx.fillStyle = 'rgba(202,232,255,.72)';
    ctx.font = '600 12px Inter, system-ui, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(`${center.lat.toFixed(2)}° · ${center.lon.toFixed(2)}°`, 18, height - 18);
    const cityLabel = shortLocationLabel(state.location);
    if (cityLabel) {
        ctx.textAlign = 'center';
        ctx.font = '800 12px Inter, system-ui, sans-serif';
        ctx.lineWidth = 4;
        ctx.strokeStyle = 'rgba(2,14,31,.9)';
        ctx.strokeText(cityLabel, cx, cy + 34);
        ctx.fillStyle = '#e9f8ff';
        ctx.fillText(cityLabel, cx, cy + 34);
    }
}
const {
    shareCurrentWeather, runSystemShare, copyCurrentShare, openShareChannel, updateThreshold,
    refreshBugReportContext, syncBugCategoryOther, clearBugReportMedia, addBugReportFiles,
    startBugVideoRecording, stopBugVideoRecording, submitBugReport, renderBugReportMedia, removeBugReportMedia
} = SERVICES.require('appUtilities').create({
    state, $, APP_BUILD, STORAGE, currentHourlyIndex, escapeHTML, isGuestSession,
    locationTimeZoneSummary, renderAlerts, saveJSON, shortLocationLabel, t, temperature, validEmail,
    weatherMeta, withLoader, showToast, meteonexaText
});

function privacyReturnHash() {
    const page = String(state.currentPage || 'home');
    const allowed = new Set(['home','radar','favorites','details','history','intelligence','advanced','bug-report','route','devices']);
    return `#${allowed.has(page) ? page : 'home'}`;
}
function privacyPageHref() {
    const url = new URL('privacy.html', location.href);
    url.searchParams.set('return', privacyReturnHash());
    return `${url.pathname.split('/').pop()}${url.search}`;
}
function syncPrivacyContextCopy() {
    const guest = isGuestSession();
    const note = $('.privacy-ai-note');
    const title = $('.privacy-fact-ai strong');
    const copy = $('.privacy-fact-ai small');
    if (note) note.setAttribute('data-i18n-key', guest ? 'privacy.ai.short_notice.guest' : 'privacy.ai.short_notice');
    if (title) title.setAttribute('data-i18n-key', guest ? 'privacy.ai.fact.guest.title' : 'privacy.ai.fact.title');
    if (copy) copy.setAttribute('data-i18n-key', guest ? 'privacy.ai.fact.guest.copy' : 'privacy.ai.fact.copy');
    $$('[data-privacy-link]').forEach(link => {
        link.setAttribute('href', privacyPageHref());
        link.removeAttribute('target');
        link.removeAttribute('rel');
    });
    translateDOM(document);
}
const PRIVACY_NOTICE_VERSION = '20.1';
function acknowledgePrivacyNotice() {
    state.privacyNotice = SERVICES.get('privacy')?.acknowledge?.(Date.now()) || { version: PRIVACY_NOTICE_VERSION, acknowledgedAt: Date.now() };
    saveJSON(STORAGE.privacyNotice, state.privacyNotice);
    const notice = $('#privacy-notice');
    if (notice)
        notice.hidden = true;
}
function openPrivacyCenter() {
    syncPrivacyContextCopy();
    const dialog = $('#privacy-dialog');
    if (dialog && !dialog.open)
        dialog.showModal();
}
function initializePrivacyNotice() {
    syncPrivacyContextCopy();
    const notice = $('#privacy-notice');
    if (!notice)
        return;
    notice.hidden = state.privacyNotice?.version === PRIVACY_NOTICE_VERSION;
}
function getToastHost() {
    const openDialogs = $$('dialog.app-dialog[open], dialog.assistant-dialog[open]');
    const activeDialog = openDialogs.at(-1);
    if (activeDialog) {
        let region = $('.dialog-toast-region', activeDialog);
        if (!region) {
            region = document.createElement('div');
            region.className = 'toast-region dialog-toast-region';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            activeDialog.appendChild(region);
        }
        return region;
    }
    return $('#toast-region');
}
function showToast(title, message = '', type = 'info', timeout = 3600) {
    const root = getToastHost();
    if (!root)
        return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icon = type === 'success' ? '✓' : type === 'error' ? '!' : type === 'warning' ? '!' : 'i';
    toast.innerHTML = `<span class="toast-icon">${icon}</span><span><strong>${escapeHTML(t(title))}</strong><small>${escapeHTML(t(message))}</small></span><button class="toast-close" type="button" aria-label="${escapeHTML(t("app.showtoast.close"))}"><svg><use href="#i-close"/></svg></button>`;
    root.appendChild(toast);
    const remove = () => {
        if (!toast.isConnected)
            return;
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 250);
    };
    $('.toast-close', toast).addEventListener('click', remove);
    setTimeout(remove, timeout);
}
SERVICES.publish('toast', showToast);
function weatherNeedsForegroundRefresh(maxAgeMs = 5 * 60 * 1000) {
    if (!state.weather?.fetchedAt)
        return true;
    return Date.now() - Number(state.weather.fetchedAt) >= maxAgeMs;
}
function refreshWeatherOnForeground() {
    if (!state.bootComplete || $('#weather-app')?.hidden !== false || !weatherNeedsForegroundRefresh())
        return Promise.resolve(state.weather);
    // Coalesce focus + visibilitychange + pageshow into one request. loadWeather
    // already deduplicates in-flight calls; this guard also avoids a no-op rerender.
    const now = Date.now();
    if (now - Number(state.lastForegroundRefreshAt || 0) < 1500)
        return state.weatherRequest || Promise.resolve(state.weather);
    state.lastForegroundRefreshAt = now;
    return loadWeather({ force: false, silent: true });
}
async function requestSafeBackgroundSync() {
    if (!('serviceWorker' in navigator))
        return false;
    try {
        const registration = await navigator.serviceWorker.ready;
        if (registration?.sync?.register) {
            await registration.sync.register('meteonexa-safe-sync');
            return true;
        }
    }
    catch (error) {
        console.debug('SAFE_BACKGROUND_SYNC_UNAVAILABLE', error?.message || error);
    }
    return false;
}
function updateNetworkStatus() {
    const banner = $('#network-banner');
    const online = navigator.onLine;
    banner.textContent = online ? meteonexaText('offline.back_online') : meteonexaText('offline.cached_mode');
    banner.className = `network-banner show ${online ? '' : 'offline'}`;
    setTimeout(() => banner.classList.remove('show'), 3600);
    if (state.weather)
        renderHeader();
    if (!online) {
        showToast(t('offline.mode.title'), t('offline.mode.copy'), 'warning', 5200);
        return;
    }
    if (!$('#weather-app').hidden) {
        Promise.allSettled([
            loadWeather({ force: true, silent: true }),
            synchronizeRemotePreferences({ force: true }),
            synchronizePersonalWeatherPreferences({ force: true }),
            state.currentPage === 'intelligence' ? loadIntelligence({ force: true, silent: true }) : Promise.resolve(),
            state.currentPage === 'radar' ? ensureRadar(true, { silent: true }) : Promise.resolve()
        ]).then(results => {
            const failed = results.some(result => result.status === 'rejected');
            showToast(t(failed ? 'offline.sync.partial.title' : 'offline.sync.done.title'),
                t(failed ? 'offline.sync.partial.copy' : 'offline.sync.done.copy'),
                failed ? 'warning' : 'success', 4400);
        }).catch(() => {});
    }
}
function updateLiveClocks() {
    const deviceTime = $('#device-time');
    if (deviceTime)
        deviceTime.textContent = nowTime();
    refreshTimeZoneLabels();
}
function startLiveClocks() {
    if (window.__METEONEXA_BOOT_CLOCK__) {
        clearInterval(window.__METEONEXA_BOOT_CLOCK__);
        window.__METEONEXA_BOOT_CLOCK__ = null;
    }
    clearTimeout(state.clockTimer);
    const tick = () => {
        updateLiveClocks();
        const delay = Math.max(250, 1000 - (Date.now() % 1000) + 20);
        state.clockTimer = setTimeout(tick, delay);
    };
    tick();
}
function scheduleRefresh() {
    clearTimeout(state.refreshTimer);
    const minutes = Math.max(1, Number(state.settings.refresh || CONFIG.REFRESH_MINUTES));
    const interval = minutes * 60 * 1000;
    const tick = async () => {
        if (!document.hidden && !$('#weather-app').hidden) {
            await loadWeather({ force: true, silent: true });
            if (state.currentPage === "radar" && navigator.onLine)
                ensureRadar(true, { silent: true });
        }
        state.refreshTimer = setTimeout(tick, interval);
    };
    // During bootstrap the app performs its own single fresh hydration. Do not
    // schedule an aligned tick a second later just because startup happens close
    // to a wall-clock interval boundary.
    const alignedDelay = state.bootComplete
        ? Math.max(1000, interval - (Date.now() % interval) + 120)
        : interval;
    state.refreshTimer = setTimeout(tick, alignedDelay);
}
function startRealtimeSynchronization() {
    clearInterval(state.preferenceSyncTimer);
    if ('BroadcastChannel' in window && !state.syncChannel) {
        try {
            state.syncChannel = new BroadcastChannel('meteonexa-realtime-v1');
            state.syncChannel.addEventListener('message', event => {
                if (event.data?.type === 'preferences-updated')
                    synchronizeRemotePreferences({ force: true });
            });
        }
        catch {
            state.syncChannel = null;
        }
    }
    state.preferenceSyncTimer = setInterval(() => synchronizeRemotePreferences(), 10000);
    scheduleSevereWeatherMonitor();
}
function synchronizeLocalState(event) {
    if (!event.key)
        return;
    if (event.key === STORAGE.session) {
        reconcileRootView({ refresh: true });
        return;
    }
    if (event.key === STORAGE.location) {
        state.location = loadJSON(STORAGE.location, state.location);
        if (!$('#weather-app').hidden)
            loadWeather({ force: true, silent: true });
        else if (hasUsableLocation())
            updateSelectedLocationUI();
        return;
    }
    if (event.key === STORAGE.weather) {
        state.weather = loadJSON(STORAGE.weather, state.weather);
        if (state.weather) {
            applyWeatherTimeZoneMetadata(state.weather);
            renderAll();
        }
        return;
    }
    if (event.key === STORAGE.air) {
        state.air = loadJSON(STORAGE.air, state.air);
        if (state.weather)
            renderAll();
        return;
    }
    if (event.key === STORAGE.favorites || event.key === STORAGE.recent) {
        state.favorites = loadJSON(STORAGE.favorites, state.favorites);
        state.recent = loadJSON(STORAGE.recent, state.recent);
        syncFavoriteUI();
        if (state.currentPage === 'favorites')
            renderFavorites();
        return;
    }
    if (event.key === STORAGE.thresholds) {
        state.thresholds = { ...DEFAULT_THRESHOLDS, ...loadJSON(STORAGE.thresholds, {}) };
        initializeThresholds();
        if (state.weather)
            renderAlerts();
        return;
    }
    if (event.key === STORAGE.notifications) {
        state.notifications = { ...DEFAULT_NOTIFICATIONS, ...loadJSON(STORAGE.notifications, {}) };
        syncNotificationButton();
        return;
    }
    if (event.key === STORAGE.settings) {
        const remoteLocalSettings = loadJSON(STORAGE.settings, {});
        delete remoteLocalSettings.language;
        delete remoteLocalSettings.theme;
        state.settings = { ...state.settings, ...remoteLocalSettings };
        applySettings();
    }
}
function updateProfileUI() {
    const profile = state.session || { type: 'guest', name: "" + meteonexaText("app.updateprofileui.guest") };
    const name = profile.name || "" + meteonexaText("app.updateprofileui.guest");
    const initials = name === "" + meteonexaText("app.updateprofileui.guest") ? "" + meteonexaText("app.updateprofileui.ao") : name.split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map(item => item[0].toUpperCase()).join('');
    $$('#profile-button span, .profile-avatar').forEach(node => { node.textContent = initials || "" + meteonexaText("app.updateprofileui.ao"); });
    $('#profile-name').textContent = meteonexaText('profile.dialog.title');
    const emailNode = $('#profile-email');
    if (emailNode) {
        emailNode.textContent = profile.type === 'email' && validEmail(profile.email || '')
            ? String(profile.email).trim().toLowerCase()
            : (profile.type === 'email' ? meteonexaText('app.updateprofileui.local_email_access') : meteonexaText('app.updateprofileui.access_without_registration'));
    }
    $('#profile-method').textContent = profile.type === 'email' ? "" + meteonexaText("app.updateprofileui.local_email_access") : profile.type === 'sms' ? "" + meteonexaText("app.updateprofileui.local_sms_access") : "" + meteonexaText("app.updateprofileui.access_without_registration");
    updateProfileNotificationBadge();
    syncDevicesSettingsButton();
}

const { syncDevicesSettingsButton, formatAccessDateTime, deviceAccessLabel, renderDeviceAccessList, approximateDeviceLocationHeader, restoreDevicesDialogShell, loadDeviceAccessHistory, openDeviceAccessDialog, revokeDeviceAccess, deletePastDeviceAccess, revokeOtherDeviceAccesses, reconcileRemoteDeviceRevocation } = SERVICES.require('deviceSessions').create({
    state, $, $$, STORAGE, SESSION_FLAGS, meteonexaText, appLocale,
    apiRequest: (...args) => apiRequest(...args), confirmAction: (...args) => confirmAction(...args),
    withLoader: (...args) => withLoader(...args), showToast: (...args) => showToast(...args),
    showGuestAccessNotice: (...args) => showGuestAccessNotice(...args),
    nextPaint: (...args) => nextPaint(...args), applyGuestAccessUI: (...args) => applyGuestAccessUI(...args),
    updateProfileUI: (...args) => updateProfileUI(...args), showWelcome: (...args) => showWelcome(...args),
    reconcileEmailServerSession: (...args) => reconcileEmailServerSession(...args)
});

function closeOpenAppDialogs(exceptId = '') {
    $$('dialog.app-dialog[open]').forEach(dialog => {
        if (dialog.id !== exceptId) {
            try {
                dialog.close('cancel');
            }
            catch {
                dialog.removeAttribute('open');
            }
        }
    });
}
function nextPaint() {
    return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
}
function settleConfirmDialog(confirmed, { closeDialog = true } = {}) {
    const dialog = $('#confirm-dialog');
    const request = pendingConfirmRequest;
    pendingConfirmRequest = null;
    if (dialog) {
        dialog.returnValue = confirmed ? 'confirm' : 'cancel';
        if (closeDialog && dialog.open) {
            try {
                dialog.close(dialog.returnValue);
            }
            catch {
                dialog.removeAttribute('open');
            }
        }
    }
    if (request)
        request.resolve(Boolean(confirmed));
}
async function confirmAction(title, copy, { confirmLabel = "" + meteonexaText("app.confirmaction.confirm"), icon = '#i-alert', kind = 'default' } = {}) {
    const dialog = $('#confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function')
        return window.confirm(`${t(title)}

${t(copy)}`);
    if (pendingConfirmRequest)
        settleConfirmDialog(false);
    $('#confirm-title').textContent = t(title);
    $('#confirm-copy').textContent = t(copy);
    $('#confirm-ok').textContent = t(confirmLabel);
    const iconUse = $('#confirm-icon-use');
    if (iconUse)
        iconUse.setAttribute('href', icon);
    dialog.dataset.confirmKind = kind;
    closeOpenAppDialogs(dialog.id);
    await nextPaint();
    dialog.returnValue = '';
    return new Promise(resolve => {
        pendingConfirmRequest = { resolve };
        try {
            if (!dialog.open)
                dialog.showModal();
            requestAnimationFrame(() => $('#confirm-cancel')?.focus({ preventScroll: true }));
        }
        catch (error) {
            pendingConfirmRequest = null;
            console.warn(meteonexaText("app.confirmaction.confirmation_dialog_unavailable_using_browser_fallback"), error);
            resolve(window.confirm(`${t(title)}

${t(copy)}`));
        }
    });
}
SERVICES.publish('confirm', confirmAction);
document.addEventListener('click', event => {
    const choice = event.target.closest?.('[data-confirm-result]');
    if (!choice)
        return;
    event.preventDefault();
    event.stopPropagation();
    settleConfirmDialog(choice.dataset.confirmResult === 'confirm');
}, { capture: true, passive: false });
async function logout({ returnToProfile = false } = {}) {
    if (state.logoutInProgress)
        return;
    state.logoutInProgress = true;
    try {
        closeOpenAppDialogs();
        await nextPaint();
        const confirmed = await confirmAction("" + meteonexaText("app.logout.sign_out_meteonexa"), "" + meteonexaText("app.logout.local_session_will_closed_favorites_settings_will_remain"), { confirmLabel: "" + meteonexaText("app.logout.sign_out"), icon: '#i-logout', kind: 'logout' });
        if (!confirmed) {
            if (returnToProfile) {
                await nextPaint();
                const profileDialog = $('#profile-dialog');
                try {
                    if (profileDialog && !profileDialog.open)
                        profileDialog.showModal();
                }
                catch { }
            }
            return;
        }
        let logoutTarget = location.pathname || '/';
        await withLoader("" + meteonexaText("app.logout.signing_out"), "" + meteonexaText("app.logout.see_soon_returning_sign_screen"), async () => {
            if (state.session?.type === 'email') {
                try { await apiRequest('api/auth/logout.php', { logout: true }); }
                catch { /* local logout still proceeds when the network is unavailable */ }
            }
            try {
                localStorage.removeItem(STORAGE.session);
            }
            catch { }
            try {
                sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
            }
            catch { }
            state.session = null;
            state.authServerVerified = false;
            closeOpenAppDialogs();
            setMobileSidebarOpen(false);
            const cleanUrl = new URL(location.href);
            cleanUrl.searchParams.delete('preview');
            cleanUrl.searchParams.delete('app');
            cleanUrl.hash = '';
            logoutTarget = `${cleanUrl.pathname}${cleanUrl.search}` || '/';
            // Do not render the login locally and then navigate to it: that was
            // perceived as a double refresh. Keep the loader visible and perform
            // one clean navigation after the server/local logout boundary.
            await sleep(80);
        }, 420);
        try {
            location.replace(logoutTarget);
        }
        catch {
            location.href = logoutTarget;
        }
    }
    finally {
        state.logoutInProgress = false;
    }
}
const PRESERVED_LOCAL_KEYS_ON_CACHE_RESET = Object.freeze([STORAGE.settings]);
function cacheResetSafeUiSettings() {
    const language = ['it', 'en', 'fr', 'es', 'de'].includes(String(state.settings?.language || '')) ? String(state.settings.language) : DEFAULT_SETTINGS.language;
    const theme = ['system', 'light', 'dark'].includes(String(state.settings?.theme || '')) ? String(state.settings.theme) : DEFAULT_SETTINGS.theme;
    return { language, theme };
}
let cacheResetInProgress = false;
function settleBrowserOperation(promise, timeoutMs = 900, fallback = null) {
    return new Promise(resolve => {
        let settled = false;
        const finish = value => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve(value);
        };
        const timer = setTimeout(() => finish(fallback), Math.max(100, Number(timeoutMs) || 900));
        Promise.resolve(promise).then(finish, () => finish(fallback));
    });
}
async function deleteMeteoNexaIndexedDb() {
    if (!('indexedDB' in window)) return;
    try {
        const databases = typeof indexedDB.databases === 'function'
            ? await settleBrowserOperation(indexedDB.databases(), 700, [])
            : [];
        await Promise.all((databases || []).filter(entry => String(entry?.name || '').toLowerCase().startsWith('meteonexa')).map(entry => settleBrowserOperation(new Promise(resolve => {
            try {
                const request = indexedDB.deleteDatabase(entry.name);
                request.onsuccess = request.onerror = request.onblocked = () => resolve(true);
            } catch { resolve(false); }
        }), 700, false)));
    } catch (error) {
        console.warn('INDEXED_DB_CLEAR_FAILED', error);
    }
}
async function unregisterMeteoNexaServiceWorkers() {
    if (!navigator.serviceWorker?.getRegistrations) return;
    try {
        const registrations = await settleBrowserOperation(navigator.serviceWorker.getRegistrations(), 800, []);
        const appPath = new URL('./', location.href).pathname.replace(/[^/]+$/, '');
        await Promise.all((registrations || []).filter(registration => {
            try { return new URL(registration.scope).pathname.startsWith(appPath); } catch { return false; }
        }).map(registration => settleBrowserOperation(registration.unregister(), 700, false)));
    } catch (error) {
        console.warn('SERVICE_WORKER_UNREGISTER_FAILED', error);
    }
}
function clearMeteoNexaLocalRuntimeState({ preservePreferences = true } = {}) {
    try {
        // Privacy reset: location, favourites, thresholds, cached weather, consent
        // markers and every authentication/device key are removed. Only language
        // and theme may survive because they are presentation-only preferences.
        const safeUiSettings = preservePreferences ? cacheResetSafeUiSettings() : null;
        for (let index = localStorage.length - 1; index >= 0; index -= 1) {
            const key = localStorage.key(index);
            if (!key) continue;
            if (key.startsWith('meteonexa_') || key.startsWith('meteonexa.') || key.startsWith('meteonexa-')) localStorage.removeItem(key);
        }
        if (safeUiSettings) localStorage.setItem(STORAGE.settings, JSON.stringify(safeUiSettings));
    } catch (error) {
        console.warn('LOCAL_STORAGE_CLEAR_FAILED', error);
    }
    try {
        for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
            const key = sessionStorage.key(index);
            if (key && (key.startsWith('meteonexa_') || key.startsWith('meteonexa.') || key.startsWith('meteonexa-'))) sessionStorage.removeItem(key);
        }
        sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
        sessionStorage.setItem(SESSION_FLAGS.cacheReset, '1');
    } catch { }
}
async function clearMeteoNexaCacheStorage() {
    if (!('caches' in window)) return;
    try {
        const names = await settleBrowserOperation(caches.keys(), 800, []);
        const targets = (names || []).filter(name => name.startsWith('meteonexa-') || name === CACHE_SENTINEL.cacheName);
        await Promise.all(targets.map(name => settleBrowserOperation(caches.delete(name), 700, false)));
    } catch (error) {
        console.warn('CACHE_STORAGE_CLEAR_FAILED', error);
    }
}
function notifyMeteoNexaServiceWorkerReset() {
    try {
        const worker = navigator.serviceWorker?.controller;
        worker?.postMessage({ type: 'METEONEXA_CLEAR_BACKGROUND' });
        worker?.postMessage({ type: 'CLEAR_APP_CACHES' });
    } catch { }
}
async function clearBrowserApplicationState({ localAlreadyCleared = false } = {}) {
    // Safari/WebKit can leave the Service Worker readiness promise pending indefinitely
    // when a registration is missing, stopped or transitioning. Cache reset must
    // never wait on ready: local auth is cleared synchronously and every browser
    // cleanup operation below has a bounded best-effort deadline.
    if (!localAlreadyCleared) clearMeteoNexaLocalRuntimeState({ preservePreferences: true });
    notifyMeteoNexaServiceWorkerReset();
    await Promise.allSettled([
        clearMeteoNexaCacheStorage(),
        deleteMeteoNexaIndexedDb(),
        unregisterMeteoNexaServiceWorkers()
    ]);
}
async function requestGuestCacheReceiptProof() {
    const language = String(state.settings?.language || 'it');
    const startChallenge = async email => apiRequest('api/privacy/cache-reset-challenge.php', {
        action: 'start', email, language
    }, { timeout: 7000, notifyAuthRequired: false });
    const verifyChallenge = async (challengeToken, code) => apiRequest('api/privacy/cache-reset-challenge.php', {
        action: 'verify', challengeToken, code, language
    }, { timeout: 5000, notifyAuthRequired: false });

    const dialog = $('#cache-privacy-dialog');
    const form = $('#cache-privacy-form');
    const emailInput = $('#cache-receipt-email');
    const codeInput = $('#cache-receipt-code');
    const emailStep = $('#cache-privacy-email-step');
    const codeStep = $('#cache-privacy-code-step');
    const verificationCopy = $('#cache-privacy-verification-copy');
    const confirmLabel = $('#cache-privacy-confirm-label');

    // Very old browsers without <dialog> still require mailbox ownership.
    // They never fall back to an unverified receipt address.
    if (!dialog || !form || !emailInput || !codeInput || typeof dialog.showModal !== 'function') {
        const fallback = window.prompt(meteonexaText('settings.cache.receipt.guest.email_label'), '') || '';
        const email = String(fallback).trim().toLowerCase();
        if (!validEmail(email)) {
            showToast(meteonexaText('settings.cache.receipt.invalid.title'), meteonexaText('settings.cache.receipt.invalid_email'), 'warning', 4200);
            return null;
        }
        let challenge;
        try {
            challenge = await withLoader(meteonexaText('settings.cache.verify.sending.title'), meteonexaText('settings.cache.verify.sending.copy'), () => startChallenge(email), 240);
        } catch (error) {
            showToast(meteonexaText('settings.cache.verify.error.title'), error?.message || meteonexaText('settings.cache.verify.send_failed'), 'warning', 5200);
            return null;
        }
        const code = String(window.prompt(meteonexaText('settings.cache.verify.code_prompt', { email: String(challenge.maskedEmail || '') }), '') || '').replace(/\D+/g, '').slice(0, 6);
        if (!/^\d{6}$/.test(code)) return null;
        try {
            const verified = await withLoader(meteonexaText('settings.cache.verify.checking.title'), meteonexaText('settings.cache.verify.checking.copy'), () => verifyChallenge(String(challenge.challengeToken || ''), code), 220);
            return verified?.verified === true ? { guestProof: String(verified.guestProof || ''), maskedEmail: String(verified.maskedEmail || '') } : null;
        } catch (error) {
            showToast(meteonexaText('settings.cache.verify.error.title'), error?.message || meteonexaText('settings.cache.verify.invalid_code'), 'warning', 5200);
            return null;
        }
    }

    closeOpenAppDialogs(dialog.id);
    clearFieldError('cache-receipt-email');
    clearFieldError('cache-receipt-code');
    emailInput.value = '';
    emailInput.disabled = false;
    codeInput.value = '';
    if (emailStep) emailStep.hidden = false;
    if (codeStep) codeStep.hidden = true;
    if (confirmLabel) {
        confirmLabel.dataset.i18nKey = 'settings.cache.verify.send_code';
        confirmLabel.textContent = meteonexaText('settings.cache.verify.send_code');
    }
    await nextPaint();

    return new Promise(resolve => {
        let settled = false;
        let challengeToken = '';
        let maskedEmail = '';
        let busy = false;
        const cleanup = () => {
            form.removeEventListener('submit', onSubmit);
            dialog.removeEventListener('cancel', onCancel);
            dialog.removeEventListener('close', onClose);
            emailInput.removeEventListener('input', onEmailInput);
            codeInput.removeEventListener('input', onCodeInput);
        };
        const finish = value => {
            if (settled) return;
            settled = true;
            cleanup();
            if (dialog.open) dialog.close(value ? 'confirm' : 'cancel');
            resolve(value);
        };
        const onSubmit = async event => {
            event.preventDefault();
            if (busy) return;
            if (!challengeToken) {
                const email = String(emailInput.value || '').trim().toLowerCase();
                if (!validEmail(email)) {
                    setFieldError('cache-receipt-email', 'settings.cache.receipt.invalid_email');
                    return;
                }
                busy = true;
                try {
                    const challenge = await withLoader(
                        meteonexaText('settings.cache.verify.sending.title'),
                        meteonexaText('settings.cache.verify.sending.copy'),
                        () => startChallenge(email),
                        240
                    );
                    challengeToken = String(challenge.challengeToken || '');
                    maskedEmail = String(challenge.maskedEmail || '');
                    if (!challengeToken) throw new Error(meteonexaText('settings.cache.verify.send_failed'));
                    emailInput.disabled = true;
                    if (emailStep) emailStep.hidden = true;
                    if (codeStep) codeStep.hidden = false;
                    if (verificationCopy) {
                        verificationCopy.dataset.i18nDynamic = 'true';
                        verificationCopy.textContent = meteonexaText('settings.cache.verify.sent', { email: maskedEmail });
                    }
                    if (confirmLabel) {
                        confirmLabel.dataset.i18nKey = 'settings.cache.verify.verify_submit';
                        confirmLabel.textContent = meteonexaText('settings.cache.verify.verify_submit');
                    }
                    await nextPaint();
                    codeInput.focus({ preventScroll: true });
                } catch (error) {
                    setFieldError('cache-receipt-email', error?.message || 'settings.cache.verify.send_failed');
                } finally {
                    busy = false;
                }
                return;
            }

            const code = String(codeInput.value || '').replace(/\D+/g, '').slice(0, 6);
            if (!/^\d{6}$/.test(code)) {
                setFieldError('cache-receipt-code', 'settings.cache.verify.invalid_code');
                return;
            }
            busy = true;
            try {
                const verified = await withLoader(
                    meteonexaText('settings.cache.verify.checking.title'),
                    meteonexaText('settings.cache.verify.checking.copy'),
                    () => verifyChallenge(challengeToken, code),
                    220
                );
                const proof = String(verified.guestProof || '');
                if (verified.verified !== true || !proof) throw new Error(meteonexaText('settings.cache.verify.invalid_code'));
                finish({ guestProof: proof, maskedEmail: String(verified.maskedEmail || maskedEmail) });
            } catch (error) {
                setFieldError('cache-receipt-code', error?.message || 'settings.cache.verify.invalid_code');
            } finally {
                busy = false;
            }
        };
        const onCancel = event => { event.preventDefault(); if (!busy) finish(null); };
        const onClose = () => finish(null);
        const onEmailInput = () => clearFieldError('cache-receipt-email');
        const onCodeInput = () => {
            codeInput.value = String(codeInput.value || '').replace(/\D+/g, '').slice(0, 6);
            clearFieldError('cache-receipt-code');
        };
        form.addEventListener('submit', onSubmit);
        dialog.addEventListener('cancel', onCancel);
        dialog.addEventListener('close', onClose);
        emailInput.addEventListener('input', onEmailInput);
        codeInput.addEventListener('input', onCodeInput);
        try {
            dialog.showModal();
            requestAnimationFrame(() => emailInput.focus({ preventScroll: true }));
        } catch {
            finish(null);
        }
    });
}
async function clearApplicationCache() {
    if (cacheResetInProgress) return;
    const wasEmailSession = state.session?.type === 'email';
    let guestReceiptProof = null;
    if (wasEmailSession) {
        const confirmed = await confirmAction(
            meteonexaText('settings.cache.confirm.title'),
            meteonexaText('settings.cache.receipt.auth.confirm.copy'),
            { confirmLabel: meteonexaText('settings.cache.action'), icon: '#i-refresh', kind: 'remove' }
        );
        if (!confirmed) return;
    } else {
        guestReceiptProof = await requestGuestCacheReceiptProof();
        if (!guestReceiptProof?.guestProof) return;
    }

    cacheResetInProgress = true;
    const receiptLanguage = String(state.settings?.language || 'it');
    const cleanUrl = new URL(location.href);
    cleanUrl.searchParams.delete('preview');
    cleanUrl.searchParams.delete('app');
    cleanUrl.hash = '';
    // A unique value also bypasses any intermediary/browser navigation cache.
    cleanUrl.searchParams.set(CACHE_RESET_QUERY, String(Date.now()));
    const logoutTarget = `${cleanUrl.pathname}${cleanUrl.search}` || '/';
    let receiptSent = false;
    let receiptSessionRevoked = false;
    let receiptMaskedEmail = '';
    let receiptError = null;

    try {
        await withLoader(meteonexaText('settings.cache.progress.title'), meteonexaText('settings.cache.receipt.progress.copy'), async () => {
            // Authentication/privacy boundary FIRST. The local app data is removed
            // before any network or browser-cleanup operation, so Safari cannot
            // leave personal local state behind because an asynchronous API hangs.
            clearMeteoNexaLocalRuntimeState({ preservePreferences: true });
            state.session = null;
            state.authServerVerified = false;
            state.diagnosticsAllowed = false;
            state.weather = null;
            state.air = null;
            state.favorites = [];
            state.recent = [];
            state.thresholds = { ...DEFAULT_THRESHOLDS };
            state.privacyNotice = null;
            state.notifications = { ...DEFAULT_NOTIFICATIONS, lastSent: {} };
            state.intelligence.models = [];
            state.intelligence.fetchedAt = 0;
            state.intelligence.locationKey = '';
            state.intelligence.previousSnapshot = null;
            state.intelligence.currentSnapshot = null;
            closeOpenAppDialogs();
            setMobileSidebarOpen(false);
            // Keep the current reset loader until the single final navigation.
            // Rendering auth-view here and then location.replace() caused the
            // visible two-step/double-refresh on desktop and mobile.
            await clearBrowserApplicationState({ localAlreadyCleared: true });

            // Receipt is deliberately attempted only after the local privacy
            // boundary has been applied. For authenticated users the backend
            // derives the destination from the HttpOnly session. Guests send only
            // a short-lived server-encrypted proof issued after a six-digit mailbox
            // challenge; arbitrary browser-supplied email fields are ignored.
            try {
                const receipt = await apiRequest('api/privacy/cache-reset-receipt.php', {
                    ...(guestReceiptProof?.guestProof ? { guestProof: guestReceiptProof.guestProof, guest: true } : {}),
                    language: receiptLanguage
                }, { timeout: 5200, notifyAuthRequired: false });
                receiptSent = receipt.receiptSent === true;
                receiptSessionRevoked = receipt.sessionRevoked === true;
                receiptMaskedEmail = String(receipt.maskedEmail || '');
            } catch (error) {
                receiptError = error;
            }

            // Fail-safe: email delivery must never be able to preserve an
            // authenticated session. If the receipt endpoint did not confirm
            // revocation (network timeout, SMTP error, etc.), revoke separately.
            if (wasEmailSession && !receiptSessionRevoked) {
                try {
                    await apiRequest('api/auth/logout.php', { logout: true, revokeTrustedDevice: true }, {
                        timeout: 1800,
                        notifyAuthRequired: false
                    });
                } catch { }
            }
            await sleep(80);
        }, 320);

        if (receiptSent) {
            showToast(
                meteonexaText('settings.cache.receipt.success.title'),
                meteonexaText('settings.cache.receipt.success.copy', { email: receiptMaskedEmail || meteonexaText('settings.cache.receipt.success.email_fallback') }),
                'success',
                1200
            );
            await sleep(420);
        } else {
            console.warn('CACHE_RECEIPT_EMAIL_FAILED', receiptError);
            showToast(
                meteonexaText('settings.cache.receipt.failure.title'),
                meteonexaText('settings.cache.receipt.failure.copy'),
                'warning',
                1800
            );
            await sleep(900);
        }
    } catch (error) {
        // No browser cleanup or receipt failure is allowed to cancel the
        // authentication boundary or the navigation to a clean login screen.
        console.warn('CACHE_RESET_PARTIAL_FAILURE', error);
    } finally {
        try {
            location.replace(logoutTarget);
        } catch {
            location.href = logoutTarget;
        }
    }
}
function syncVisualViewportMetrics() {
    const viewport = window.visualViewport;
    const height = viewport?.height || window.innerHeight;
    const offsetTop = viewport?.offsetTop || 0;
    document.documentElement.style.setProperty('--visual-height', `${Math.max(320, height)}px`);
    document.documentElement.style.setProperty('--visual-center-y', `${offsetTop + height / 2}px`);
}
function sidebarIsDrawer() {
    return matchMedia('(max-width: 780px)').matches;
}
function setSidebarCollapsed(collapsed, { persist = true } = {}) {
    const app = $('#weather-app');
    const sidebar = $('#sidebar');
    if (!app)
        return;
    if (sidebarIsDrawer()) {
        app.style.removeProperty('grid-template-columns');
        sidebar?.style.removeProperty('width');
        app.classList.remove('sidebar-collapsed');
        setMobileSidebarOpen(!collapsed);
        return;
    }
    app.classList.toggle('sidebar-collapsed', Boolean(collapsed));
    const expanded = !app.classList.contains('sidebar-collapsed');
    app.style.setProperty('grid-template-columns', expanded ? 'var(--sidebar-expanded) minmax(0, 1fr)' : '88px minmax(0, 1fr)', 'important');
    sidebar?.style.setProperty('width', expanded ? 'var(--sidebar-expanded)' : '88px', 'important');
    $('#menu-button')?.setAttribute('aria-expanded', String(expanded));
    $('#menu-button')?.setAttribute('aria-label', expanded ? "" + meteonexaText("app.setsidebarcollapsed.collapse_sidebar") : "" + meteonexaText("app.setsidebarcollapsed.expand_sidebar"));
    if (persist) {
        try {
            localStorage.setItem('meteonexa.sidebar-collapsed', expanded ? '0' : '1');
        }
        catch { }
    }
    setTimeout(() => {
        drawHomeChart();
        drawTrendCharts(state.currentPage === 'details');
        if (state.currentPage === 'details')
            drawDetailChart();
        if (state.radar.map)
            renderRadarMap();
    }, 280);
}
function setMobileSidebarOpen(open) {
    const app = $('#weather-app');
    if (!app)
        return;
    app.classList.toggle('menu-open', Boolean(open));
    document.documentElement.classList.toggle('drawer-open', Boolean(open));
    if (sidebarIsDrawer()) {
        $('#menu-button')?.setAttribute('aria-expanded', String(Boolean(open)));
    }
}
function toggleSidebarNavigation(event) {
    event?.preventDefault?.();
    event?.stopPropagation?.();
    const app = $('#weather-app');
    if (!app)
        return;
    if (sidebarIsDrawer())
        setMobileSidebarOpen(!app.classList.contains('menu-open'));
    else
        setSidebarCollapsed(!app.classList.contains('sidebar-collapsed'));
}
function restoreSidebarState() {
    if (sidebarIsDrawer())
        return;
    let collapsed = innerWidth <= 1180;
    try {
        const saved = localStorage.getItem('meteonexa.sidebar-collapsed');
        if (saved !== null)
            collapsed = saved === '1';
    }
    catch { }
    setSidebarCollapsed(collapsed, { persist: false });
}
function hasUsableLocation(locationData = state.location) {
    return Boolean(locationData)
        && Number.isFinite(Number(locationData.latitude))
        && Number.isFinite(Number(locationData.longitude));
}
function showWelcome(viewId = 'auth-view') {
    const welcome = $('#welcome');
    const app = $('#weather-app');
    if (app) {
        app.hidden = true;
        setMobileSidebarOpen(false);
    }
    if (welcome)
        welcome.hidden = false;
    setOnboardingView(viewId);
    if (viewId === 'location-view' && hasUsableLocation())
        updateSelectedLocationUI();
}
async function showApp({ refresh = true, waitForWeather = false, forceWeather = false } = {}) {
    const welcome = $('#welcome');
    const app = $('#weather-app');
    const revealAfterHydration = Boolean(waitForWeather && refresh);
    if (welcome)
        welcome.hidden = true;
    if (app && !revealAfterHydration)
        app.hidden = false;
    updateProfileUI();
    applyGuestAccessUI();
    if (isGuestSession() && !state.officialAlerts) renderHomeOfficialAlert({ loading: true });
    loadSevereWeatherMonitor({ force: false }).catch(()=>{});
    const shortcut = location.hash.replace('#', '');
    let weatherTask = null;
    if (refresh) {
        weatherTask = loadWeather({ force: forceWeather || !state.weather, silent: true })
            .catch(error => { console.warn('WEATHER_BACKGROUND_REFRESH_FAILED', error); return state.weather; });
        // On a real app entry (cold boot/login) route-dependent pages must never
        // calculate from a cached/preview payload that is about to be replaced.
        if (waitForWeather)
            await weatherTask;
    }
    const notificationShortcut = shortcut === 'notifications' || shortcut === 'alerts';
    const targetPage = ["radar", 'favorites', 'details', 'history', "intelligence", 'advanced', 'bug-report', 'route', 'devices'].includes(shortcut) ? shortcut : 'home';
    await goToPage(targetPage, { loader: false, instant: revealAfterHydration, metricEntry: true });
    if (app)
        app.hidden = false;
    if (notificationShortcut) {
        setTimeout(() => openNotificationCenter(), 80);
    }
    if (isGuestSession())
        setTimeout(() => showGuestAccessNotice({ once: true }), 220);
    else {
        loadNotificationInbox({ silent: true });
        Promise.resolve(weatherTask).catch(() => null).then(() => loadPersonalWeatherPreferences().then(() => maybeGenerateMorningBriefing()).catch(() => {}));
    }
    // Background hydration must stay background: returning the in-flight promise
    // would make callers await it even when waitForWeather=false.
    return waitForWeather ? state.weather : null;
}
function reconcileRootView({ refresh = false, waitForWeather = false } = {}) {
    state.session = loadJSON(STORAGE.session, state.session || null);
    state.location = loadJSON(STORAGE.location, state.location || CONFIG.DEFAULT_LOCATION);
    let forceAuth = false;
    try {
        forceAuth = sessionStorage.getItem(SESSION_FLAGS.forceAuth) === '1';
    }
    catch { }
    if (forceAuth) {
        state.session = null;
        try {
            localStorage.removeItem(STORAGE.session);
        }
        catch { }
        showWelcome('auth-view');
        return;
    }
    if (APP_PREVIEW && !state.session) {
        state.session = { type: 'guest', name: meteonexaText('session.preview.name'), at: Date.now() };
    }
    if (state.session && hasUsableLocation()) {
        const wasHidden = $('#weather-app')?.hidden !== false;
        const shouldWaitForWeather = !isGuestSession() && (waitForWeather || wasHidden);
        return showApp({
            refresh: refresh || wasHidden,
            waitForWeather: shouldWaitForWeather,
            // Guest sessions render cached/preview data immediately and refresh in
            // background. Authenticated sessions may wait when restoring private
            // route-dependent surfaces that require a fresh hydration.
            forceWeather: wasHidden
        });
    }
    showWelcome(state.session ? 'location-view' : 'auth-view');
    return Promise.resolve();
}
function bindEvents() {
    bindNotificationEvents();
    initializeUiTooltips();
    syncVisualViewportMetrics();
    window.visualViewport?.addEventListener('resize', syncVisualViewportMetrics);
    window.visualViewport?.addEventListener('scroll', syncVisualViewportMetrics);
    startLiveClocks();
    initializeThresholdsPanelFollow();
    const guestLoginButton = $('#guest-login');
    if (!guestLoginButton) throw new Error('METEONEXA_GUEST_LOGIN_BUTTON_MISSING');
    const activateGuestAccess = (button, touchGuard) => {
        if (touchGuard) armGuestMobileFocusGuard(2600);
        button?.blur?.();
        startLocalSession({ type: 'guest', name: "" + meteonexaText("app.updateprofileui.guest") }, { touchGuard }).catch(error => console.warn('GUEST_SESSION_START_FAILED', error));
    };
    guestLoginButton.addEventListener('pointerdown', event => {
        const touchGuard = event.pointerType === 'touch' || matchMedia('(pointer: coarse)').matches;
        if (!touchGuard) return;
        event.preventDefault();
        event.stopPropagation();
        guestTouchActivationArmed = true;
        armGuestMobileFocusGuard(2800);
    }, { passive: false });
    guestLoginButton.addEventListener('pointerup', event => {
        if (!guestTouchActivationArmed) return;
        event.preventDefault();
        event.stopPropagation();
        guestTouchActivationArmed = false;
        guestTouchActivationAt = Date.now();
        activateGuestAccess(event.currentTarget, true);
    }, { passive: false });
    guestLoginButton.addEventListener('pointercancel', () => { guestTouchActivationArmed = false; });
    guestLoginButton.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        if (Date.now() - guestTouchActivationAt < 900) return;
        activateGuestAccess(event.currentTarget, false);
    });
    document.addEventListener('focusin', event => {
        if (Date.now() >= mobileFocusGuardUntil) return;
        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable)) return;
        target.blur?.();
    }, true);
    $$('[data-auth]').forEach(button => button.addEventListener('click', () => button.dataset.auth === 'email' ? beginEmailAccessFlow() : openAuthDialog(button.dataset.auth)));
    $('#back-to-auth').addEventListener('click', () => setOnboardingView('auth-view'));
    $('#detect-location').addEventListener('click', detectLocation);
    $('#continue-to-app').addEventListener('click', async () => {
        if (isGuestSession()) {
            await showApp({ refresh: true, waitForWeather: false, forceWeather: true });
            return;
        }
        await withLoader("" + meteonexaText("app.activateguestaccess.preparing_dashboard"), "" + meteonexaText("app.activateguestaccess.preparing_weather_experience"), async () => await showApp({ refresh: true, waitForWeather: true, forceWeather: true }), 650);
    });
    $('#onboarding-search-btn').addEventListener('click', () => searchCities($('#onboarding-city').value, $('#onboarding-results'), selectOnboardingLocation, { compact: true }));
    $('#onboarding-city').addEventListener('input', debounce(event => searchCities(event.target.value, $('#onboarding-results'), selectOnboardingLocation, { compact: true }), 420));
    $('#onboarding-city').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#onboarding-search-btn').click();
        }
    });
    $('#auth-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!$('#auth-verify-step').hidden)
            await verifyEmailCode();
        else
            await requestEmailCode();
    });
    $('#auth-send-code').addEventListener('click', () => requestEmailCode());
    $('#auth-resend').addEventListener('click', () => requestEmailCode({ resend: true }));
    $('#auth-change-email').addEventListener('click', () => { stopAuthResendTimer(); setAuthStep('request'); setTimeout(() => $('#auth-primary').focus(), 80); });
    $('#auth-primary').addEventListener('input', () => clearFieldError('auth-primary'));
    $('#auth-code').addEventListener('input', event => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6); clearFieldError('auth-code'); });
    $$('[data-dialog-close]').forEach(button => button.addEventListener('click', event => {
        event.preventDefault();
        const dialog = button.closest('dialog');
        if (dialog?.id === 'auth-dialog')
            stopAuthResendTimer();
        dialog?.close('cancel');
    }));
    document.addEventListener('click', event => {
        const pageButton = event.target.closest('button[data-page], [role="button"][data-page]');
        if (pageButton && !pageButton.disabled) {
            event.preventDefault();
            event.stopPropagation();
            if (!SERVICES.get('navigation')?.request?.(pageButton.dataset.page)) goToPage(pageButton.dataset.page);
        }
    });
    document.addEventListener('meteonexa:navigation-request', event => { const detail=event?.detail||{}; goToPage(detail.page, detail.options||{}).catch(error => SERVICES.get('navigation')?.failed?.(detail.page,error)); });
    $('#trust-brief-ai')?.addEventListener('click', () => {
        const prompt = t('trust.brief.ai_prompt');
        const display = t('trust.brief.ai_display');
        SERVICES.get('suite')?.openAssistantWithAi?.(prompt, display);
    });
    const confirmDialog = $('#confirm-dialog');
    const confirmForm = $('#confirm-form');
    const handleConfirmChoice = (event, confirmed) => {
        event.preventDefault();
        event.stopPropagation();
        settleConfirmDialog(confirmed);
    };
    $('#confirm-cancel')?.addEventListener('click', event => handleConfirmChoice(event, false), { capture: true, passive: false });
    $('#confirm-ok')?.addEventListener('click', event => handleConfirmChoice(event, true), { capture: true, passive: false });
    confirmForm?.addEventListener('submit', event => {
        event.preventDefault();
        settleConfirmDialog(true);
    });
    confirmDialog?.addEventListener('cancel', event => {
        event.preventDefault();
        settleConfirmDialog(false);
    });
    confirmDialog?.addEventListener('close', () => {
        if (pendingConfirmRequest)
            settleConfirmDialog(confirmDialog.returnValue === 'confirm', { closeDialog: false });
    });
    confirmDialog?.addEventListener('click', event => {
        if (event.target === confirmDialog)
            settleConfirmDialog(false);
    });
    $$('[data-action="logout"]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            logout({ returnToProfile: Boolean(button.closest('#profile-dialog')) });
        }, { passive: false });
    });
    $('#menu-button').addEventListener('click', toggleSidebarNavigation, { passive: false });
    $('#sidebar-close')?.addEventListener('click', () => {
        if (sidebarIsDrawer())
            setMobileSidebarOpen(false);
        else
            setSidebarCollapsed(true);
    });
    $('#sidebar-scrim').addEventListener('click', () => setMobileSidebarOpen(false));
    const closeProfileForAction = () => { const dialog = $('#profile-dialog'); if (dialog?.open) dialog.close(); };
    $('#refresh-button').addEventListener('click', async () => { closeProfileForAction(); await loadWeather({ force: true }); await SERVICES.get('advanced')?.afterRefresh?.(); });
    $('#share-button').addEventListener('click', () => { closeProfileForAction(); shareCurrentWeather(); });
    $('#share-system').addEventListener('click', runSystemShare);
    $('#share-copy').addEventListener('click', copyCurrentShare);
    $('#share-whatsapp').addEventListener('click', () => openShareChannel('whatsapp'));
    $('#share-email').addEventListener('click', () => openShareChannel('email'));
    $('#hour-detail-prev').addEventListener('click', () => stepHourDetail(-1));
    $('#hour-detail-next').addEventListener('click', () => stepHourDetail(1));
    $('#day-detail-prev').addEventListener('click', () => stepDayDetail(-1));
    $('#day-detail-next').addEventListener('click', () => stepDayDetail(1));
    $('#header-favorite').addEventListener('click', () => { closeProfileForAction(); toggleCurrentFavorite(); });
    $('#hero-favorite').addEventListener('click', toggleCurrentFavorite);
    $('#command-search')?.addEventListener('submit', event => {
        event.preventDefault();
        performCommandSearch();
    });
    $('#command-city-search')?.addEventListener('input', debounce(event => {
        const query = event.target.value.trim();
        if (query.length >= 2)
            searchCities(query, $('#command-search-results'), selectCommandLocation, { compact: true });
        else
            clearCommandSearch();
    }, 360));
    $('#command-city-search')?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            clearCommandSearch({ clearInput: true });
            event.currentTarget.blur();
        }
    });
    $('#location-button').addEventListener('click', () => openSearch('switch'));
    $('#add-favorite-button').addEventListener('click', () => openSearch('favorite'));
    $('#global-search-button').addEventListener('click', performGlobalSearch);
    $('#global-use-location')?.addEventListener('click', useCurrentLocationFromSearch);
    $('#global-city-search').addEventListener('input', debounce(event => {
        if (event.target.value.trim().length >= 2)
            searchCities(event.target.value, $('#global-search-results'), handleSearchSelection);
        else
            $('#global-search-results').innerHTML = '';
    }, 420));
    $('#global-city-search').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            performGlobalSearch();
        }
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.command-search-shell'))
            clearCommandSearch();
    });
    $('#privacy-center-button').addEventListener('click', openPrivacyCenter);
    $('#privacy-notice-details')?.addEventListener('click', openPrivacyCenter);
    $$('[data-privacy-link]').forEach(link => link.addEventListener('click', () => {
        link.setAttribute('href', privacyPageHref());
        link.removeAttribute('target');
    }));
    $('#privacy-notice-ok')?.addEventListener('click', acknowledgePrivacyNotice);
    $('#privacy-close')?.addEventListener('click', () => $('#privacy-dialog')?.close());
    $('#profile-button').addEventListener('click', () => {
        updateProfileUI();
        $('#profile-dialog').showModal();
    });
    $('#profile-settings-shortcut').addEventListener('click', () => {
        $('#profile-dialog').close();
        rebuildLanguageOptions();
        setSettingsLanguageMenu(false);
        syncDevicesSettingsButton();
        $('#settings-dialog').showModal();
    });
    $('#settings-cache-action')?.addEventListener('click', () => clearApplicationCache());
    $('#settings-devices-action')?.addEventListener('click', openDeviceAccessDialog);
    $('#devices-revoke-others')?.addEventListener('click', revokeOtherDeviceAccesses);
    $('#settings-integrations')?.addEventListener('click', () => {
        if (isGuestSession()) {
            showGuestAccessNotice();
            return;
        }
        $('#settings-dialog')?.close();
        goToPage('devices');
    });
    
    $('#temperature-unit').addEventListener('change', event => withLoader(t("app.closeprofileforaction.changing_unit"), t("app.closeprofileforaction.converting_all_temperatures"), async () => { state.settings.unit = event.target.value; applySettings(); }, 300));
    $('#welcome-language-button')?.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = $('#welcome-language-button')?.getAttribute('aria-expanded') === 'true';
        setWelcomeLanguageMenu(!isOpen, { focusActive: !isOpen });
    });
    $$('[data-language-option]').forEach(option => option.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const nextLanguage = option.dataset.languageOption;
        setWelcomeLanguageMenu(false);
        await changeApplicationLanguage(nextLanguage);
    }));
    $('#settings-language-button')?.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = $('#settings-language-button')?.getAttribute('aria-expanded') === 'true';
        setSettingsLanguageMenu(!isOpen, { focusActive: !isOpen });
    });
    $$('[data-settings-language-option]').forEach(option => option.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const nextLanguage = option.dataset.settingsLanguageOption;
        setSettingsLanguageMenu(false);
        await changeApplicationLanguage(nextLanguage);
    }));
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('#welcome-language-picker'))
            setWelcomeLanguageMenu(false);
        if (!event.target.closest('#settings-language-picker'))
            setSettingsLanguageMenu(false);
    });
    $('#welcome-language-menu')?.addEventListener('keydown', event => {
        const options = $$('[data-language-option]', event.currentTarget);
        const currentIndex = options.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            setWelcomeLanguageMenu(false, { restoreFocus: true });
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key))
            return;
        event.preventDefault();
        let nextIndex = currentIndex;
        if (event.key === 'Home')
            nextIndex = 0;
        else if (event.key === 'End')
            nextIndex = options.length - 1;
        else if (event.key === 'ArrowDown')
            nextIndex = (currentIndex + 1 + options.length) % options.length;
        else
            nextIndex = (currentIndex - 1 + options.length) % options.length;
        options[nextIndex]?.focus({ preventScroll: true });
    });
    $('#settings-language-menu')?.addEventListener('keydown', event => {
        const options = $$('[data-settings-language-option]', event.currentTarget);
        const currentIndex = options.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            setSettingsLanguageMenu(false, { restoreFocus: true });
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key))
            return;
        event.preventDefault();
        let nextIndex = currentIndex;
        if (event.key === 'Home')
            nextIndex = 0;
        else if (event.key === 'End')
            nextIndex = options.length - 1;
        else if (event.key === 'ArrowDown')
            nextIndex = (currentIndex + 1 + options.length) % options.length;
        else
            nextIndex = (currentIndex - 1 + options.length) % options.length;
        options[nextIndex]?.focus({ preventScroll: true });
    });
    $$('[data-language-setting]').forEach(select => select.addEventListener('change', event => changeApplicationLanguage(event.target.value)));
    $('#theme-setting').addEventListener('change', event => {
        const next = event.target.value;
        // Theme changes are a local UI action first: never wait for the API before repainting.
        state.preferenceLocalMutationAt = Date.now();
        markPreferencesPending(true);
        applyThemePreference(next, { persist: true, notify: true });
        requestAnimationFrame(() => {
            if (state.weather) {
                renderAll();
                updateWeatherAtmosphere(true);
            }
        });
        // Persist remotely in the background. A slow/failed request must not roll the visible theme back.
        void saveRemotePreferences({ showConfirmation: true }).then(() => {
            applyThemePreference(state.settings.theme, { persist: true, notify: false });
        });
    });
    $('#refresh-setting').addEventListener('change', event => withLoader(t("app.closeprofileforaction.automatic_refresh"), t("app.closeprofileforaction.saving_new_frequency"), async () => { state.settings.refresh = Number(event.target.value); applySettings({ rerender: false }); }, 300));
    $('#reduce-motion-setting').addEventListener('change', event => withLoader("" + meteonexaText("app.closeprofileforaction.animation_preferences"), "" + meteonexaText("app.closeprofileforaction.applying_new_mode"), async () => { state.settings.reduceMotion = event.target.checked; applySettings({ rerender: false }); updateWeatherAtmosphere(true); }, 300));
    $('#radar-city-search')?.addEventListener('input', debounce(event => {
        const query = event.target.value.trim();
        const target = $('#radar-city-results');
        if (query.length >= 3) {
            target.classList.add('open');
            searchCities(query, target, selectRadarLocation, { compact: true });
        }
        else {
            target.classList.remove('open');
            target.innerHTML = '';
        }
    }, 380));
    $('#radar-city-search')?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            performRadarCitySearch();
        }
    });
    $('#radar-city-gps')?.addEventListener('click', useGpsFromRadar);
    document.addEventListener('pointerdown', event => {
        const shell = event.target.closest('.radar-city-search-shell');
        if (!shell) {
            const results = $('#radar-city-results');
            results?.classList.remove('open');
        }
    });
    $('#radar-mode-setting').addEventListener('change', event => withLoader("" + meteonexaText("app.radar_layer"), "" + meteonexaText("app.setting_initial_map"), async () => {
        state.settings.radarMode = event.target.value;
        persistLocalSettings();
        if (state.radar.loaded)
            setRadarMode(event.target.value, { notify: false });
    }, 280));
    $('#radar-play').addEventListener('click', toggleRadarAnimation);
    $('#radar-prev').addEventListener('click', () => stepRadar(-1));
    $('#radar-next').addEventListener('click', () => stepRadar(1));
    $('#radar-refresh').addEventListener('click', () => ensureRadar(true));
    $('#radar-retry').addEventListener('click', () => ensureRadar(true));
    $$('[data-radar-mode]').forEach(button => button.addEventListener('click', () => setRadarMode(button.dataset.radarMode)));
    $('#radar-speed').addEventListener('change', event => {
        state.radar.speed = Number(event.target.value);
        if (state.radar.timer) {
            stopRadarAnimation();
            toggleRadarAnimation();
        }
    });
    $('#radar-slider').addEventListener('input', event => setRadarFrame(Number(event.target.value)));
    $('#radar-zoom-in').addEventListener('click', () => zoomRadar(1));
    $('#radar-zoom-out').addEventListener('click', () => zoomRadar(-1));
    $('#radar-recenter').addEventListener('click', () => withLoader("" + meteonexaText("app.recentering_radar"), "" + meteonexaText("app.returning_map_current_location"), async () => {
        state.radar.center = { lat: Number(state.location.latitude), lon: Number(state.location.longitude) };
        state.radar.zoom = state.radar.mode === 'live' ? 7 : 6;
        renderRadarMap();
    }, 280));
    $('#radar-opacity').addEventListener('input', event => {
        state.radar.opacity = Number(event.target.value) / 100;
        $('#radar-opacity-value').textContent = `${event.target.value}%`;
        $$('.radar-overlay-tile').forEach(tile => { tile.style.opacity = String(state.radar.opacity); });
        if (state.radar.vectorMapReady && state.radar.mode === 'live')
            syncRadarVectorLayer({ force: false });
        if (state.radar.mode === 'forecast')
            drawForecastRadarLayer();
    });
    $('#radar-fullscreen').addEventListener('click', async () => {
        const card = $('#radar-card');
        try {
            if (!document.fullscreenElement)
                await card.requestFullscreen();
            else
                await document.exitFullscreen();
            setTimeout(renderRadarMap, 220);
        }
        catch {
            showToast("" + meteonexaText("app.full_screen_unavailable"), "" + meteonexaText("app.browser_does_not_allow_mode"), 'warning');
        }
    });
    $('#bug-report-form')?.addEventListener('submit', submitBugReport);
    $('#bug-category')?.addEventListener('change', syncBugCategoryOther);
    $('#bug-file-choose')?.addEventListener('click', event => { event.stopPropagation(); $('#bug-attachments')?.click(); });
    $('#bug-record-video')?.addEventListener('click', event => { event.stopPropagation(); startBugVideoRecording(); });
    $('#bug-attachments')?.addEventListener('change', event => addBugReportFiles(event.target.files));
    $('#bug-dropzone')?.addEventListener('click', event => { if (!event.target.closest('button')) $('#bug-attachments')?.click(); });
    $('#bug-dropzone')?.addEventListener('keydown', event => { if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button')) { event.preventDefault(); $('#bug-attachments')?.click(); } });
    ['dragenter','dragover'].forEach(name => $('#bug-dropzone')?.addEventListener(name, event => { event.preventDefault(); event.stopPropagation(); $('#bug-dropzone')?.classList.add('is-dragover'); }));
    ['dragleave','drop'].forEach(name => $('#bug-dropzone')?.addEventListener(name, event => { event.preventDefault(); event.stopPropagation(); $('#bug-dropzone')?.classList.remove('is-dragover'); if (name === 'drop') addBugReportFiles(event.dataTransfer?.files); }));
    $('#bug-attachment-list')?.addEventListener('click', event => { const button = event.target.closest('[data-bug-media-remove]'); if (!button) return; removeBugReportMedia(Number(button.dataset.bugMediaRemove)); });
    $('#bug-recording-stop')?.addEventListener('click', () => stopBugVideoRecording({ discard:false }));
    $('#bug-recording-cancel')?.addEventListener('click', () => stopBugVideoRecording({ discard:true }));
    $('#alerts-mark-all-read')?.addEventListener('click', markAllCurrentAlertsRead);
    $('#alerts-list')?.addEventListener('click', event => { const button = event.target.closest('[data-alert-mark-read]'); if (!button) return; const card = button.closest('[data-alert-id]'); markAlertRead(String(card?.dataset.alertId || '')); renderAlerts(); });
    syncBugCategoryOther();
    $('#reset-thresholds').addEventListener('click', () => withLoader("" + meteonexaText("app.resetting_thresholds"), "" + meteonexaText("app.recalculating_alert_center"), async () => {
        state.thresholds = { ...DEFAULT_THRESHOLDS };
        $('#threshold-rain').value = String(state.thresholds.rain);
        $('#threshold-wind').value = String(state.thresholds.wind);
        $('#threshold-heat').value = String(state.thresholds.heat);
        updateThreshold('rain', state.thresholds.rain);
        updateThreshold('wind', state.thresholds.wind);
        updateThreshold('heat', state.thresholds.heat);
        showToast("" + meteonexaText("app.thresholds_reset"), "" + meteonexaText("app.recommended_values_have_been_restored"), 'success');
    }, 320));
    $('#threshold-rain').addEventListener('input', event => updateThreshold('rain', event.target.value));
    $('#threshold-wind').addEventListener('input', event => updateThreshold('wind', event.target.value));
    $('#threshold-heat').addEventListener('input', event => updateThreshold('heat', event.target.value));
    ['threshold-rain', 'threshold-wind', 'threshold-heat'].forEach(id => $('#' + id).addEventListener('change', () => { showToast("" + meteonexaText("app.thresholds_updated"), "" + meteonexaText("app.alerts_have_been_recalculated"), 'success', 2200); evaluateLocalWeatherNotifications(); }));
    $('#history-form')?.addEventListener('submit', event => {
        event.preventDefault();
        state.history.start = $('#history-start')?.value || '';
        state.history.end = $('#history-end')?.value || '';
        loadHistory({ force: true });
    });
    $$('[data-history-days]').forEach(button => button.addEventListener('click', () => {
        setHistoryRange(Number(button.dataset.historyDays || 30));
        loadHistory({ force: true });
    }));
    $('#intelligence-refresh')?.addEventListener('click', () => withLoader(
        t("intelligence.task.updating_analysis"),
        t("intelligence.retrieving_forecasts_from_main_models"),
        async () => {
            await loadWeather({ force: true, silent: true });
            await loadIntelligence({ force: true, silent: true });
            await syncVerifiedModelAccuracy({ force: true });
        },
        520
    ));
    $('#impact-preference-chips')?.addEventListener('click', event => {
        const button = event.target.closest('[data-impact-preference]');
        if (!button) return;
        const id = String(button.dataset.impactPreference || '');
        if (!IMPACT_ACTIVITY_IDS.includes(id)) return;
        const next = new Set(personalWeatherPrefs.activities);
        if (next.has(id)) next.delete(id); else next.add(id);
        personalWeatherPrefs.activities = IMPACT_ACTIVITY_IDS.filter(activity => next.has(activity));
        renderPersonalImpact();
        savePersonalWeatherPreferences().catch(error => console.warn('IMPACT_PREFERENCE_SAVE_FAILED', error));
    });
    $('#briefing-enabled')?.addEventListener('change', event => {
        personalWeatherPrefs.briefingEnabled = event.target.checked === true;
        savePersonalWeatherPreferences({ notify: true }).then(() => {
            if (personalWeatherPrefs.briefingEnabled) maybeGenerateMorningBriefing();
        }).catch(() => {});
    });
    $('#briefing-hour')?.addEventListener('change', event => {
        personalWeatherPrefs.briefingHour = clamp(Number(event.target.value || currentLocationHour()), 0, 23);
        personalWeatherPrefs.briefingHourSet = true;
        savePersonalWeatherPreferences({ notify: true }).catch(() => {});
    });
    $('#briefing-generate')?.addEventListener('click', () => withLoader(t('briefing.action.generate'), t('assistant.thinking'), () => generateAiBriefing({ manual: true }), 480));
    $('#proactive-enabled')?.addEventListener('change', event => {
        personalWeatherPrefs.proactiveEnabled = event.target.checked === true;
        savePersonalWeatherPreferences({ notify: true }).then(() => {
            renderProactiveInsight();
            if (personalWeatherPrefs.proactiveEnabled) maybeGenerateProactiveInsight({ manual: true }).catch(() => {});
        }).catch(() => {});
    });
    $('#proactive-run')?.addEventListener('click', () => withLoader(t('proactive.action.run'), t('assistant.thinking'), () => maybeGenerateProactiveInsight({ manual: true, force: true }), 480));
    
    
    bindGlobalLifecycleEvents();
}
function initializeThresholds() {
    $('#threshold-rain').value = String(state.thresholds.rain);
    $('#threshold-wind').value = String(state.thresholds.wind);
    $('#threshold-heat').value = String(state.thresholds.heat);
    updateThreshold('rain', state.thresholds.rain);
    updateThreshold('wind', state.thresholds.wind);
    updateThreshold('heat', state.thresholds.heat);
    syncNotificationButton();
}
const { bindGlobalLifecycleEvents, readCacheSentinel, writeCacheSentinel, detectExternalCacheEvictionAndForceLogin, enforceAuthenticationStorageCoherence, recoverRootViewAfterBootFailure } = SERVICES.require('appLifecycle').create({
    state, $, syncNotificationButton: (...args) => syncNotificationButton(...args), updateLiveClocks: (...args) => updateLiveClocks(...args),
    synchronizeRemotePreferences: (...args) => synchronizeRemotePreferences(...args), reconcileRemoteDeviceRevocation: (...args) => reconcileRemoteDeviceRevocation(...args),
    refreshWeatherOnForeground: (...args) => refreshWeatherOnForeground(...args), loadSevereWeatherMonitor: (...args) => loadSevereWeatherMonitor(...args),
    ensureRadar: (...args) => ensureRadar(...args), loadIntelligence: (...args) => loadIntelligence(...args), reconcileRootView: (...args) => reconcileRootView(...args),
    weatherNeedsForegroundRefresh: (...args) => weatherNeedsForegroundRefresh(...args), synchronizeLocalState: (...args) => synchronizeLocalState(...args),
    updateNetworkStatus: (...args) => updateNetworkStatus(...args), debounce, detectDevice, hideChartTooltip: (...args) => hideChartTooltip(...args),
    sidebarIsDrawer: (...args) => sidebarIsDrawer(...args), setMobileSidebarOpen: (...args) => setMobileSidebarOpen(...args),
    setSidebarCollapsed: (...args) => setSidebarCollapsed(...args), drawHomeChart: (...args) => drawHomeChart(...args), drawTrendCharts: (...args) => drawTrendCharts(...args),
    drawAllDetailCharts: (...args) => drawAllDetailCharts(...args), drawHistoryChart: (...args) => drawHistoryChart(...args), renderRadarMap: (...args) => renderRadarMap(...args),
    scheduleThresholdsPanelFollow: (...args) => scheduleThresholdsPanelFollow(...args), resizeWeatherFX: (...args) => resizeWeatherFX(...args),
    openSearch: (...args) => openSearch(...args), setWelcomeLanguageMenu: (...args) => setWelcomeLanguageMenu(...args),
    applyThemePreference: (...args) => applyThemePreference(...args), renderAll: (...args) => renderAll(...args), updateWeatherAtmosphere: (...args) => updateWeatherAtmosphere(...args),
    saveRemotePreferences: (...args) => saveRemotePreferences(...args), CACHE_SENTINEL, STORAGE, SESSION_FLAGS,
    apiRequest: (...args) => apiRequest(...args), hasUsableLocation: (...args) => hasUsableLocation(...args), showWelcome: (...args) => showWelcome(...args)
});

RUNTIME_API.publish({
    build: APP_BUILD,
    getState: () => state,
    previewMode: PREVIEW_MODE,
    loadJSON,
    saveJSON,
    showToast,
    withLoader,
    loadWeather,
    loadForecastFusion,
    updateThreshold,
    syncEnhancedSelect,
    currentHourlyIndex,
    temperature,
    weatherSummary,
    appLocale,
    t,
    average,
    renderAll,
    ensureRadar,
    removeRadarVectorLayer,
    formatClock,
    weatherMeta,
    weatherArt
});

async function init() {
    const bootDeadline = setTimeout(() => {
        document.documentElement.classList.remove('fresh-build');
        setLoader(false);
        const appVisible = $('#weather-app')?.hidden === false;
        const welcomeVisible = $('#welcome')?.hidden === false;
        if (!appVisible && !welcomeVisible) recoverRootViewAfterBootFailure(new Error('BOOT_WATCHDOG_RECOVERY'));
    }, 6000);
    try {
        await detectExternalCacheEvictionAndForceLogin();
        enforceAuthenticationStorageCoherence();
        state.recent = uniqueLocations(state.recent).slice(0, 6);
        saveJSON(STORAGE.recent, state.recent);
        if (state.weather?.timezone)
            applyWeatherTimeZoneMetadata(state.weather);
        applyLoginWeatherBackdrop(state.weather);
        detectDevice();
        initializeWeatherFX();
        await loadRemotePreferences();
        applyUiVisibility();
        loadUiVisibilityConfig().catch(error => console.warn('UI_VISIBILITY_CONFIG_BACKGROUND_FAILED', error));
        // The current auth flow no longer stores the email address in localStorage. Legacy
        // sessions are scrubbed immediately, then validated against the new
        // HttpOnly server session when online.
        if (state.session?.type === 'email' && Object.prototype.hasOwnProperty.call(state.session, 'email')) {
            state.session = { type: 'email', name: String(state.session.name || '').slice(0, 120), verified: state.session.verified === true, at: Number(state.session.at) || Date.now() };
            persistSessionSafely();
        }
        if (state.session?.type === 'email')
            await reconcileEmailServerSession();
        else
            reconcileEmailServerSession().catch(() => false);
        // Migrate locations saved by older cold boots where the i18n keys were
        // stored as labels (for example location.default.name).
        const defaultLatitude = Number(CONFIG.DEFAULT_LOCATION?.latitude);
        const defaultLongitude = Number(CONFIG.DEFAULT_LOCATION?.longitude);
        const currentLatitude = Number(state.location?.latitude);
        const currentLongitude = Number(state.location?.longitude);
        const isDefaultPoint = Number.isFinite(currentLatitude) && Number.isFinite(currentLongitude)
            && Math.abs(currentLatitude - defaultLatitude) < 0.0001
            && Math.abs(currentLongitude - defaultLongitude) < 0.0001;
        const defaultFields = [
            ['name', CONFIG.DEFAULT_LOCATION.nameKey],
            ['admin1', CONFIG.DEFAULT_LOCATION.admin1Key],
            ['country', CONFIG.DEFAULT_LOCATION.countryKey]
        ];
        let repairedDefaultLocation = false;
        defaultFields.forEach(([field, key]) => {
            const current = String(state.location?.[field] || '').trim();
            const isLegacyKey = current === key;
            if (!isLegacyKey && !(isDefaultPoint && !current))
                return;
            const translated = String(t(key) || '').trim();
            if (translated && translated !== key) {
                state.location[field] = translated;
                repairedDefaultLocation = true;
            }
        });
        if (repairedDefaultLocation)
            saveJSON(STORAGE.location, state.location);
        rebuildLanguageOptions();
        translateDOM(document);
        initializeI18nObserver();
        state.radar.mode = state.settings.radarMode || 'live';
        initializeEnhancedSelects();
        applySettings({ rerender: false });
        initializeThresholds();
        bindEvents();
        startRealtimeSynchronization();
        restoreSidebarState();
        initializeChartObservers();
        initializePrivacyNotice();
        registerPWA();
        syncFavoriteUI();
        updateProfileUI();
        await reconcileRootView({ refresh: true, waitForWeather: true });
        state.bootComplete = true;
        state.lastForegroundRefreshAt = Date.now();
        document.dispatchEvent(new CustomEvent('meteonexa:ready'));
        pullAccountSync({ force: true }).catch(error => console.warn('ACCOUNT_SYNC_PULL_FAILED', error));
    }
    catch (error) {
        console.error('BOOT_ERROR', error);
        recoverRootViewAfterBootFailure(error);
    }
    finally {
        clearTimeout(bootDeadline);
        document.documentElement.classList.remove('fresh-build');
        state.loaderDepth = 0;
        setLoader(false);
    }
}
const startApplication = () => { init().catch(error => { console.error('BOOT_ERROR', error); recoverRootViewAfterBootFailure(error); setLoader(false); }); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startApplication, { once: true });
else queueMicrotask(startApplication);
