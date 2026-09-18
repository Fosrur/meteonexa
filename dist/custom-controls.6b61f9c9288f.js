'use strict';
(() => {
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const BUILD = '20.1';
    const q = (selector, root = document) => root.querySelector(selector);
    const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const number = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;

    function translateNode(node) {
        if (!node) return;
        const key = node.getAttribute('data-i18n-key');
        if (key && typeof window.meteonexaText === 'function') node.textContent = window.meteonexaText(key);
    }

    function defineValueProperty(node, initial, onSet) {
        let current = String(initial ?? '');
        const setter = value => {
            current = String(value ?? '');
            node.dataset.value = current;
            node.setAttribute('value', current);
            onSet?.(current);
        };
        try {
            Object.defineProperty(node, 'value', {
                configurable: true,
                enumerable: true,
                get: () => current,
                set: setter
            });
        } catch { }
        setter(current);
    }

    function enhanceSelect(trigger) {
        if (!trigger || trigger.dataset.meteoEnhanced === '1') return;
        trigger.dataset.meteoEnhanced = '1';
        const root = trigger.closest('[data-meteo-select-control]');
        const menu = root?.querySelector('.meteo-select-menu');
        const label = trigger.querySelector('[data-meteo-select-value]');
        const options = () => qa('[data-meteo-option]', menu || root || document);
        const close = (restore = false) => {
            if (!menu) return;
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            root?.classList.remove('open');
            if (restore) trigger.focus({ preventScroll: true });
        };
        const sync = value => {
            const option = options().find(item => String(item.dataset.meteoOption) === String(value)) || options()[0];
            options().forEach(item => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
            if (!label || !option) return;
            const source = option.querySelector('[data-i18n-key]') || option.querySelector('span');
            const key = source?.getAttribute('data-i18n-key');
            if (key) {
                label.setAttribute('data-i18n-key', key);
                translateNode(label);
            } else {
                label.removeAttribute('data-i18n-key');
                label.textContent = source?.textContent?.trim() || option.textContent.trim();
            }
        };
        defineValueProperty(trigger, trigger.getAttribute('value') || trigger.dataset.value || options()[0]?.dataset.meteoOption || '', sync);
        trigger.addEventListener('click', event => {
            event.preventDefault();
            if (!menu || trigger.disabled) return;
            const opening = menu.hidden;
            document.dispatchEvent(new CustomEvent('meteonexa:custom-select-open', { detail: { trigger } }));
            menu.hidden = !opening;
            trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
            root?.classList.toggle('open', opening);
            if (opening) {
                const selected = options().find(item => item.getAttribute('aria-selected') === 'true') || options()[0];
                setTimeout(() => selected?.focus({ preventScroll: true }), 0);
            }
        });
        menu?.addEventListener('click', event => {
            const option = event.target.closest('[data-meteo-option]');
            if (!option) return;
            event.preventDefault();
            trigger.value = option.dataset.meteoOption || '';
            close(true);
            trigger.dispatchEvent(new Event('change', { bubbles: true }));
        });
        menu?.addEventListener('keydown', event => {
            const items = options();
            if (!items.length) return;
            const current = Math.max(0, items.indexOf(document.activeElement));
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                items[(current + direction + items.length) % items.length]?.focus();
            } else if (event.key === 'Escape') {
                event.preventDefault(); close(true);
            } else if (event.key === 'Enter' || event.key === ' ') {
                const option = document.activeElement?.closest?.('[data-meteo-option]');
                if (option) { event.preventDefault(); option.click(); }
            }
        });
        trigger.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault(); trigger.click();
            } else if (event.key === 'Escape') close(false);
        });
    }

    function enhanceSwitch(button) {
        if (!button || button.dataset.meteoEnhanced === '1') return;
        button.dataset.meteoEnhanced = '1';
        let checked = button.getAttribute('aria-checked') === 'true';
        const sync = value => {
            checked = Boolean(value);
            button.setAttribute('aria-checked', checked ? 'true' : 'false');
            button.setAttribute('aria-pressed', checked ? 'true' : 'false');
            button.classList.toggle('active', checked);
        };
        try {
            Object.defineProperty(button, 'checked', {
                configurable: true,
                enumerable: true,
                get: () => checked,
                set: sync
            });
        } catch { }
        sync(checked);
        button.addEventListener('click', event => {
            event.preventDefault();
            if (button.disabled) return;
            button.checked = !button.checked;
            button.dispatchEvent(new Event('change', { bubbles: true }));
        });
        button.addEventListener('keydown', event => {
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault(); button.click();
            }
        });
    }

    function decimalPlaces(step) {
        const value = String(step);
        return value.includes('.') ? value.split('.')[1].length : 0;
    }

    function enhanceRange(slider) {
        if (!slider || slider.dataset.meteoEnhanced === '1') return;
        slider.dataset.meteoEnhanced = '1';
        let min = number(slider.dataset.min, 0);
        let max = number(slider.dataset.max, 100);
        let step = Math.max(0.0001, number(slider.dataset.step, 1));
        let value = number(slider.dataset.value, min);
        const fill = q('.meteo-range-fill', slider);
        const thumb = q('.meteo-range-thumb', slider);
        const sync = raw => {
            const precision = decimalPlaces(step);
            const quantized = min + Math.round((number(raw, min) - min) / step) * step;
            value = Number(clamp(quantized, min, max).toFixed(precision));
            slider.dataset.value = String(value);
            slider.setAttribute('aria-valuenow', String(value));
            const pct = max > min ? ((value - min) / (max - min)) * 100 : 0;
            slider.style.setProperty('--meteo-range-pct', `${clamp(pct, 0, 100)}%`);
            if (fill) fill.style.width = `${clamp(pct, 0, 100)}%`;
            if (thumb) thumb.style.left = `${clamp(pct, 0, 100)}%`;
        };
        const defineNumberProp = (name, getter, setter) => {
            try { Object.defineProperty(slider, name, { configurable: true, enumerable: true, get: getter, set: setter }); } catch { }
        };
        defineNumberProp('value', () => String(value), raw => sync(raw));
        defineNumberProp('min', () => String(min), raw => { min = number(raw, min); slider.dataset.min = String(min); slider.setAttribute('aria-valuemin', String(min)); sync(value); });
        defineNumberProp('max', () => String(max), raw => { max = number(raw, max); slider.dataset.max = String(max); slider.setAttribute('aria-valuemax', String(max)); sync(value); });
        defineNumberProp('step', () => String(step), raw => { step = Math.max(0.0001, number(raw, step)); slider.dataset.step = String(step); sync(value); });
        slider.setAttribute('aria-valuemin', String(min)); slider.setAttribute('aria-valuemax', String(max)); sync(value);

        const setFromPointer = event => {
            const track = q('.meteo-range-track', slider) || slider;
            const rect = track.getBoundingClientRect();
            if (!rect.width) return;
            const ratio = clamp((event.clientX - rect.left) / rect.width, 0, 1);
            slider.value = min + ratio * (max - min);
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        };
        let dragging = false;
        slider.addEventListener('pointerdown', event => {
            if (slider.getAttribute('aria-disabled') === 'true') return;
            dragging = true;
            slider.setPointerCapture?.(event.pointerId);
            setFromPointer(event);
        });
        slider.addEventListener('pointermove', event => { if (dragging) setFromPointer(event); });
        const finish = event => {
            if (!dragging) return;
            dragging = false;
            try { slider.releasePointerCapture?.(event.pointerId); } catch { }
            slider.dispatchEvent(new Event('change', { bubbles: true }));
        };
        slider.addEventListener('pointerup', finish); slider.addEventListener('pointercancel', finish);
        slider.addEventListener('keydown', event => {
            let next = value;
            if (event.key === 'ArrowRight' || event.key === 'ArrowUp') next += step;
            else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') next -= step;
            else if (event.key === 'Home') next = min;
            else if (event.key === 'End') next = max;
            else return;
            event.preventDefault(); slider.value = next;
            slider.dispatchEvent(new Event('input', { bubbles: true }));
            slider.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function enhance(root = document) {
        qa('[data-meteo-select]', root).forEach(enhanceSelect);
        qa('[data-meteo-switch]', root).forEach(enhanceSwitch);
        qa('[data-meteo-range]', root).forEach(enhanceRange);
    }

    document.addEventListener('meteonexa:custom-select-open', event => {
        qa('[data-meteo-select][aria-expanded="true"]').forEach(trigger => {
            if (trigger === event.detail?.trigger) return;
            const root = trigger.closest('[data-meteo-select-control]');
            const menu = root?.querySelector('.meteo-select-menu');
            if (menu) menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false'); root?.classList.remove('open');
        });
    });
    document.addEventListener('click', event => {
        if (event.target.closest?.('[data-meteo-select-control]')) return;
        qa('[data-meteo-select][aria-expanded="true"]').forEach(trigger => {
            const root = trigger.closest('[data-meteo-select-control]');
            const menu = root?.querySelector('.meteo-select-menu');
            if (menu) menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false'); root?.classList.remove('open');
        });
    });

    const observer = new MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) {
            if (!(node instanceof Element)) continue;
            if (node.matches?.('[data-meteo-select],[data-meteo-switch],[data-meteo-range]')) enhance(node.parentElement || node);
            else if (node.querySelector?.('[data-meteo-select],[data-meteo-switch],[data-meteo-range]')) enhance(node);
        }
    });

    function boot() {
        enhance();
        observer.observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
    SERVICES.publish('controls', Object.freeze({ build: BUILD, enhance }));
})();
