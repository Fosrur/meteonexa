function createI18nCatalog(state) {
    let patternSource = null;
    let patternCatalog = [];
    const reverseCache = new WeakMap();

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function reset() {
        patternSource = null;
        patternCatalog = [];
    }

    function patterns() {
        if (patternSource === state.translations)
            return patternCatalog;
        patternSource = state.translations;
        patternCatalog = Object.entries(state.translations || {}).flatMap(([source, translation]) => {
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
        return patternCatalog;
    }

    function reverse(catalog = {}) {
        if (!catalog || typeof catalog !== 'object')
            return { exact: new Map(), patterns: [] };
        const cached = reverseCache.get(catalog);
        if (cached)
            return cached;
        const exact = new Map();
        const reversePatterns = [];
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
                reversePatterns.push({ regex: new RegExp(pattern, 'u'), names, source, literalLength });
            }
            catch { }
        });
        reversePatterns.sort((first, second) => second.literalLength - first.literalLength || second.names.length - first.names.length);
        const result = { exact, patterns: reversePatterns };
        reverseCache.set(catalog, result);
        return result;
    }

    function canonicalize(value, catalog = {}) {
        const text = String(value ?? '');
        if (!text)
            return text;
        if (Object.prototype.hasOwnProperty.call(catalog, text))
            return text;
        const index = reverse(catalog);
        const exact = index.exact.get(text);
        if (exact !== undefined)
            return exact;
        for (const entry of index.patterns) {
            const match = text.match(entry.regex);
            if (!match)
                continue;
            const replacements = {};
            entry.names.forEach((name, position) => {
                const captured = match[position + 1];
                replacements[name] = index.exact.get(captured) ?? captured;
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

    function t(source, params = {}) {
        const key = String(source ?? '');
        let value = state.translations?.[key] ?? globalThis.MeteoNexaI18n?.state?.catalog?.[key];
        const replacements = { ...params };
        if (value === undefined && key) {
            for (const entry of patterns()) {
                const match = key.match(entry.regex);
                if (!match)
                    continue;
                value = entry.translation;
                entry.names.forEach((name, position) => {
                    replacements[name] = match[position + 1];
                });
                break;
            }
        }
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

    return Object.freeze({ t, canonicalize, reset });
}

export const i18nCatalogService = Object.freeze({ create: createI18nCatalog });

export const serviceNames = Object.freeze(['i18nCatalog']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void window;
    void deps;
    provided.i18nCatalog = i18nCatalogService;
}

export function install(services, host = globalThis) {
    return services.installModule({ services, host, provides: serviceNames, dependencies, factory });
}

export function installI18nCatalog(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_I18N_CATALOG_HOST_INVALID');
    const existing = services?.get?.('i18nCatalog');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('i18nCatalog', i18nCatalogService);
}
