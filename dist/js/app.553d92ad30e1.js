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

const {
    browserTheme, appLocale, t, translateDOM, initializeI18nObserver, persistLocalSettings,
    markPreferencesPending, loadRemotePreferences, saveRemotePreferences, synchronizeRemotePreferences,
    rebuildLanguageOptions, setWelcomeLanguageMenu, setSettingsLanguageMenu, changeApplicationLanguage
} = SERVICES.require('i18nPreferences').create({
    state, storage: STORAGE, saveJSON, enhancedSelects, $, $$, services: SERVICES,
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
SERVICES.publish('loader', Object.freeze({
    run: (title, copy, task, minDuration = 420) => withLoader(title, copy, task, minDuration),
    active: () => state.loaderDepth > 0
}));
SERVICES.publish('auth', Object.freeze({
    isAuthenticated: () => isAuthenticatedSession(),
    serverVerified: () => hasVerifiedServerSession(),
    handleAuthRequired: () => handleAuthRequiredResponse()
}));

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

SERVICES.publish('panelLoader', Object.freeze({ set: setArticleInlineLoading }));


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


let publicAccuracyCache={key:'',at:0,data:null};






let proactiveGenerating = false;


let thresholdsFollowRaf = 0;
let thresholdsFollowObserver = null;

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
const {
    shareCurrentWeather, runSystemShare, copyCurrentShare, openShareChannel, updateThreshold,
    refreshBugReportContext, syncBugCategoryOther, clearBugReportMedia, addBugReportFiles,
    startBugVideoRecording, stopBugVideoRecording, submitBugReport, renderBugReportMedia, removeBugReportMedia
} = SERVICES.require('appUtilities').create({
    state, $, APP_BUILD, STORAGE, currentHourlyIndex, escapeHTML, isGuestSession,
    locationTimeZoneSummary, renderAlerts, saveJSON, shortLocationLabel, t, temperature, validEmail,
    weatherMeta, withLoader, showToast, meteonexaText
});

const PRIVACY_NOTICE_VERSION = '20.1';
SERVICES.publish('toast', showToast);

const { syncDevicesSettingsButton, formatAccessDateTime, deviceAccessLabel, renderDeviceAccessList, approximateDeviceLocationHeader, restoreDevicesDialogShell, loadDeviceAccessHistory, openDeviceAccessDialog, revokeDeviceAccess, deletePastDeviceAccess, revokeOtherDeviceAccesses, reconcileRemoteDeviceRevocation } = SERVICES.require('deviceSessions').create({
    state, $, $$, STORAGE, SESSION_FLAGS, meteonexaText, appLocale,
    apiRequest: (...args) => apiRequest(...args), confirmAction: (...args) => confirmAction(...args),
    withLoader: (...args) => withLoader(...args), showToast: (...args) => showToast(...args),
    showGuestAccessNotice: (...args) => showGuestAccessNotice(...args),
    nextPaint: (...args) => nextPaint(...args), applyGuestAccessUI: (...args) => applyGuestAccessUI(...args),
    updateProfileUI: (...args) => updateProfileUI(...args), showWelcome: (...args) => showWelcome(...args),
    reconcileEmailServerSession: (...args) => reconcileEmailServerSession(...args)
});

SERVICES.publish('confirm', confirmAction);
document.addEventListener('click', event => {
    const choice = event.target.closest?.('[data-confirm-result]');
    if (!choice)
        return;
    event.preventDefault();
    event.stopPropagation();
    settleConfirmDialog(choice.dataset.confirmResult === 'confirm');
}, { capture: true, passive: false });
const PRESERVED_LOCAL_KEYS_ON_CACHE_RESET = Object.freeze([STORAGE.settings]);
let cacheResetInProgress = false;
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
    weatherArt,
    sendDeviceNotification
});

const startApplication = () => { init().catch(error => { console.error('BOOT_ERROR', error); recoverRootViewAfterBootFailure(error); setLoader(false); }); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startApplication, { once: true });
else queueMicrotask(startApplication);
