export function createRuntimeState({
    config,
    storage,
    loadJSON,
    buildChanged = false,
    defaultSettings,
    defaultThresholds,
    defaultNotifications,
    defaultUiVisibility
}) {
    if (!config || !storage || typeof loadJSON !== 'function') throw new Error('RUNTIME_STATE_CONFIG_INVALID');
    const storedSettings = loadJSON(storage.settings, {});
    delete storedSettings.themeAutoMigrated;
    if (buildChanged) {
        storedSettings.refresh = 1;
        storedSettings.radarMode = 'live';
    }
    const runtimeCatalog = globalThis.MeteoNexaI18n?.state?.catalog || {};
    const runtimeLanguages = globalThis.MeteoNexaI18n?.state?.supportedLanguages || [];
    const configuredDefaultLocation = {
        ...config.DEFAULT_LOCATION,
        // On a cold build the translation catalogue may still be loading.
        // Never persist translation keys as real place names.
        name: globalThis.meteonexaText?.(config.DEFAULT_LOCATION.nameKey) || config.DEFAULT_LOCATION.name || '',
        admin1: globalThis.meteonexaText?.(config.DEFAULT_LOCATION.admin1Key) || config.DEFAULT_LOCATION.admin1 || '',
        country: globalThis.meteonexaText?.(config.DEFAULT_LOCATION.countryKey) || config.DEFAULT_LOCATION.country || ''
    };
    const storedLocation = loadJSON(storage.location, null);
    const storedWeatherSnapshot = loadJSON(storage.weather, null);
    // A restored forecast is a cached snapshot even if it was live when saved.
    const startupWeather = storedWeatherSnapshot && typeof storedWeatherSnapshot === 'object' && !Array.isArray(storedWeatherSnapshot)
        ? { ...storedWeatherSnapshot, source: storedWeatherSnapshot.source === 'preview' ? 'preview' : 'cache', cacheReason: 'startup' }
        : null;

    return {
        location: {
            ...configuredDefaultLocation,
            ...(storedLocation && typeof storedLocation === 'object' && !Array.isArray(storedLocation) ? storedLocation : {})
        },
        session: loadJSON(storage.session, null),
        authServerVerified: false,
        diagnosticsAllowed: false,
        authRequiredHandledAt: 0,
        weather: startupWeather,
        air: loadJSON(storage.air, null),
        officialAlerts: null,
        officialAlertsFetchedAt: 0,
        officialAlertsLocationKey: '',
        officialAlertsRequest: null,
        favorites: loadJSON(storage.favorites, []),
        recent: loadJSON(storage.recent, []),
        settings: { ...defaultSettings, ...storedSettings },
        thresholds: { ...defaultThresholds, ...loadJSON(storage.thresholds, {}) },
        privacyNotice: loadJSON(storage.privacyNotice, null),
        notifications: { ...defaultNotifications, ...loadJSON(storage.notifications, {}), lastSent: { ...(loadJSON(storage.notifications, {})?.lastSent || {}) } },
        alertReads: loadJSON(storage.alertReads, {}),
        alertWeatherUnreadCount: 0,
        notificationInboxUnread: 0,
        notificationInboxLoaded: false,
        translations: { ...runtimeCatalog },
        supportedLanguages: runtimeLanguages.length ? [...runtimeLanguages] : ['it', 'en', 'fr', 'es', 'de'].map(code => ({ code, label: code.toUpperCase() })),
        preferencesReady: false,
        preferencesUpdatedAt: '',
        translationsUpdatedAt: '',
        preferenceSyncTimer: null,
        preferenceSyncRequest: null,
        preferenceLastCheckedAt: 0,
        preferenceLocalMutationAt: 0,
        preferencesPendingSync: Boolean(loadJSON(storage.preferencePending, false)),
        uiVisibility: Object.fromEntries(Object.entries(defaultUiVisibility).map(([key, value]) => [key, { ...value }])),
        uiVisibilityReady: false,
        clockTimer: null,
        syncChannel: null,
        selectedHourIndex: null,
        selectedDayIndex: null,
        history: { data: null, start: '', end: '', request: null, locationKey: '', rangeKey: '' },
        intelligence: {
            models: [], request: null, fetchedAt: 0, locationKey: '',
            previousSnapshot: null, currentSnapshot: loadJSON(storage.intelligenceSnapshot, null),
            accuracy: loadJSON(storage.modelWeights, null), ensemble: null
        },
        forecastFusion: { hourly: [], request: null, fetchedAt: 0, locationKey: '', modelsAvailable: 0, freshModelsAvailable: 0, modelsExpected: 0, degraded: true, mode: 'unavailable' },
        severeWeather: { events: [], request: null, fetchedAt: 0, locationKey: '', authoritative: false, degraded: true, timer: null },
        currentPage: 'home',
        searchMode: 'switch',
        loaderDepth: 0,
        deferredInstallPrompt: null,
        refreshTimer: null,
        chartResizeTimer: null,
        weatherRequest: null,
        bootComplete: false,
        lastForegroundRefreshAt: 0,
        logoutInProgress: false,
        radar: {
            map: null,
            frames: [],
            liveFrames: [],
            forecastFrames: [],
            forecastGrid: [],
            host: '',
            mode: 'live',
            index: 0,
            timer: null,
            refreshTimer: null,
            speed: 720,
            zoom: 7,
            center: null,
            opacity: .72,
            requestNonce: Date.now(),
            motion: loadJSON(storage.radarMotion, null),
            motionRequest: null,
            drag: null,
            initialized: false,
            loaded: false,
            loading: false,
            loadingPromise: null,
            liveAvailable: false,
            forecastAvailable: false,
            renderToken: 0,
            failedTiles: 0,
            baseData: null,
            baseLoading: null,
            adminData: { regions: null, metros: null },
            adminLoading: null,
            baseScanPhase: 0,
            vectorMap: null,
            vectorMapReady: false,
            vectorMapFailed: false,
            vectorMapInitializing: false,
            vectorCameraKey: '',
            vectorSize: { width: 0, height: 0 },
            vectorRepaintTimer: null,
            vectorRecoveryTimer: null,
            vectorRecoveryAttempts: 0,
            vectorFallbackTimer: null,
            vectorRadarKey: '',
            vectorRadarToken: 0,
            vectorRadarFailed: false,
            vectorRadarRetryAt: 0,
            vectorRadarRevealTimer: null,
            vectorMoveRaf: null,
            forecastRenderRaf: null,
            renderRaf: null,
            renderTimer: null,
            zoomRenderTimer: null,
            tileRetryTimer: null,
            cameraPreviewRaf: null,
            wheelAccumulator: 0,
            wheelTimer: null
        }
    };
}

export const runtimeStateService = Object.freeze({ create: createRuntimeState });

export function installRuntimeState(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_RUNTIME_STATE_HOST_INVALID');
    const existing = services?.get?.('runtimeState');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('runtimeState', runtimeStateService);
}
