const PROVIDES = Object.freeze(['uiVisibility', 'guestAccess', 'navigation']);
export const dependencies = Object.freeze(['advanced', 'intelligence', 'suite', 'core']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const core = deps.core;
            // Publish stable service facades synchronously. The service registry validates
            // every declared service as soon as install() returns, while the concrete UI
            // controller is created later by js/app.js. Keeping these objects stable lets the
            // controller hydrate them after js/app.js loads without breaking the ESM bootstrap.
            const uiVisibilityService = {};
            const guestAccessService = {};
            provided.uiVisibility = uiVisibilityService;
            provided.guestAccess = guestAccessService;

            const allowed = new Set(['home','radar','favorites','details','history','intelligence','advanced','bug-report','route','devices','alerts']);
            const request = (page, options = {}) => {
                const target = String(page || '').trim();
                if (!allowed.has(target)) return false;
                core?.patch?.('navigation', { pendingPage: target }, 'navigation/request');
                core?.events?.emit?.('navigation-request', { page: target, options: { ...options } });
                return true;
            };
            const committed = page => core?.patch?.('navigation', { currentPage: page, pendingPage: null }, 'navigation/committed');
            const failed = (page, error = '') => core?.patch?.('navigation', { pendingPage: null, error: String(error || '') }, 'navigation/failed');
        
            const createController = deps => {
                const {
                    state, DEFAULT_UI_VISIBILITY, $, $$, clearNavigationLoadingArtifacts,
                    isAuthenticatedSession, syncPrivacyContextCopy, apiRequest, showToast, meteonexaText,
                    openNotificationCenter, setMobileSidebarOpen, renderFavorites, ensureRadar,
                    drawAllDetailCharts, refreshBugReportContext, loadHistory, renderIntelligence,
                    loadIntelligence, scheduleThresholdsPanelFollow, withLoader
                } = deps;
        
                const GUEST_PROTECTED_PAGES = new Set(['history', 'route', 'devices']);
                const PAGE_UI_FEATURES = Object.freeze({
                    home: 'page.home', radar: 'page.radar', favorites: 'page.favorites', details: 'page.details',
                    intelligence: 'page.intelligence', advanced: 'page.advanced', route: 'page.route',
                    history: 'page.history', devices: 'page.devices', 'bug-report': 'page.feedback'
                });
                const UI_VISIBILITY_TARGETS = Object.freeze({
                    'feature.assistant': ['#assistant-center-button'],
                    'feature.notifications': ['#settings-notifications'],
                    'feature.integrations': ['#settings-integrations']
                });
                let guestAccessNoticeShown = false;
                function isGuestSession() {
                    if (!isAuthenticatedSession()) return true;
                    // Fail closed while online until the HttpOnly server session + device proof
                    // have been reconciled. Offline we may retain the locally remembered email
                    // mode, but private network operations remain disabled independently.
                    if (navigator.onLine !== false && !state.authServerVerified) return true;
                    return false;
                }
                function uiVisibilityMode() {
                    return isGuestSession() ? 'guest' : 'authenticated';
                }
                function isUiFeatureVisible(featureKey, { mode = uiVisibilityMode() } = {}) {
                    // The local assistant is a public offline-capable feature. AI remains auth-only.
                    if (featureKey === 'feature.assistant' && mode === 'guest') return true;
                    if (featureKey === 'feature.qa' && mode === 'authenticated' && state.diagnosticsAllowed !== true) return false;
                    const configured = state.uiVisibility?.[featureKey];
                    const fallback = DEFAULT_UI_VISIBILITY[featureKey];
                    const value = configured && typeof configured === 'object' ? configured[mode] : undefined;
                    if (typeof value === 'boolean') return value;
                    if (fallback && typeof fallback[mode] === 'boolean') return fallback[mode];
                    // Unknown features are hidden for guests and visible to authenticated users.
                    return mode === 'authenticated';
                }
                function firstVisiblePage() {
                    return ['home', 'radar', 'favorites', 'details', 'intelligence', 'advanced', 'bug-report', 'route', 'history', 'devices']
                        .find(page => isUiFeatureVisible(PAGE_UI_FEATURES[page]) && !(isGuestSession() && GUEST_PROTECTED_PAGES.has(page))) || 'home';
                }
                function applyUiVisibility() {
                    Object.entries(PAGE_UI_FEATURES).forEach(([page, featureKey]) => {
                        const visible = isUiFeatureVisible(featureKey) && !(isGuestSession() && GUEST_PROTECTED_PAGES.has(page));
                        $$(`[data-page="${page}"]`).forEach(node => { node.hidden = !visible; });
                        const section = $(`#page-${page}`);
                        if (section) section.hidden = !visible;
                    });
                    Object.entries(UI_VISIBILITY_TARGETS).forEach(([featureKey, selectors]) => {
                        const visible = isUiFeatureVisible(featureKey);
                        selectors.forEach(selector => $$(selector).forEach(node => { node.hidden = !visible; }));
                    });
                    $$('[data-ui-feature]').forEach(node => {
                        const featureKey = String(node.dataset.uiFeature || '').trim();
                        if (!featureKey) return;
                        node.hidden = !isUiFeatureVisible(featureKey);
                    });
                    // Private controls/panels are never advertised to guest sessions. This is
                    // a presentation boundary only; protected endpoints still enforce auth.
                    // Keeping it here (in addition to js/privacy-context.js) prevents first-paint
                    // flashes while auth/session reconciliation is still in progress.
                    const guestMode = isGuestSession();
                    $$('[data-auth-only]').forEach(node => { node.hidden = guestMode; });
                    $$('[data-guest-only]').forEach(node => { node.hidden = !guestMode; });
                    // Suite-owned dynamic panels (notably Radar archive) are created/removed at
                    // runtime, so resync them whenever guest/auth visibility is recalculated.
                    // The backend remains authoritative; this hook only keeps the UI deterministic.
                    try { deps.suite?.syncVisibility?.(); } catch { }
                    const mobileNav = $('.mobile-nav');
                    if (mobileNav) {
                        const guest = isGuestSession();
                        $$('.mobile-nav-link[data-page]', mobileNav).forEach(node => {
                            const audience = String(node.dataset.mobileAudience || 'all');
                            let visible = audience !== 'authenticated' || !guest;
                            if (audience === 'guest') visible = guest;
                            const featureKey = PAGE_UI_FEATURES[String(node.dataset.page || '')];
                            if (featureKey) visible = visible && isUiFeatureVisible(featureKey) && !(guest && GUEST_PROTECTED_PAGES.has(String(node.dataset.page || '')));
                            node.hidden = !visible;
                        });
                        const visibleItems = $$('.mobile-nav-link[data-page]', mobileNav).filter(node => !node.hidden).length;
                        mobileNav.style.setProperty('--mobile-nav-columns', String(Math.max(1, visibleItems)));
                        mobileNav.dataset.audience = guest ? 'guest' : 'authenticated';
                    }
                    Object.assign(uiVisibilityService, {
                        isVisible: featureKey => isUiFeatureVisible(String(featureKey || '')),
                        apply: () => applyUiVisibility(),
                        mode: () => uiVisibilityMode()
                    });
                }
                async function loadUiVisibilityConfig() {
                    try {
                        const result = await apiRequest('api/ui-config.php', null, { timeout: 12000, notifyAuthRequired: false });
                        if (result?.features && typeof result.features === 'object') {
                            Object.entries(result.features).forEach(([key, value]) => {
                                if (!value || typeof value !== 'object') return;
                                state.uiVisibility[key] = {
                                    guest: value.guest === true,
                                    authenticated: value.authenticated === true
                                };
                            });
                        }
                        state.uiVisibilityReady = true;
                    }
                    catch (error) {
                        // Conservative built-in defaults remain active if the public UI config
                        // cannot be loaded. Never fail open for guest-only protected features.
                        state.uiVisibilityReady = false;
                        console.warn('UI_VISIBILITY_CONFIG_UNAVAILABLE', error?.code || error?.message || error);
                    }
                    applyUiVisibility();
                }
                function showGuestAccessNotice({ once = false } = {}) {
                    if (once && guestAccessNoticeShown)
                        return;
                    guestAccessNoticeShown = true;
                    showToast(meteonexaText('guest.login.required.title'), meteonexaText('guest.login.required.copy'), 'info', 7000);
                }
                function applyGuestAccessUI() {
                    const guest = isGuestSession();
                    document.body.classList.toggle('guest-mode', guest);
                    applyUiVisibility();
                    try { syncPrivacyContextCopy(); } catch { }
                    if (guest && 'serviceWorker' in navigator) {
                        navigator.serviceWorker.ready.then(registration => {
                            registration.active?.postMessage({ type: 'METEONEXA_CLEAR_BACKGROUND' });
                        }).catch(() => null);
                    }
                    Object.assign(guestAccessService, {
                        isGuest: () => isGuestSession(),
                        notify: () => showGuestAccessNotice(),
                        canUse: page => !isGuestSession() || !GUEST_PROTECTED_PAGES.has(String(page || ''))
                    });
                }
                async function goToPage(page, options = {}) {
                    clearNavigationLoadingArtifacts();
                    if (page === 'alerts') {
                        openNotificationCenter();
                        return;
                    }
                    if (!['home', "radar", 'favorites', 'details', 'history', "intelligence", 'advanced', 'bug-report', 'route', 'devices'].includes(page))
                        return;
                    if (isGuestSession() && GUEST_PROTECTED_PAGES.has(page)) {
                        showGuestAccessNotice();
                        page = firstVisiblePage();
                        options = { ...options, loader: false, instant: true };
                    }
                    const pageFeature = PAGE_UI_FEATURES[page];
                    if (pageFeature && !isUiFeatureVisible(pageFeature)) {
                        page = firstVisiblePage();
                        options = { ...options, loader: false, instant: true };
                    }
                    const previousPage = state.currentPage;
                    const pageChanged = previousPage !== page;
                    const perform = async () => {
                        state.currentPage = page;
                        provided.navigation?.committed?.(page);
                        if (pageChanged || options.metricEntry === true) {
                            const metric = { home:'page_home', radar:'page_radar', intelligence:'page_intelligence' }[page];
                        }
                        document.body.dataset.page = page;
                        $$('.page').forEach(section => section.classList.toggle('active-page', section.dataset.pageName === page));
                        $$('.nav-link[data-page], .mobile-nav-link[data-page], button.panel-link[data-page], .forecast-history-button[data-page]').forEach(button => button.classList.toggle('active', button.dataset.page === page));
                        setMobileSidebarOpen(false);
                        if (pageChanged && options.preserveScroll !== true) {
                            window.scrollTo({ top: 0, left: 0, behavior: options.instant ? 'auto' : (state.settings.reduceMotion ? 'auto' : 'smooth') });
                        }
                        if (page === 'favorites')
                            await renderFavorites();
                        if (page === "radar") {
                            // Page navigation already owns the global loader. Radar is bounded so
                            // an upstream tile/metadata timeout cannot leave the sidebar spinning.
                            try {
                                await Promise.race([
                                    ensureRadar(false, { silent: true }),
                                    new Promise(resolve => window.setTimeout(resolve, 12000))
                                ]);
                            } catch (error) { console.warn('RADAR_PAGE_LOAD_FAILED', error); }
                        }
                        if (page === 'details')
                            requestAnimationFrame(drawAllDetailCharts);
                        if (page === 'bug-report')
                            refreshBugReportContext();
                        if (page === 'history')
                            await loadHistory({ force: false, silent: options.loader !== false });
                        if (page === "intelligence") {
                            // Render immediately with the already available weather data; the six
                            // provider feeds update the page in the background instead of blocking navigation.
                            renderIntelligence();
                            loadIntelligence({ force: false, silent: true }).catch(error => console.warn('INTELLIGENCE_BACKGROUND_LOAD_FAILED', error));
                            // The Smart/demo panel used to self-start from a DOMContentLoaded timer.
                            // On a cold guest boot that timer could run before the final location/i18n
                            // hydration and then never run again until the user pressed Refresh.
                            // Route entry is authoritative: at this point session + location are final.
                            const smartRefresh = deps.intelligence?.refresh;
                            if (typeof smartRefresh === 'function')
                                Promise.resolve(smartRefresh(false)).catch(error => console.warn('SMART_INTELLIGENCE_BACKGROUND_LOAD_FAILED', error));
                        }
                        await deps.advanced?.onPage?.(page, options);
                        requestAnimationFrame(scheduleThresholdsPanelFollow);
                        const nextHash = page === 'home' ? '' : `#${page}`;
                        if (location.hash !== nextHash) {
                            try {
                                history.replaceState(history.state, '', `${location.pathname}${location.search}${nextHash}`);
                            }
                            catch { }
                        }
                    };
                    if (options.loader === false || !pageChanged)
                        await perform();
                    else
                        await withLoader("" + meteonexaText("navigation.perform.opening_section"), meteonexaText("navigation.perform.preparing_value", { section: page === "radar" ? "" + meteonexaText("navigation.perform.weather_radar") : page === 'favorites' ? "" + meteonexaText("navigation.perform.favorite_locations") : page === 'alerts' ? "" + meteonexaText("navigation.perform.alerts") : page === 'details' ? "" + meteonexaText("navigation.perform.forecast_analysis") : page === 'history' ? meteonexaText("navigation.perform.weather_history") : page === "intelligence" ? "" + meteonexaText("navigation.perform.smart_forecast") : page === 'advanced' ? meteonexaText("navigation.perform.advanced_forecast") : page === 'route' ? meteonexaText("navigation.perform.route_weather") : page === 'assistant' ? meteonexaText("navigation.perform.weather_assistant") : page === 'devices' ? meteonexaText('page.devices.section') : "" + meteonexaText("navigation.perform.overview") }), perform, 330);
                }
                return Object.freeze({
                    isGuestSession, uiVisibilityMode, isUiFeatureVisible, firstVisiblePage, applyUiVisibility,
                    loadUiVisibilityConfig, showGuestAccessNotice, applyGuestAccessUI, goToPage
                });
            };
        
            provided.navigation = Object.freeze({
                request, committed, failed,
                current: () => core?.getState?.().navigation?.currentPage || 'home',
                createController
            });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
