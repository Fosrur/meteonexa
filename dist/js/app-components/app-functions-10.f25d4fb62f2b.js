'use strict';
async function init() {
    const bootDeadline = setTimeout(() => {
        console.warn('BOOT_SLOW_PENDING_AUTH_RECONCILIATION');
        state.loaderDepth = 0;
        setLoader(false);
    }, 12000);
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
        if (state.session?.type === 'email' && Object.prototype.hasOwnProperty.call(state.session, 'email')) {
            state.session = { type: 'email', name: String(state.session.name || '').slice(0, 120), verified: state.session.verified === true, at: Number(state.session.at) || Date.now() };
            persistSessionSafely();
        }
        if (state.session?.type === 'email')
            await reconcileEmailServerSession();
        else
            reconcileEmailServerSession().catch(() => false);
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
        document.documentElement.classList.remove('app-boot-pending');
        state.loaderDepth = 0;
        setLoader(false);
    }
}
