export function create(deps) {
    const { state, storage: STORAGE, saveJSON, enhancedSelects, $, $$ } = deps;
    const apiRequest = (...args) => deps.apiRequest(...args);
    const requestSafeBackgroundSync = (...args) => deps.requestSafeBackgroundSync(...args);
    const showToast = (...args) => deps.showToast(...args);
    const initializeEnhancedSelects = (...args) => deps.initializeEnhancedSelects(...args);
    const syncEnhancedSelect = (...args) => deps.syncEnhancedSelect(...args);
    const applySettings = (...args) => deps.applySettings(...args);
    const withLoader = (...args) => deps.withLoader(...args);
    const updateProfileUI = (...args) => deps.updateProfileUI(...args);
    const syncFavoriteUI = (...args) => deps.syncFavoriteUI(...args);
    const syncNotificationButton = (...args) => deps.syncNotificationButton(...args);
    const renderRecentSearches = (...args) => deps.renderRecentSearches(...args);
    const hasUsableLocation = (...args) => deps.hasUsableLocation(...args);
    const updateSelectedLocationUI = (...args) => deps.updateSelectedLocationUI(...args);
    const setAuthStep = (...args) => deps.setAuthStep(...args);
    const refreshAuthServerStatus = (...args) => deps.refreshAuthServerStatus(...args);
    const escapeHTML = (...args) => deps.escapeHTML(...args);

    function browserLanguage() {
        return String(navigator.languages?.[0] || navigator.language || 'it-IT');
    }

    function browserTheme() {
        return matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    function appLocale() {
        return ({ it: 'it-IT', en: 'en-US', fr: 'fr-FR', es: 'es-ES', de: 'de-DE' })[state.settings.language] || 'it-IT';
    }

    const services = deps.services;
    const catalog = services.require('i18nCatalog').create(state);
    const t = catalog.t;
    globalThis.meteonexaText = (key, params = {}) => t(key, params);
    const dom = services.require('i18nDom').create({ state, t, canonicalize: catalog.canonicalize, resetCatalog: catalog.reset });
    const translateDOM = dom.translateDOM;
    const initializeObserver = dom.initializeObserver;
    const prepareCatalogSwap = dom.prepareCatalogSwap;
    const finishCatalogSwap = dom.finishCatalogSwap;
    function persistLocalSettings() {
        saveJSON(STORAGE.settings, { ...state.settings });
    }
    function markPreferencesPending(pending) {
        state.preferencesPendingSync = Boolean(pending);
        saveJSON(STORAGE.preferencePending, state.preferencesPendingSync);
    }
    function preferenceRequestParams({ includeVersions = false } = {}) {
        const values = {
            browserLanguage: browserLanguage(),
            browserTheme: browserTheme(),
            _: String(Date.now())
        };
        if (includeVersions) {
            if (state.preferencesUpdatedAt)
                values.preferenceUpdatedAt = state.preferencesUpdatedAt;
            if (state.translationsUpdatedAt)
                values.translationsUpdatedAt = state.translationsUpdatedAt;
        }
        return new URLSearchParams(values);
    }
    function assignPreferenceResult(result, { preserveCatalog = false } = {}) {
        state.settings.language = result.language || state.settings.language || 'it';
        state.settings.theme = result.theme || state.settings.theme || 'system';
        if (!preserveCatalog || (result.translations && typeof result.translations === 'object')) {
            state.translations = result.translations || state.translations || {};
        }
        state.supportedLanguages = result.supportedLanguages || state.supportedLanguages;
        state.preferencesUpdatedAt = result.preferenceUpdatedAt || result.updatedAt || state.preferencesUpdatedAt;
        state.translationsUpdatedAt = result.translationsUpdatedAt || state.translationsUpdatedAt;
        state.preferencesReady = true;
        document.documentElement.lang = state.settings.language;
    }
    async function loadRemotePreferences() {
        const runtime = globalThis.MeteoNexaI18n;
        const consumeRuntime = () => {
            if (!runtime?.state) return;
            state.settings.language = runtime.state.language || state.settings.language;
            if (!state.preferenceLocalMutationAt)
                state.settings.theme = runtime.state.theme || state.settings.theme;
            if (runtime.state.catalog && Object.keys(runtime.state.catalog).length) state.translations = { ...runtime.state.catalog };
            if (Array.isArray(runtime.state.supportedLanguages) && runtime.state.supportedLanguages.length) state.supportedLanguages = [...runtime.state.supportedLanguages];
            state.translationsUpdatedAt = runtime.state.translationsUpdatedAt || state.translationsUpdatedAt;
            state.preferencesReady = Boolean(Object.keys(state.translations).length);
            persistLocalSettings();
        };
        consumeRuntime();
        if (runtime?.ready && runtime.state?.ready !== true) {
            try { await Promise.race([runtime.ready, new Promise(resolve => setTimeout(resolve, 7800))]); }
            catch (error) { console.warn('I18N_BOOT_UNAVAILABLE', error); }
            consumeRuntime();
        } else if (!Object.keys(state.translations).length) {
            try { await Promise.race([runtime?.ready || Promise.resolve(), new Promise(resolve => setTimeout(resolve, 7800))]); }
            catch (error) { console.warn('I18N_BOOT_UNAVAILABLE', error); }
            consumeRuntime();
        } else runtime?.ready?.then(consumeRuntime).catch(() => null);
        document.documentElement.lang = state.settings.language;
    }
    function broadcastRealtimeUpdate(type, payload = {}) {
        try {
            state.syncChannel?.postMessage({ type, payload, sentAt: Date.now() });
        }
        catch { }
    }
    async function saveRemotePreferences({ showConfirmation = false } = {}) {
        persistLocalSettings();
        document.documentElement.lang = state.settings.language;
        const previousCatalog = state.translations;
        try {
            const result = await apiRequest('api/preferences.php', {
                language: state.settings.language,
                theme: state.settings.theme,
                browserLanguage: browserLanguage(),
                browserTheme: browserTheme()
            }, { timeout: 12000, notifyAuthRequired: false });
            const catalogChanged = Boolean(result.translations && typeof result.translations === 'object');
            const observerWasActive = catalogChanged ? prepareCatalogSwap(previousCatalog) : false;
            assignPreferenceResult(result);
            persistLocalSettings();
            markPreferencesPending(false);
            rebuildLanguageOptions();
            if (catalogChanged)
                finishCatalogSwap(observerWasActive);
            else
                translateDOM(document);
            broadcastRealtimeUpdate('preferences-updated', { updatedAt: state.preferencesUpdatedAt });
            if (showConfirmation)
                showToast(t("preferences.saveremotepreferences.settings_updated"), t("preferences.language_theme_have_been_applied"), 'success');
            return result;
        }
        catch (error) {
            markPreferencesPending(true);
            requestSafeBackgroundSync().catch(() => null);
            state.preferencesReady = false;
            rebuildLanguageOptions();
            translateDOM(document);
            broadcastRealtimeUpdate('preferences-updated', { localOnly: true, updatedAt: Date.now() });
            if (showConfirmation)
                showToast(t("preferences.saveremotepreferences.settings_updated"), t("preferences.preferences_saved_device_will_synchronized_as_soon_as"), 'success');
            console.warn(t("preferences.preferences_saved_locally_server_sync_postponed"), error);
            return {
                ok: true,
                storage: 'local',
                language: state.settings.language,
                theme: state.settings.theme,
                pendingSync: true
            };
        }
    }
    async function synchronizeRemotePreferences({ force = false } = {}) {
        if (!navigator.onLine || (document.hidden && !force))
            return false;
        if (state.preferenceSyncRequest)
            return state.preferenceSyncRequest;
        const now = Date.now();
        if (!force && now - Number(state.preferenceLastCheckedAt || 0) < 5000)
            return false;
        state.preferenceLastCheckedAt = now;
        const syncLocalMutationAt = state.preferenceLocalMutationAt;
        state.preferenceSyncRequest = (async () => {
            try {
                if (state.preferencesPendingSync) {
                    await saveRemotePreferences({ showConfirmation: false });
                    return !state.preferencesPendingSync;
                }
                const beforeLanguage = state.settings.language;
                const beforeTheme = state.settings.theme;
                const beforePreferenceVersion = state.preferencesUpdatedAt;
                const beforeTranslationVersion = state.translationsUpdatedAt;
                const result = await apiRequest(`api/preferences.php?${preferenceRequestParams({ includeVersions: true }).toString()}`, null, { timeout: 12000, notifyAuthRequired: false });
                if (state.preferenceLocalMutationAt !== syncLocalMutationAt)
                    return false;
                const catalogChanged = Boolean(result.translations && typeof result.translations === 'object')
                    || (result.translationsUpdatedAt && result.translationsUpdatedAt !== beforeTranslationVersion);
                const preferenceChanged = (result.language && result.language !== beforeLanguage)
                    || (result.theme && result.theme !== beforeTheme)
                    || (result.preferenceUpdatedAt && result.preferenceUpdatedAt !== beforePreferenceVersion);
                if (!preferenceChanged && !catalogChanged)
                    return false;
                const previousCatalog = state.translations;
                const observerWasActive = catalogChanged ? prepareCatalogSwap(previousCatalog) : false;
                assignPreferenceResult(result, { preserveCatalog: !catalogChanged });
                rebuildLanguageOptions();
                if (catalogChanged)
                    finishCatalogSwap(observerWasActive);
                else
                    translateDOM(document);
                if (beforeLanguage !== state.settings.language || catalogChanged) {
                    enhancedSelects.forEach(instance => instance.destroy?.());
                    enhancedSelects.clear();
                    initializeEnhancedSelects();
                }
                applySettings({ rerender: Boolean(state.weather) });
                return true;
            }
            catch (error) {
                if (force)
                    console.warn(t("preferences.synchronizeremotepreferences.preference_sync_unavailable"), error);
                return false;
            }
            finally {
                state.preferenceSyncRequest = null;
            }
        })();
        return state.preferenceSyncRequest;
    }
    function rebuildLanguageOptions() {
        const activeValue = state.settings.language;
        $$('[data-language-setting]').forEach(select => {
            select.innerHTML = state.supportedLanguages.map(item => `<option value="${escapeHTML(item.code)}">${escapeHTML(t(item.label))}</option>`).join('');
            select.value = activeValue;
            if (select.id === 'language-setting')
                syncEnhancedSelect('language-setting', activeValue);
        });
        const activeLanguage = state.supportedLanguages.find(item => item.code === activeValue) || state.supportedLanguages[0];
        const currentLabel = $('#welcome-language-current');
        if (currentLabel && activeLanguage)
            currentLabel.textContent = t(activeLanguage.label);
        $$('[data-language-option]').forEach(option => {
            const code = option.dataset.languageOption || '';
            const item = state.supportedLanguages.find(language => language.code === code);
            const label = option.querySelector('strong');
            if (label && item)
                label.textContent = t(item.label);
            const selected = code === activeValue;
            option.classList.toggle('active', selected);
            option.setAttribute('aria-selected', String(selected));
            option.tabIndex = selected ? 0 : -1;
        });
        const settingsCurrent = $('#settings-language-current');
        if (settingsCurrent && activeLanguage)
            settingsCurrent.textContent = t(activeLanguage.label);
        $$('[data-settings-language-option]').forEach(option => {
            const code = option.dataset.settingsLanguageOption || '';
            const item = state.supportedLanguages.find(language => language.code === code);
            const label = option.querySelector('strong');
            if (label && item)
                label.textContent = t(item.label);
            const selected = code === activeValue;
            option.classList.toggle('active', selected);
            option.setAttribute('aria-selected', String(selected));
            option.tabIndex = selected ? 0 : -1;
        });
    }
    function setWelcomeLanguageMenu(open, { focusActive = false, restoreFocus = false } = {}) {
        const picker = $('#welcome-language-picker');
        const button = $('#welcome-language-button');
        const menu = $('#welcome-language-menu');
        if (!picker || !button || !menu)
            return;
        const shouldOpen = Boolean(open);
        picker.classList.toggle('open', shouldOpen);
        button.setAttribute('aria-expanded', String(shouldOpen));
        menu.hidden = !shouldOpen;
        if (shouldOpen && focusActive) {
            requestAnimationFrame(() => menu.querySelector('[aria-selected="true"]')?.focus({ preventScroll: true }));
        }
        else if (!shouldOpen && restoreFocus) {
            button.focus({ preventScroll: true });
        }
    }
    function setSettingsLanguageMenu(open, { focusActive = false, restoreFocus = false } = {}) {
        const picker = $('#settings-language-picker');
        const button = $('#settings-language-button');
        const menu = $('#settings-language-menu');
        if (!picker || !button || !menu)
            return;
        const shouldOpen = Boolean(open);
        picker.classList.toggle('open', shouldOpen);
        button.setAttribute('aria-expanded', String(shouldOpen));
        menu.hidden = !shouldOpen;
        if (shouldOpen && focusActive) {
            requestAnimationFrame(() => menu.querySelector('[aria-selected="true"]')?.focus({ preventScroll: true }));
        }
        else if (!shouldOpen && restoreFocus) {
            button.focus({ preventScroll: true });
        }
    }
    async function changeApplicationLanguage(nextLanguage) {
        const next = String(nextLanguage || '').toLowerCase();
        if (!state.supportedLanguages.some(item => item.code === next))
            return;
        const previous = state.settings.language;
        if (next === previous) {
            rebuildLanguageOptions();
            return;
        }
        await withLoader(t("preferences.changeapplicationlanguage.changing_language"), t("preferences.changeapplicationlanguage.applying_selected_language"), async () => {
            state.settings.language = next;
            try {
                await saveRemotePreferences({ showConfirmation: false });
                document.documentElement.lang = state.settings.language;
                enhancedSelects.forEach(instance => instance.destroy?.());
                enhancedSelects.clear();
                initializeEnhancedSelects();
                updateProfileUI();
                syncFavoriteUI();
                syncNotificationButton();
                renderRecentSearches();
                if (hasUsableLocation())
                    updateSelectedLocationUI();
                if ($('#auth-dialog')?.open) {
                    setAuthStep($('#auth-form')?.dataset.step === 'verify' ? 'verify' : 'request');
                    refreshAuthServerStatus();
                }
                applySettings();
                translateDOM(document);
                showToast(t("preferences.changeapplicationlanguage.language_updated"), t("preferences.interface_has_been_updated_selected_language"), 'success');
            }
            catch (error) {
                state.settings.language = next;
                persistLocalSettings();
                document.documentElement.lang = next;
                rebuildLanguageOptions();
                applySettings();
                translateDOM(document);
                console.warn(t('log.language.local'), error);
            }
        }, 350);
    }

    return Object.freeze({
        browserLanguage, browserTheme, appLocale, t, translateDOM, initializeI18nObserver: initializeObserver,
        persistLocalSettings, markPreferencesPending, loadRemotePreferences, saveRemotePreferences,
        synchronizeRemotePreferences, rebuildLanguageOptions, setWelcomeLanguageMenu,
        setSettingsLanguageMenu, changeApplicationLanguage
    });
}
export const i18nPreferencesService = Object.freeze({ create });

export function installI18nPreferences(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_I18N_PREFERENCES_HOST_INVALID');
    const existing = services?.get?.('i18nPreferences');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('i18nPreferences', i18nPreferencesService);
}
