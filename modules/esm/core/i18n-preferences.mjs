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
    let i18nTextSources = new WeakMap();
    let i18nAttributeSources = new WeakMap();
    const i18nInternalTextChanges = new WeakSet();
    let i18nObserver = null;
    function browserLanguage() {
        return String(navigator.languages?.[0] || navigator.language || 'it-IT');
    }
    function browserTheme() {
        return matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    function appLocale() {
        return ({ it: 'it-IT', en: 'en-US', fr: 'fr-FR', es: 'es-ES', de: 'de-DE' })[state.settings.language] || 'it-IT';
    }
    let i18nPatternCatalogSource = null;
    let i18nPatternCatalog = [];
    function translationPatternCatalog() {
        if (i18nPatternCatalogSource === state.translations)
            return i18nPatternCatalog;
        i18nPatternCatalogSource = state.translations;
        const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        i18nPatternCatalog = Object.entries(state.translations || {}).flatMap(([source, translation]) => {
            const tokens = [...source.matchAll(/\$?\{([a-zA-Z0-9_]+)\}/g)];
            if (!tokens.length)
                return [];
            const names = [];
            let cursor = 0;
            let pattern = '^';
            tokens.forEach(token => {
                pattern += escapeRegExp(source.slice(cursor, token.index));
                pattern += '(.+?)';
                names.push(token[1]);
                cursor = Number(token.index) + token[0].length;
            });
            pattern += `${escapeRegExp(source.slice(cursor))}$`;
            try {
                const literalLength = source.replace(/\$?\{[a-zA-Z0-9_]+\}/g, '').length;
                return [{ regex: new RegExp(pattern, 'u'), names, translation, literalLength }];
            }
            catch {
                return [];
            }
        }).sort((first, second) => second.literalLength - first.literalLength || second.names.length - first.names.length);
        return i18nPatternCatalog;
    }
    const i18nReverseCatalogCache = new WeakMap();
    function buildReverseTranslationCatalog(catalog = {}) {
        if (!catalog || typeof catalog !== 'object')
            return { exact: new Map(), patterns: [] };
        const cached = i18nReverseCatalogCache.get(catalog);
        if (cached)
            return cached;
        const exact = new Map();
        const patterns = [];
        const escapeRegExp = value => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const placeholderPattern = /\$?\{([a-zA-Z0-9_]+)\}/g;
        Object.entries(catalog).forEach(([source, translation]) => {
            const translated = String(translation ?? '');
            if (!translated)
                return;
            if (!exact.has(translated))
                exact.set(translated, source);
            const tokens = [...translated.matchAll(placeholderPattern)];
            if (!tokens.length)
                return;
            const names = [];
            let cursor = 0;
            let pattern = '^';
            tokens.forEach(token => {
                pattern += escapeRegExp(translated.slice(cursor, token.index));
                pattern += '(.+?)';
                names.push(token[1]);
                cursor = Number(token.index) + token[0].length;
            });
            pattern += `${escapeRegExp(translated.slice(cursor))}$`;
            try {
                const literalLength = translated.replace(placeholderPattern, '').length;
                patterns.push({ regex: new RegExp(pattern, 'u'), names, source, literalLength });
            }
            catch { }
        });
        patterns.sort((first, second) => second.literalLength - first.literalLength || second.names.length - first.names.length);
        const result = { exact, patterns };
        i18nReverseCatalogCache.set(catalog, result);
        return result;
    }
    function canonicalizeI18nValue(value, catalog = {}) {
        const text = String(value ?? '');
        if (!text)
            return text;
        if (Object.prototype.hasOwnProperty.call(catalog, text))
            return text;
        const reverse = buildReverseTranslationCatalog(catalog);
        const exact = reverse.exact.get(text);
        if (exact !== undefined)
            return exact;
        for (const entry of reverse.patterns) {
            const match = text.match(entry.regex);
            if (!match)
                continue;
            const replacements = {};
            entry.names.forEach((name, index) => {
                const captured = match[index + 1];
                replacements[name] = reverse.exact.get(captured) ?? captured;
            });
            let canonical = entry.source;
            Object.entries(replacements).forEach(([name, replacement]) => {
                canonical = canonical
                    .replaceAll(`{${name}}`, String(replacement))
                    .replaceAll(`\${${name}}`, String(replacement));
            });
            return canonical;
        }
        return text;
    }
    function rebaseI18nSources(previousCatalog = {}) {
        const nextTextSources = new WeakMap();
        const nextAttributeSources = new WeakMap();
        const attributes = ['placeholder', 'aria-label', 'data-tooltip', 'data-placeholder', 'data-update-label'];
        const canonicalText = (stored, visible) => {
            const storedValue = String(stored ?? '');
            const storedMatch = storedValue.match(/^(\s*)([\s\S]*?)(\s*)$/);
            const storedCore = storedMatch?.[2] || '';
            let canonicalCore = canonicalizeI18nValue(storedCore.trim(), previousCatalog);
            if (canonicalCore === storedCore.trim()) {
                const visibleCore = String(visible ?? '').trim();
                const fromVisible = canonicalizeI18nValue(visibleCore, previousCatalog);
                if (fromVisible !== visibleCore || Object.prototype.hasOwnProperty.call(previousCatalog, fromVisible))
                    canonicalCore = fromVisible;
            }
            return `${storedMatch?.[1] || ''}${canonicalCore}${storedMatch?.[3] || ''}`;
        };
        const walker = document.createTreeWalker(document, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        let node = walker.currentNode;
        while (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                const stored = i18nTextSources.get(node) ?? node.nodeValue ?? '';
                nextTextSources.set(node, canonicalText(stored, node.nodeValue || ''));
            }
            else if (node instanceof Element && !node.closest('svg,symbol')) {
                const previousAttributes = i18nAttributeSources.get(node) || {};
                const canonicalAttributes = {};
                attributes.forEach(name => {
                    if (!node.hasAttribute(name) && !(name in previousAttributes))
                        return;
                    const stored = previousAttributes[name] ?? node.getAttribute(name) ?? '';
                    const visible = node.getAttribute(name) ?? '';
                    let canonical = canonicalizeI18nValue(stored, previousCatalog);
                    if (canonical === stored) {
                        const fromVisible = canonicalizeI18nValue(visible, previousCatalog);
                        if (fromVisible !== visible || Object.prototype.hasOwnProperty.call(previousCatalog, fromVisible))
                            canonical = fromVisible;
                    }
                    canonicalAttributes[name] = canonical;
                });
                if (Object.keys(canonicalAttributes).length)
                    nextAttributeSources.set(node, canonicalAttributes);
            }
            node = walker.nextNode();
        }
        i18nTextSources = nextTextSources;
        i18nAttributeSources = nextAttributeSources;
        i18nPatternCatalogSource = null;
        i18nPatternCatalog = [];
    }
    function prepareI18nCatalogSwap(previousCatalog = {}) {
        const observerWasActive = Boolean(i18nObserver);
        i18nObserver?.disconnect();
        rebaseI18nSources(previousCatalog);
        return observerWasActive;
    }
    function finishI18nCatalogSwap(observerWasActive) {
        translateDOM(document);
        if (observerWasActive)
            initializeI18nObserver();
    }
    function t(source, params = {}) {
        const key = String(source ?? '');
        let value = state.translations?.[key] ?? globalThis.MeteoNexaI18n?.state?.catalog?.[key];
        const replacements = { ...params };
        if (value === undefined && key) {
            for (const entry of translationPatternCatalog()) {
                const match = key.match(entry.regex);
                if (!match)
                    continue;
                value = entry.translation;
                entry.names.forEach((name, index) => { replacements[name] = match[index + 1]; });
                break;
            }
        }
        // Never expose implementation keys (ui.x, intelq.x, code.x, ...) in the
        // visible application while a catalog is loading or after a missing-key
        // regression. Preserve true literal text passed through t().
        if (value === undefined)
            value = /^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/i.test(key) ? '' : key;
        Object.entries(replacements).forEach(([name, replacement]) => {
            const replacementText = String(replacement);
            const localizedReplacement = state.translations?.[replacementText] ?? replacementText;
            value = value
                .replaceAll(`{${name}}`, localizedReplacement)
                .replaceAll(`\${${name}}`, localizedReplacement);
        });
        return value;
    }
    // Keep one authoritative translator on the main application. The boot i18n
    // runtime remains available for standalone pages, while dynamic widgets loaded
    // after app.js always follow the language currently selected in app state.
    globalThis.meteonexaText = (key, params = {}) => t(key, params);
    function translateTextNode(node, { resetSource = false } = {}) {
        if (!node || node.nodeType !== Node.TEXT_NODE)
            return;
        const parent = node.parentElement;
        if (!parent || parent.closest('script,style,svg,symbol,[data-i18n-skip=\"true\"]'))
            return;
        if (resetSource || !i18nTextSources.has(node))
            i18nTextSources.set(node, node.nodeValue || '');
        const original = i18nTextSources.get(node) || '';
        const match = original.match(/^(\s*)([\s\S]*?)(\s*)$/);
        const core = match?.[2] || '';
        if (!core.trim())
            return;
        const translated = t(core.trim());
        const nextValue = `${match?.[1] || ''}${translated}${match?.[3] || ''}`;
        if (node.nodeValue !== nextValue) {
            i18nInternalTextChanges.add(node);
            node.nodeValue = nextValue;
        }
    }
    function translateElementAttributes(element, { resetSource = false } = {}) {
        if (!(element instanceof Element) || element.closest('svg,symbol,[data-i18n-skip=\"true\"]'))
            return;
        const keyedText = element.getAttribute('data-i18n-key');
        const dynamicText = element.getAttribute('data-i18n-dynamic') === 'true';
        if (keyedText && (!dynamicText || !element.textContent.trim())) {
            const translatedText = t(keyedText);
            if (element.textContent !== translatedText)
                element.textContent = translatedText;
        }
        const keyedPlaceholder = element.getAttribute('data-i18n-placeholder');
        if (keyedPlaceholder) {
            const translatedPlaceholder = t(keyedPlaceholder);
            if (element.getAttribute('placeholder') !== translatedPlaceholder) element.setAttribute('placeholder', translatedPlaceholder);
        }
        const attributes = ['placeholder', 'aria-label', 'data-tooltip', 'data-placeholder', 'data-update-label'];
        let originals = i18nAttributeSources.get(element);
        if (!originals || resetSource) {
            originals = {};
            attributes.forEach(name => {
                if (element.hasAttribute(name))
                    originals[name] = element.getAttribute(name) || '';
            });
            i18nAttributeSources.set(element, originals);
        }
        Object.entries(originals).forEach(([name, source]) => {
            const translated = t(source);
            if (element.getAttribute(name) !== translated)
                element.setAttribute(name, translated);
        });
    }
    function translateDOM(root = document) {
        if (!root)
            return;
        if (root.nodeType === Node.TEXT_NODE)
            translateTextNode(root);
        if (root instanceof Element)
            translateElementAttributes(root);
        const owner = root.nodeType === Node.DOCUMENT_NODE ? root : root.ownerDocument;
        const walker = owner.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        let node = walker.currentNode;
        while (node) {
            if (node.nodeType === Node.TEXT_NODE)
                translateTextNode(node);
            else if (node instanceof Element)
                translateElementAttributes(node);
            node = walker.nextNode();
        }
    }
    function initializeI18nObserver() {
        i18nObserver?.disconnect();
        i18nObserver = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'characterData') {
                    const node = mutation.target;
                    if (i18nInternalTextChanges.has(node)) {
                        i18nInternalTextChanges.delete(node);
                        return;
                    }
                    i18nTextSources.set(node, node.nodeValue || '');
                    translateTextNode(node);
                    return;
                }
                if (mutation.type === 'attributes') {
                    i18nAttributeSources.delete(mutation.target);
                    translateElementAttributes(mutation.target, { resetSource: true });
                    return;
                }
                mutation.addedNodes.forEach(node => translateDOM(node));
            });
        });
        i18nObserver.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['placeholder', 'aria-label', 'data-tooltip', 'data-placeholder', 'data-update-label']
        });
    }
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
            const observerWasActive = catalogChanged ? prepareI18nCatalogSwap(previousCatalog) : false;
            assignPreferenceResult(result);
            persistLocalSettings();
            markPreferencesPending(false);
            rebuildLanguageOptions();
            if (catalogChanged)
                finishI18nCatalogSwap(observerWasActive);
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
        // focus and visibilitychange are frequently emitted as a pair by browsers.
        // Avoid performing two preference round-trips for the same foreground event.
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
                // Do not let an older foreground-sync response undo a theme selected while this request was in flight.
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
                const observerWasActive = catalogChanged ? prepareI18nCatalogSwap(previousCatalog) : false;
                assignPreferenceResult(result, { preserveCatalog: !catalogChanged });
                rebuildLanguageOptions();
                if (catalogChanged)
                    finishI18nCatalogSwap(observerWasActive);
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
        browserLanguage, browserTheme, appLocale, t, translateDOM, initializeI18nObserver,
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
