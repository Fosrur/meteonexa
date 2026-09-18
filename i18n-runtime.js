'use strict';
(() => {
    const VERSION = '20.1';
    const CACHE_KEY = 'meteonexa_i18n_catalog_v4';
    const STATIC_CATALOG_TOKEN = '350be7bcfd298215';
    const SCRIPT_URL = (() => { try { return new URL(document.currentScript?.src || location.href); } catch { return new URL(location.href); } })();
    const APP_ROOT_URL = (() => {
        const url = new URL(SCRIPT_URL.toString());
        // Fingerprinted assets are served from /dist/. Resolve APIs/cookies
        // against the application root, not against /dist/, otherwise a cold
        // boot requests /dist/api/i18n.php and renders an empty translated UI.
        const marker = '/dist/';
        const index = url.pathname.lastIndexOf(marker);
        if (index >= 0) {
            url.pathname = url.pathname.slice(0, index + 1);
            url.search = '';
            url.hash = '';
            return url;
        }
        return new URL('./', url);
    })();
    const apiUrl = path => new URL(String(path || '').replace(/^\/+/, ''), APP_ROOT_URL).toString();
    const languageCookiePath = (() => { const value = APP_ROOT_URL.pathname || '/'; return value.endsWith('/') ? value : value + '/'; })();
    const SETTINGS_KEY = 'meteonexa_v3_settings';
    const SUPPORTED = ['it', 'en', 'fr', 'es', 'de'];
    const browserLanguage = () => String(navigator.languages?.[0] || navigator.language || 'it-IT');
    const normalizeLanguage = value => {
        const code = String(value || '').toLowerCase().split(/[-_]/)[0];
        return SUPPORTED.includes(code) ? code : 'it';
    };
    const browserTheme = () => matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    const readJSON = (key, fallback) => {
        try { return JSON.parse(localStorage.getItem(key) || '') ?? fallback; }
        catch { return fallback; }
    };
    const writeJSON = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch {} };
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const localSettings = readJSON(SETTINGS_KEY, {});
    const cached = readJSON(CACHE_KEY, {});
    const selectedLanguage = normalizeLanguage(localSettings.language || cached.language || browserLanguage());
    // A translation catalog is data, not executable code: keep using the last
    // compatible catalog across app upgrades so a new Service Worker/build can
    // never expose raw i18n keys while the fresh catalog is being checked.
    const cachedCatalog = cached.catalog && typeof cached.catalog === 'object' ? cached.catalog : {};
    const cachedLanguage = normalizeLanguage(cached.language || selectedLanguage);
    const warmCatalog = cachedLanguage === selectedLanguage ? cachedCatalog : {};
    const state = {
        language: selectedLanguage,
        theme: localSettings.theme || cached.theme || 'system',
        resolvedTheme: cached.resolvedTheme || browserTheme(),
        catalog: warmCatalog,
        supportedLanguages: Array.isArray(cached.supportedLanguages) ? cached.supportedLanguages : [],
        translationsUpdatedAt: cached.translationsUpdatedAt || '',
        cacheBuild: String(cached.version || ''),
        catalogFresh: String(cached.version || '') === VERSION,
        catalogSource: Object.keys(warmCatalog).length ? 'cache' : 'none',
        ready: false
    };
    const withTimeout = async (url, options = {}, timeout = 5200) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, { ...options, signal: controller.signal, cache: options.cache || 'no-store' });
            if (response.status === 304) return { ok: true, notModified: true };
            const raw = (await response.text()).replace(/^\uFEFF/, '').trim();
            if (!response.ok) throw new Error(`HTTP_${response.status}`);
            if (!raw) throw new Error('EMPTY_JSON_RESPONSE');
            const contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (!contentType.includes('application/json') && !raw.startsWith('{') && !raw.startsWith('[')) throw new Error('NON_JSON_RESPONSE');
            try { return JSON.parse(raw); } catch { throw new Error('INVALID_JSON_RESPONSE'); }
        } finally { clearTimeout(timer); }
    };
    const interpolate = (value, params = {}) => {
        let output = String(value ?? '');
        Object.entries(params).forEach(([name, replacement]) => {
            output = output.replaceAll(`{${name}}`, String(replacement)).replaceAll(`\${${name}}`, String(replacement));
        });
        return output;
    };
    const looksLikeTranslationKey = value => /^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/i.test(String(value || ''));
    const tr = (key, params = {}) => {
        const normalizedKey = String(key ?? '');
        const translated = state.catalog?.[normalizedKey];
        const value = translated ?? (looksLikeTranslationKey(normalizedKey) ? '' : normalizedKey);
        return interpolate(value, params);
    };
    const TRANSLATABLE_ATTRIBUTES = Object.freeze({
        'data-i18n-placeholder': 'placeholder',
        'data-i18n-aria-label': 'aria-label',
        'data-i18n-data-tooltip': 'data-tooltip',
        'data-i18n-data-placeholder': 'data-placeholder',
        'data-i18n-data-update-label': 'data-update-label',
        'data-i18n-title': 'title',
        'data-i18n-alt': 'alt',
        'data-i18n-content': 'content'
    });
    const applyElement = element => {
        if (!(element instanceof Element) || element.closest('svg,symbol') || element.matches('script,style,iframe,object,embed')) return;
        const key = element.getAttribute('data-i18n-key');
        const dynamicText = element.getAttribute('data-i18n-dynamic') === 'true';
        if (key && (!dynamicText || !element.textContent.trim())) element.textContent = tr(key);
        Object.entries(TRANSLATABLE_ATTRIBUTES).forEach(([source, target]) => {
            if (element.hasAttribute(source)) element.setAttribute(target, tr(element.getAttribute(source) || ''));
        });
    };
    const applyDocument = (root = document) => {
        if (!root) return;
        if (root instanceof Element) applyElement(root);
        const scope = root.querySelectorAll ? root : document;
        scope.querySelectorAll?.('[data-i18n-key],[data-i18n-placeholder],[data-i18n-aria-label],[data-i18n-data-tooltip],[data-i18n-data-placeholder],[data-i18n-data-update-label],[data-i18n-title],[data-i18n-alt],[data-i18n-content]').forEach(applyElement);
        document.documentElement.lang = state.language;
        document.documentElement.dataset.theme = state.resolvedTheme;
        if (document.body) document.body.dataset.theme = state.resolvedTheme;
    };
    const persist = () => writeJSON(CACHE_KEY, {
        version: VERSION, language: state.language, theme: state.theme, resolvedTheme: state.resolvedTheme,
        catalog: state.catalog, supportedLanguages: state.supportedLanguages,
        translationsUpdatedAt: state.translationsUpdatedAt
    });
    const assignCatalog = (result, source = 'database') => {
        if (result?.language) state.language = normalizeLanguage(result.language);
        try { document.cookie = `meteonexa_language=${encodeURIComponent(state.language)}; Path=${languageCookiePath}; Max-Age=31536000; SameSite=Strict${location.protocol === 'https:' ? '; Secure' : ''}`; } catch {}
        if (result?.translations && typeof result.translations === 'object') state.catalog = result.translations;
        if (Array.isArray(result?.supportedLanguages)) state.supportedLanguages = result.supportedLanguages;
        state.translationsUpdatedAt = result?.translationsUpdatedAt || state.translationsUpdatedAt;
        state.cacheBuild = VERSION;
        state.catalogFresh = true;
        state.catalogSource = source;
        persist();
        applyDocument();
        return result;
    };
    const requestStaticCatalog = async locale => {
        const url = `${apiUrl(`assets/i18n/${locale}.json`)}?v=${STATIC_CATALOG_TOKEN}`;
        return withTimeout(url, { credentials: 'omit', headers: { Accept: 'application/json' }, cache: 'default' }, 3200);
    };
    const requestPrimaryCatalog = async locale => {
        const params = new URLSearchParams({ language: locale, _: String(Date.now()) });
        if (state.translationsUpdatedAt && Object.keys(state.catalog).length && !String(state.translationsUpdatedAt).startsWith('seed:')) {
            params.set('translationsUpdatedAt', state.translationsUpdatedAt);
        }
        return withTimeout(`${apiUrl('api/i18n.php')}?${params}`, {
            credentials: 'same-origin', headers: { Accept: 'application/json' }
        }, 12000);
    };
    const requestSeedCatalog = async locale => {
        const params = new URLSearchParams({ language: locale, v: VERSION });
        return withTimeout(`${apiUrl('api/i18n-fallback.php')}?${params}`, {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'default'
        }, 8000);
    };
    const refreshAuthoritativeCatalog = locale => {
        setTimeout(async () => {
            try {
                const result = await requestPrimaryCatalog(locale);
                if (result?.ok && result.translations && typeof result.translations === 'object') assignCatalog(result, 'database');
            } catch {}
        }, 900);
    };
    const loadCatalog = async (language = state.language, { force = false } = {}) => {
        const locale = normalizeLanguage(language);
        if (!force && locale === state.language && Object.keys(state.catalog).length) {
            applyDocument();
            return { ok: true, cached: true, language: locale };
        }
        let primaryError = null;
        try {
            const result = await requestPrimaryCatalog(locale);
            if (!result?.ok) throw new Error(result?.code || 'CATALOG_EMPTY');
            if (!result.translations) {
                if (!Object.keys(state.catalog).length) throw new Error('CATALOG_EMPTY');
                state.translationsUpdatedAt = result.translationsUpdatedAt || state.translationsUpdatedAt;
                if (Array.isArray(result.supportedLanguages)) state.supportedLanguages = result.supportedLanguages;
                state.catalogSource = state.catalogSource === 'none' ? 'cache' : state.catalogSource;
                persist(); applyDocument();
                return { ...result, cached: true };
            }
            if (typeof result.translations !== 'object') throw new Error(result?.code || 'CATALOG_EMPTY');
            return assignCatalog(result, 'database');
        } catch (error) {
            primaryError = error;
        }
        // First deployment can legitimately spend several seconds applying DB
        // migrations/translation seed. Do not reveal a blank UI: fall back to
        // the packaged, release-versioned catalog which has no DB dependency.
        try {
            const fallback = await requestSeedCatalog(locale);
            if (!fallback?.ok || !fallback.translations || typeof fallback.translations !== 'object') throw new Error(fallback?.code || 'FALLBACK_EMPTY');
            const assigned = assignCatalog(fallback, 'release-seed');
            refreshAuthoritativeCatalog(locale);
            return { ...assigned, degraded: true, primaryError: primaryError?.message || 'I18N_DATABASE_UNAVAILABLE' };
        } catch (fallbackError) {
            throw new Error(fallbackError?.message || primaryError?.message || 'CATALOG_ERROR');
        }
    };
    const syncPreferences = async ({ payload = null } = {}) => {
        const params = new URLSearchParams({ browserLanguage: browserLanguage(), browserTheme: browserTheme(), _: String(Date.now()) });
        if (state.translationsUpdatedAt && !String(state.translationsUpdatedAt).startsWith('seed:')) params.set('translationsUpdatedAt', state.translationsUpdatedAt);
        const result = await withTimeout(`${apiUrl('api/preferences.php')}?${params}`, payload ? {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload)
        } : { credentials: 'same-origin', headers: { Accept: 'application/json' } }, 12000);
        if (!result?.ok) throw new Error(result?.code || 'PREFERENCES_ERROR');
        state.theme = result.theme || state.theme;
        state.resolvedTheme = result.resolvedTheme || (state.theme === 'system' ? browserTheme() : state.theme);
        const preferredLanguage = normalizeLanguage(result.language || state.language);
        if (preferredLanguage !== state.language || !Object.keys(state.catalog).length) await loadCatalog(preferredLanguage, { force: true });
        if (Array.isArray(result.supportedLanguages)) state.supportedLanguages = result.supportedLanguages;
        persist(); applyDocument();
        return result;
    };
    const sync = async ({ force = false, payload = null } = {}) => {
        try {
            if (payload) return await syncPreferences({ payload });
            const result = await loadCatalog(state.language, { force });
            syncPreferences().catch(() => null);
            return result;
        } catch (error) {
            state.resolvedTheme = state.theme === 'system' ? browserTheme() : state.theme;
            applyDocument();
            return { ok: false, code: error?.message || 'CATALOG_ERROR', fallback: true };
        }
    };
    let bootReady = false;
    const releaseBootUi = () => {
        if (bootReady) return;
        bootReady = true;
        const loader = document.getElementById('global-loader');
        loader?.classList.remove('active');
        loader?.setAttribute('aria-hidden', 'true');
        document.body?.classList.remove('operation-loading');
        document.documentElement.classList.remove('fresh-build');
    };
    const markReady = () => {
        if (!Object.keys(state.catalog).length) return false;
        state.ready = true;
        applyDocument();
        document.documentElement.classList.remove('i18n-pending');
        document.documentElement.classList.add('i18n-ready');
        document.dispatchEvent(new CustomEvent('meteonexa:i18n-ready', { detail: { language: state.language, source: state.catalogSource } }));
        return true;
    };
    const ready = (async () => {
        const hasWarmCatalog = Object.keys(state.catalog).length > 0;
        applyDocument();
        // A compatible cached catalog is always good enough for first paint.
        // Refreshing DB preferences/translations happens in background and must
        // never keep login/guest controls hidden on a slow PHP/MySQL worker.
        if (hasWarmCatalog) {
            if (!markReady()) throw new Error('I18N_BOOT_CATALOG_EMPTY');
            sync({ force: true }).catch(() => null);
            return state;
        }
        // Cold install / cleared browser: load the packaged static catalog first.
        // It has no PHP/DB/cookie dependency, so cookie acknowledgement can never
        // gate language rendering. The authoritative DB catalog refreshes later.
        try {
            const local = await requestStaticCatalog(state.language);
            if (local?.ok && local.translations && typeof local.translations === 'object')
                assignCatalog(local, 'static-release');
        } catch {}
        if (Object.keys(state.catalog).length) {
            if (!markReady()) throw new Error('I18N_BOOT_CATALOG_EMPTY');
            sync({ force: true }).catch(() => null);
            return state;
        }
        // Last online fallback for deployments where static assets are unavailable.
        let result = await sync({ force: true });
        if (!result?.ok && !Object.keys(state.catalog).length) {
            await sleep(450);
            result = await sync({ force: true });
        }
        if (!markReady()) throw new Error('I18N_BOOT_CATALOG_EMPTY');
        return state;
    })().catch(async () => {
        // Last-resort retry loop: a temporary web-server/PHP restart must keep
        // the splash visible instead of exposing an icon-only untranslated UI.
        for (let attempt = 0; attempt < 4 && !Object.keys(state.catalog).length; attempt++) {
            await sleep(900 + attempt * 850);
            try { await loadCatalog(state.language, { force: true }); } catch {}
        }
        markReady();
        return state;
    });
    window.MeteoNexaI18n = Object.freeze({ state, tr, apply: applyDocument, sync, ready, normalizeLanguage, browserTheme, loadCatalog });
    window.meteonexaText = tr;
    document.addEventListener('DOMContentLoaded', () => {
        applyDocument();
        const observer = new MutationObserver(mutations => mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
            if (node instanceof Element) applyDocument(node);
        })));
        if (document.body) observer.observe(document.body, { childList: true, subtree: true });
        // The watchdog may release an operation loader, but never changes the
        // i18n-pending state. Blank translated surfaces therefore cannot flash.
        setTimeout(releaseBootUi, 12000);
    });
    document.addEventListener('meteonexa:ready', releaseBootUi, { once: true });
})();
