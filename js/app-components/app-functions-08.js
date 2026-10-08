'use strict';
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
    cleanUrl.searchParams.set(CACHE_RESET_QUERY, String(Date.now()));
    const logoutTarget = `${cleanUrl.pathname}${cleanUrl.search}` || '/';
    let receiptSent = false;
    let receiptSessionRevoked = false;
    let receiptMaskedEmail = '';
    let receiptError = null;

    try {
        await withLoader(meteonexaText('settings.cache.progress.title'), meteonexaText('settings.cache.receipt.progress.copy'), async () => {
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
            await clearBrowserApplicationState({ localAlreadyCleared: true });

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
            forceWeather: wasHidden
        });
    }
    showWelcome(state.session ? 'location-view' : 'auth-view');
    return Promise.resolve();
}
