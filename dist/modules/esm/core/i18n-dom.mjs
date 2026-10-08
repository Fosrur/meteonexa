function createI18nDom({ state, t, canonicalize, resetCatalog }) {
    let textSources = new WeakMap();
    let attributeSources = new WeakMap();
    const internalTextChanges = new WeakSet();
    let observer = null;
    const attributes = ['placeholder', 'aria-label', 'data-tooltip', 'data-placeholder', 'data-update-label'];

    function canonicalText(stored, visible, catalog) {
        const storedValue = String(stored ?? '');
        const match = storedValue.match(/^(\s*)([\s\S]*?)(\s*)$/);
        const core = match?.[2] || '';
        let canonicalCore = canonicalize(core.trim(), catalog);
        if (canonicalCore === core.trim()) {
            const visibleCore = String(visible ?? '').trim();
            const fromVisible = canonicalize(visibleCore, catalog);
            if (fromVisible !== visibleCore || Object.prototype.hasOwnProperty.call(catalog, fromVisible))
                canonicalCore = fromVisible;
        }
        return `${match?.[1] || ''}${canonicalCore}${match?.[3] || ''}`;
    }

    function rebase(previousCatalog = {}) {
        const nextTextSources = new WeakMap();
        const nextAttributeSources = new WeakMap();
        const walker = document.createTreeWalker(document, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
        let node = walker.currentNode;
        while (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                const stored = textSources.get(node) ?? node.nodeValue ?? '';
                nextTextSources.set(node, canonicalText(stored, node.nodeValue || '', previousCatalog));
            }
            else if (node instanceof Element && !node.closest('svg,symbol')) {
                const previousAttributes = attributeSources.get(node) || {};
                const canonicalAttributes = {};
                attributes.forEach(name => {
                    if (!node.hasAttribute(name) && !(name in previousAttributes))
                        return;
                    const stored = previousAttributes[name] ?? node.getAttribute(name) ?? '';
                    const visible = node.getAttribute(name) ?? '';
                    let canonical = canonicalize(stored, previousCatalog);
                    if (canonical === stored) {
                        const fromVisible = canonicalize(visible, previousCatalog);
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
        textSources = nextTextSources;
        attributeSources = nextAttributeSources;
        resetCatalog();
    }

    function translateTextNode(node, { resetSource = false } = {}) {
        if (!node || node.nodeType !== Node.TEXT_NODE)
            return;
        const parent = node.parentElement;
        if (!parent || parent.closest('script,style,svg,symbol,[data-i18n-skip="true"]'))
            return;
        if (resetSource || !textSources.has(node))
            textSources.set(node, node.nodeValue || '');
        const original = textSources.get(node) || '';
        const match = original.match(/^(\s*)([\s\S]*?)(\s*)$/);
        const core = match?.[2] || '';
        if (!core.trim())
            return;
        const translated = t(core.trim());
        const nextValue = `${match?.[1] || ''}${translated}${match?.[3] || ''}`;
        if (node.nodeValue !== nextValue) {
            internalTextChanges.add(node);
            node.nodeValue = nextValue;
        }
    }

    function translateElementAttributes(element, { resetSource = false } = {}) {
        if (!(element instanceof Element) || element.closest('svg,symbol,[data-i18n-skip="true"]'))
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
            if (element.getAttribute('placeholder') !== translatedPlaceholder)
                element.setAttribute('placeholder', translatedPlaceholder);
        }
        let originals = attributeSources.get(element);
        if (!originals || resetSource) {
            originals = {};
            attributes.forEach(name => {
                if (element.hasAttribute(name))
                    originals[name] = element.getAttribute(name) || '';
            });
            attributeSources.set(element, originals);
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

    function initializeObserver() {
        observer?.disconnect();
        observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'characterData') {
                    const node = mutation.target;
                    if (internalTextChanges.has(node)) {
                        internalTextChanges.delete(node);
                        return;
                    }
                    textSources.set(node, node.nodeValue || '');
                    translateTextNode(node);
                    return;
                }
                if (mutation.type === 'attributes') {
                    attributeSources.delete(mutation.target);
                    translateElementAttributes(mutation.target, { resetSource: true });
                    return;
                }
                mutation.addedNodes.forEach(node => translateDOM(node));
            });
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: attributes
        });
    }

    function prepareCatalogSwap(previousCatalog = {}) {
        const active = Boolean(observer);
        observer?.disconnect();
        rebase(previousCatalog);
        return active;
    }

    function finishCatalogSwap(active) {
        translateDOM(document);
        if (active)
            initializeObserver();
    }

    return Object.freeze({ translateDOM, initializeObserver, prepareCatalogSwap, finishCatalogSwap });
}

export const i18nDomService = Object.freeze({ create: createI18nDom });

export const serviceNames = Object.freeze(['i18nDom']);
export const dependencies = Object.freeze([]);

function factory(window, deps, provided) {
    void window;
    void deps;
    provided.i18nDom = i18nDomService;
}

export function install(services, host = globalThis) {
    return services.installModule({ services, host, provides: serviceNames, dependencies, factory });
}

export function installI18nDom(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_I18N_DOM_HOST_INVALID');
    const existing = services?.get?.('i18nDom');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('i18nDom', i18nDomService);
}
