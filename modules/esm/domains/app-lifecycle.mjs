export const serviceNames = Object.freeze(['appLifecycle']);
export const dependencies = Object.freeze(['security']);

function factory(window, deps, provided) {
    void deps;
    provided.appLifecycle = Object.freeze({
        create(context) {
            const {
                state, $, syncNotificationButton, updateLiveClocks, synchronizeRemotePreferences, reconcileRemoteDeviceRevocation,
                refreshWeatherOnForeground, loadSevereWeatherMonitor, ensureRadar, loadIntelligence, reconcileRootView,
                weatherNeedsForegroundRefresh, synchronizeLocalState, updateNetworkStatus, debounce, detectDevice,
                hideChartTooltip, sidebarIsDrawer, setMobileSidebarOpen, setSidebarCollapsed, drawHomeChart, drawTrendCharts,
                drawAllDetailCharts, drawHistoryChart, renderRadarMap, scheduleThresholdsPanelFollow, resizeWeatherFX,
                openSearch, setWelcomeLanguageMenu, applyThemePreference, renderAll, updateWeatherAtmosphere,
                saveRemotePreferences, CACHE_SENTINEL, STORAGE, SESSION_FLAGS, apiRequest, hasUsableLocation, showWelcome
            } = context;
            if (!state || typeof $ !== 'function') throw new Error('METEONEXA_APP_LIFECYCLE_CONTEXT_INVALID');
            function bindGlobalLifecycleEvents() {
        document.addEventListener("visibilitychange", () => {
            if (document.hidden || !state.bootComplete)
                return;
            updateLiveClocks();
            syncNotificationButton();
            synchronizeRemotePreferences();
            void reconcileRemoteDeviceRevocation();
            refreshWeatherOnForeground().catch(error => console.warn('WEATHER_FOREGROUND_REFRESH_FAILED', error));
            loadSevereWeatherMonitor({ force: Date.now() - Number(state.severeWeather.fetchedAt || 0) > 90 * 1000 }).catch(()=>{});
            if (state.currentPage === "radar")
                ensureRadar(true, { silent: true });
            if (state.currentPage === "intelligence")
                loadIntelligence({ force: false, silent: true });
        });
        addEventListener('pageshow', event => {
            if (!state.bootComplete)
                return;
            syncNotificationButton();
            updateLiveClocks();
            synchronizeRemotePreferences();
            void reconcileRemoteDeviceRevocation({ force: event.persisted === true });
            // The initial pageshow belongs to the same bootstrap and must not start a
            // second app-entry cycle. BFCache restores are reconciled without forcing
            // weather unless the cached dataset is actually stale.
            if (event.persisted)
                reconcileRootView({ refresh: weatherNeedsForegroundRefresh(), waitForWeather: false });
        });
        addEventListener('focus', () => {
            if (!state.bootComplete)
                return;
            updateLiveClocks();
            synchronizeRemotePreferences();
            void reconcileRemoteDeviceRevocation();
            refreshWeatherOnForeground().catch(error => console.warn('WEATHER_FOREGROUND_REFRESH_FAILED', error));
            if (state.currentPage === "intelligence")
                loadIntelligence({ force: false, silent: true });
        });
        addEventListener('storage', synchronizeLocalState);
        addEventListener('online', updateNetworkStatus);
        addEventListener('offline', updateNetworkStatus);
        addEventListener('resize', debounce(() => {
            detectDevice();
            hideChartTooltip();
            if (sidebarIsDrawer()) {
                const app = $('#weather-app');
                app?.classList.remove('sidebar-collapsed');
                app?.style.removeProperty('grid-template-columns');
                $('#sidebar')?.style.removeProperty('width');
            }
            else {
                setMobileSidebarOpen(false);
                setSidebarCollapsed($('#weather-app')?.classList.contains('sidebar-collapsed') || false, { persist: false });
            }
            drawHomeChart();
            drawTrendCharts();
            if (state.currentPage === 'details')
                drawAllDetailCharts();
            if (state.currentPage === 'history' && state.history.data)
                drawHistoryChart();
            if (state.radar.map)
                renderRadarMap();
            scheduleThresholdsPanelFollow();
            resizeWeatherFX();
        }, 180));
        addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !$('#weather-app').hidden) {
                event.preventDefault();
                const input = $('#command-city-search');
                if (input && getComputedStyle($('.topbar-center')).display !== 'none') {
                    input.focus();
                    input.select();
                }
                else
                    openSearch('switch');
            }
            if (event.key === 'Escape') {
                setMobileSidebarOpen(false);
                setWelcomeLanguageMenu(false);
            }
        });
        matchMedia('(prefers-color-scheme: light)').addEventListener?.('change', () => {
            if (state.settings.theme !== 'system')
                return;
            applyThemePreference('system', { persist: true, notify: true });
            if (state.weather) {
                renderAll();
                updateWeatherAtmosphere(true);
            }
            void saveRemotePreferences();
        });
            }

            async function readCacheSentinel() {
                if (!('caches' in window)) return null;
                try {
                    const cache = await caches.open(CACHE_SENTINEL.cacheName);
                    return Boolean(await cache.match(CACHE_SENTINEL.requestUrl));
                } catch { return null; }
            }
            async function writeCacheSentinel() {
                if (!('caches' in window)) return false;
                try {
                    const cache = await caches.open(CACHE_SENTINEL.cacheName);
                    await cache.put(CACHE_SENTINEL.requestUrl, new Response('ok', { headers: { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' } }));
                    localStorage.setItem(CACHE_SENTINEL.localMarker, '1');
                    return true;
                } catch { return false; }
            }
            async function detectExternalCacheEvictionAndForceLogin() {
                let expected = false;
                try { expected = localStorage.getItem(CACHE_SENTINEL.localMarker) === '1'; } catch { }
                const sentinel = await readCacheSentinel();
                if (!expected) {
                    await writeCacheSentinel();
                    return false;
                }
                if (sentinel !== false) return false;
                // Safari and some mobile browsers can clear Cache Storage / PWA assets while
                // retaining cookies and localStorage. Treat that split state as an explicit
                // authentication boundary: revoke the server session before rebuilding cache.
                try {
                    await apiRequest('api/auth/logout.php', { logout: true, revokeTrustedDevice: true });
                } catch { }
                state.session = null;
                state.authServerVerified = false;
                state.diagnosticsAllowed = false;
                try { localStorage.removeItem(STORAGE.session); } catch { }
                try {
                    sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
                    sessionStorage.setItem(SESSION_FLAGS.cacheReset, '1');
                } catch { }
                await writeCacheSentinel();
                return true;
            }
            function enforceAuthenticationStorageCoherence() {
                const freshDeviceCredential = deps.security?.freshCredential === true;
                if (state.session?.type !== 'email' || !freshDeviceCredential) return false;
                // A remembered email session without the browser device credential can only
                // happen after site-data/cache cleanup or partial browser storage eviction.
                // Never leave the UI in a half-authenticated state: force a clean login and
                // let auth/status.php revoke the orphaned HttpOnly server session.
                state.session = null;
                state.authServerVerified = false;
                state.diagnosticsAllowed = false;
                try { localStorage.removeItem(STORAGE.session); } catch { }
                try { sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1'); } catch { }
                return true;
            }
            function recoverRootViewAfterBootFailure(error) {
                console.error('BOOT_RECOVERY', error);
                try {
                    state.authServerVerified = false;
                    state.diagnosticsAllowed = false;
                    if (!state.session || deps.security?.freshCredential === true) {
                        state.session = null;
                        try { localStorage.removeItem(STORAGE.session); } catch { }
                        showWelcome('auth-view');
                    } else if (hasUsableLocation()) {
                        const welcome = $('#welcome');
                        const app = $('#weather-app');
                        if (welcome) welcome.hidden = true;
                        if (app) app.hidden = false;
                    } else {
                        showWelcome('location-view');
                    }
                } catch {
                    const welcome = document.getElementById('welcome');
                    const app = document.getElementById('weather-app');
                    if (app) app.hidden = true;
                    if (welcome) welcome.hidden = false;
                }
            }

            return Object.freeze({ bindGlobalLifecycleEvents, readCacheSentinel, writeCacheSentinel, detectExternalCacheEvictionAndForceLogin, enforceAuthenticationStorageCoherence, recoverRootViewAfterBootFailure });
        }
    });
}

export function install(services) {
    return services.installModule({ provides: serviceNames, dependencies, factory });
}
