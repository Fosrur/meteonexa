let uiTooltipTarget = null;
let uiTooltipHideTimer = null;
function ensureUiTooltip() {
    let tooltip = document.getElementById('ui-tooltip');
    if (tooltip)
        return tooltip;
    tooltip = document.createElement('div');
    tooltip.id = 'ui-tooltip';
    tooltip.className = 'ui-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.appendChild(tooltip);
    return tooltip;
}
function normalizeNativeTooltips(root = document) {
    const elements = [];
    if (root instanceof Element && root.hasAttribute('title'))
        elements.push(root);
    if (root?.querySelectorAll)
        elements.push(...root.querySelectorAll('[title]'));
    elements.forEach(element => {
        const value = element.getAttribute('title') || '';
        if (value && !element.hasAttribute('data-tooltip'))
            element.setAttribute('data-tooltip', value);
        element.removeAttribute('title');
    });
}
function positionUiTooltip(target, tooltip) {
    if (!target?.isConnected || tooltip.hidden)
        return;
    const rect = target.getBoundingClientRect();
    const gap = 10;
    tooltip.style.left = '0px';
    tooltip.style.top = '0px';
    const tipRect = tooltip.getBoundingClientRect();
    let left = rect.left + rect.width / 2 - tipRect.width / 2;
    left = Math.max(10, Math.min(left, innerWidth - tipRect.width - 10));
    let top = rect.bottom + gap;
    let placement = 'bottom';
    if (top + tipRect.height > innerHeight - 10) {
        top = rect.top - tipRect.height - gap;
        placement = 'top';
    }
    top = Math.max(10, Math.min(top, innerHeight - tipRect.height - 10));
    tooltip.dataset.placement = placement;
    tooltip.style.left = `${Math.round(left)}px`;
    tooltip.style.top = `${Math.round(top)}px`;
}
function showUiTooltip(target) {
    const source = target?.closest?.('[data-tooltip]');
    const text = source?.dataset.tooltip?.trim();
    if (!source || !text || source.matches(":disabled,[aria-disabled=\"true\"]"))
        return;
    clearTimeout(uiTooltipHideTimer);
    const tooltip = ensureUiTooltip();
    uiTooltipTarget = source;
    tooltip.textContent = text;
    tooltip.hidden = false;
    requestAnimationFrame(() => positionUiTooltip(source, tooltip));
}
function hideUiTooltip({ immediate = false } = {}) {
    clearTimeout(uiTooltipHideTimer);
    const close = () => {
        const tooltip = document.getElementById('ui-tooltip');
        if (tooltip)
            tooltip.hidden = true;
        uiTooltipTarget = null;
    };
    if (immediate)
        close();
    else
        uiTooltipHideTimer = setTimeout(close, 70);
}
function initializeUiTooltips() {
    normalizeNativeTooltips(document);
    document.addEventListener('pointerover', event => {
        if (event.pointerType === 'touch')
            return;
        const target = event.target.closest?.('[data-tooltip]');
        if (target && !target.contains(event.relatedTarget))
            showUiTooltip(target);
    });
    document.addEventListener('pointerout', event => {
        const target = event.target.closest?.('[data-tooltip]');
        if (target && !target.contains(event.relatedTarget))
            hideUiTooltip();
    });
    document.addEventListener('focusin', event => {
        const target = event.target.closest?.('[data-tooltip]');
        if (target)
            showUiTooltip(target);
    });
    document.addEventListener('focusout', event => {
        if (event.target.closest?.('[data-tooltip]'))
            hideUiTooltip();
    });
    document.addEventListener('pointerdown', () => hideUiTooltip({ immediate: true }), true);
    document.addEventListener('scroll', () => hideUiTooltip({ immediate: true }), true);
    addEventListener('resize', () => {
        const tooltip = document.getElementById('ui-tooltip');
        if (uiTooltipTarget && tooltip && !tooltip.hidden)
            positionUiTooltip(uiTooltipTarget, tooltip);
    });
    new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            if (mutation.type === 'attributes' && mutation.attributeName === 'title')
                normalizeNativeTooltips(mutation.target);
            mutation.addedNodes.forEach(node => normalizeNativeTooltips(node));
        });
    }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['title'] });
}
export const tooltipsService = Object.freeze({
    ensureUiTooltip,
    normalizeNativeTooltips,
    positionUiTooltip,
    showUiTooltip,
    hideUiTooltip,
    initializeUiTooltips
});

export function installTooltips(host = globalThis, services = host?.MeteoNexaServices) {
    if (!host || typeof host !== 'object') throw new Error('METEONEXA_TOOLTIPS_HOST_INVALID');
    const existing = services?.get?.('tooltips');
    if (existing) return existing;
    if (!services?.publish) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    return services.publish('tooltips', tooltipsService);
}
