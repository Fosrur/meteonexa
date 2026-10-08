'use strict';
(() => {
    const CONFIG = window.METEONEXA_CONFIG;
    if (!CONFIG)
        throw new Error('METEONEXA_CONFIG_NOT_LOADED');
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const APP_RUNTIME = SERVICES.require('runtimeApi').get();
    const { showToast, withLoader, loadWeather, syncEnhancedSelect, updateThreshold, appLocale, temperature, t, weatherMeta, weatherArt, sendDeviceNotification } = APP_RUNTIME;
    const state = APP_RUNTIME.getState();
    const SECURITY = SERVICES.get('security');
    if (!SECURITY)
        throw new Error('METEONEXA_SECURITY_NOT_LOADED');
    const {
        BUILD, API, KEYS, q, qa, n, clamp, safe, currentLocale, mean, deviation, localDate, localTime, tempText,
        localizedLocationPart, locationLabel, locationKey, ui, apiMessage, toast, loader, deviceId, isGuest, fetchJson,
        geocodeCity, dateInput, addDays, setDateField, nearestTimeIndex, bearing, directionName, haversine, exportHistoryPdf
    } = SERVICES.require('suiteSupport').create({
        CONFIG, SERVICES, SECURITY, state, appLocale, temperature, t, showToast, withLoader, meteonexaText
    });
    try { localStorage.removeItem(KEYS.assistant); } catch { }
    const suite = {
        modelRows: [], modelFetchedAt: 0, direction: null, environment: null, officialAlerts: null, officialFetchedAt: 0,
        history: null, route: null, routeNavigation: { watchId: null, marker: null, active: false }, assistantContext: {},
        mapLayer: null, routeMap: null, routeMapResizeObserver: null, aiAvailability: null,
        assistantMode: localStorage.getItem(KEYS.assistantMode) === 'local' ? 'local' : 'ai', aiSwitchPending: null,
        refreshTimer: null, snapshotPrevious: null,
        advancedRequest: null, advancedRefreshedAt: 0, advancedLocationKey: '', canonicalConsensus: null
    };
    const weatherIntelligence = SERVICES.require('suiteWeatherIntelligence').create({
        CONFIG, SERVICES, API, KEYS, suite, state, q, qa, n, clamp, safe, currentLocale,
        mean, deviation, localTime, tempText, locationLabel, locationKey, ui, toast, loader,
        deviceId, isGuest, fetchJson, dateInput, nearestTimeIndex, directionName, updateThreshold,
        temperature, weatherMeta, weatherArt, meteonexaText, sendDeviceNotification
    });
    const {
        loadModels, modelConfidence, renderModels, nowcastBase, snapshot, stabilityScore,
        nowcastReliability, saveSnapshot, renderNowcast, loadOfficialAlerts, loadEnvironment,
        loadAlertProfile, applyProfile, updateExtendedOutputs, environmentPeaks, renderExtendedAlerts
    } = weatherIntelligence;
    function removeSuiteMapLayer() {
        SERVICES.require('radarLayers').remove();
        suite.mapLayer = null;
    }
    async function showSuiteMapLayer(type) {
        const result = await SERVICES.require('radarLayers').show(type);
        suite.mapLayer = type;
        return result;
    }
    const historyApi = SERVICES.require('suiteHistory').create({
        CONFIG, suite, state, q, n, clamp, safe, mean, loader, fetchJson, dateInput, addDays, toast, meteonexaText
    });
    const { loadEnhancedHistory, exportHistoryCsv } = historyApi;

    let assistantRuntime = null;
    const routeApi = SERVICES.require('suiteRoute').create({
        CONFIG, SERVICES, API, suite, q, qa, n, clamp, safe, ui, localTime, tempText,
        haversine, bearing, nearestTimeIndex, fetchJson, normalizeLocation, searchCities,
        syncEnhancedSelect, locationLabel, geocodeCity, loader,
        refreshAssistantSuggestions: () => assistantRuntime?.renderAssistantSuggestions?.(),
        toast, weatherArt: (...args) => weatherArt(...args)
    });
    const {
        calculateRouteCore, initializeRouteControls, calculateRouteUI, applyRouteDepartureCandidate,
        startRouteNavigation, stopRouteNavigation, clearRoute
    } = routeApi;
    assistantRuntime = SERVICES.require('suiteAssistant').create({
        API, KEYS, addDays, apiMessage, calculateRouteCore, clamp, currentLocale, environmentPeaks,
        geocodeCity, isGuest, loadEnvironment, loader, localTime, locationLabel, modelConfidence, n, nearestTimeIndex,
        nowcastBase, nowcastReliability, q, qa, safe, snapshot, stabilityScore, state, suite, tempText, toast, ui, temperature, weatherMeta
    });
    const {
        renderAssistantSuggestions, setAssistantProviderStatus, setAssistantMode, openAssistantWithAi,
        openAssistantDialog, closeAssistantDialog, assistantRows, setAssistantBusy, addAssistant,
        resetAssistant, restoreAssistant, askAssistant, answerWithAi
    } = assistantRuntime;

    document.addEventListener('click', async event => {
        const target = event.target;
        const openTrigger = target.closest?.('#assistant-center-button');
        if (openTrigger) {
            event.preventDefault();
            openAssistantDialog();
            return;
        }

        const closeTrigger = target.closest?.('#assistant-close');
        if (closeTrigger) {
            event.preventDefault();
            closeAssistantDialog();
            return;
        }

        const modeButton = target.closest?.('[data-assistant-mode-choice]');
        if (modeButton) {
            event.preventDefault();
            if (!suite.assistantBusy)
                setAssistantMode(modeButton.dataset.assistantModeChoice === 'local' ? 'local' : 'ai', true);
            return;
        }

        const suggestion = target.closest?.('[data-assistant-question]');
        if (suggestion) {
            event.preventDefault();
            await askAssistant(suggestion.dataset.assistantQuestion || '');
            return;
        }

        const clearTrigger = target.closest?.('#assistant-clear');
        if (clearTrigger) {
            event.preventDefault();
            const rows = assistantRows();
            if (!rows.length) {
                toast(ui('assistant.clear.empty.toast.title'), ui('assistant.clear.empty.toast.copy'), 'warning');
                q('#assistant-input')?.focus();
                return;
            }
            resetAssistant();
            toast(ui('assistant.clear.toast.title'), ui('assistant.clear.toast.copy'));
            return;
        }

        const switchButton = target.closest?.('[data-assistant-switch]');
        if (switchButton) {
            event.preventDefault();
            const choice = switchButton.dataset.assistantSwitch;
            const question = String(suite.aiSwitchPending || '').trim();
            switchButton.closest('.assistant-switch')?.remove();
            suite.aiSwitchPending = null;
            if (choice === 'ai' && question) {
                setAssistantMode('ai', true);
                await answerWithAi(question);
                return;
            }
            setAssistantMode('local');
            setAssistantBusy(false);
            addAssistant('assistant', ui('assistant.ai.offer.stay_local'), true, { source: 'local' });
            setTimeout(() => q('#assistant-input')?.focus(), 30);
            return;
        }

        const dialog = q('#assistant-dialog');
        if (dialog && target === dialog)
            closeAssistantDialog();
    }, true);

    document.addEventListener('submit', event => {
        const form = event.target?.closest?.('#assistant-form');
        if (!form)
            return;
        event.preventDefault();
        const input = form.querySelector('#assistant-input');
        const value = String(input?.value || '').trim();
        if (!value) {
            input?.focus();
            return;
        }
        if (input)
            input.value = '';
        Promise.resolve(askAssistant(value)).catch(error => {
            console.warn('ASSISTANT_SUBMIT_FAILED', error);
            toast(ui('assistant.error.generic'), error?.message || ui('assistant.error.generic'), 'warning');
        });
    }, true);

    document.addEventListener('cancel', event => {
        if (event.target?.id !== 'assistant-dialog')
            return;
        event.preventDefault();
        closeAssistantDialog();
    }, true);

    async function configureBackgroundChecks() {
        if (isGuest())
            return;
        if (!('serviceWorker' in navigator))
            return;
        try {
            const registration = await navigator.serviceWorker.ready, profile = loadAlertProfile();
            const remotePush = Boolean(await registration.pushManager?.getSubscription?.());
            registration.active?.postMessage({ type: 'METEONEXA_CONFIGURE_BACKGROUND', payload: { location: { latitude: n(state.location.latitude), longitude: n(state.location.longitude), name: locationLabel() }, thresholds: profile, weatherApi: CONFIG.WEATHER_API, language: state.settings.language, deviceId, deviceKey: SECURITY.deviceKey, remotePush, serverAuthoritative: true } });
            if ('periodicSync' in registration && Notification.permission === 'granted') {
                const tags = await registration.periodicSync.getTags();
                if (!tags.includes('meteonexa-weather-check'))
                    await registration.periodicSync.register('meteonexa-weather-check', { minInterval: 15 * 60 * 1000 });
            }
        }
        catch { }
    }
    function cloneAndBind(selector, handler, event = 'click') {
        const old = q(selector);
        if (!old)
            return null;
        const fresh = old.cloneNode(true);
        old.replaceWith(fresh);
        fresh.addEventListener(event, handler);
        return fresh;
    }
    function bind() {
        initializeRouteControls();
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-alert-profile]');
            if (!button)
                return;
            event.preventDefault();
            event.stopImmediatePropagation();
            applyProfile(button.dataset.alertProfile);
        }, true);
        qa('[data-suite-map-layer]').forEach(button => button.addEventListener('click', () => {
            const details = button.closest('.radar-more-layers'), label = q('.radar-more-layers-label');
            if (label)
                label.textContent = button.textContent.trim();
            if (details)
                details.open = false;
            showSuiteMapLayer(button.dataset.suiteMapLayer).catch(error => toast(meteonexaText("advanced.setadvancedradarlayer.unavailable"), error.message, 'warning'));
        }));
        qa('[data-advanced-radar-layer]').forEach(button => button.addEventListener('click', () => {
            removeSuiteMapLayer();
            qa('[data-suite-map-layer]').forEach(item => item.classList.remove('active'));
            const label = q('.radar-more-layers-label');
            if (label)
                label.textContent = meteonexaText('radar.layers.more');
            q('.radar-more-layers')?.removeAttribute('open');
        }, true));
        ['suite-cold', 'suite-pollen', 'suite-wave'].forEach(id => q(`#${id}`)?.addEventListener('input', () => { const profile = loadAlertProfile(); profile[id.replace('suite-', '')] = n(q(`#${id}`).value); localStorage.setItem(KEYS.alerts, JSON.stringify(profile)); updateExtendedOutputs(); renderExtendedAlerts(); configureBackgroundChecks(); }));
        q('#history-form')?.addEventListener('submit', () => setTimeout(() => loadEnhancedHistory().catch(error => toast(meteonexaText("suite.bind.historical_comparison_unavailable"), error.message, 'warning')), 80));
        qa('[data-history-days]').forEach(button => button.addEventListener('click', () => setTimeout(() => loadEnhancedHistory().catch(() => { }), 80)));
        q('#suite-history-csv')?.addEventListener('click', exportHistoryCsv);
        q('#suite-history-pdf')?.addEventListener('click', () => exportHistoryPdf(suite.history));
        cloneAndBind('#route-calculate', () => loader(ui('route.action.calculate'), ui('assistant.analyzing'), calculateRouteUI, 480));
        cloneAndBind('#route-clear', clearRoute);
        cloneAndBind('#route-start-navigation', startRouteNavigation);
        cloneAndBind('#route-stop-navigation', stopRouteNavigation);
        q('#route-departure-comparison')?.addEventListener('click', event => { const button=event.target.closest?.('[data-route-departure-at]'); if(button)applyRouteDepartureCandidate(button.dataset.routeDepartureAt); });
        renderAssistantSuggestions();
        restoreAssistant();
        const profile = loadAlertProfile();
        applyProfile(profile.name, false);
        updateExtendedOutputs();
        configureBackgroundChecks();
        suite.refreshTimer = setInterval(() => {
            if (document.visibilityState === 'visible' && state.weather) {
                loadWeather?.({ force: true, silent: true }).then(() => refreshAdvanced(true)).catch(() => { });
            }
        }, 5 * 60 * 1000);
        suite.assistantSuggestionTimer = setInterval(() => {
            if (document.visibilityState === 'visible' && state.weather)
                renderAssistantSuggestions();
        }, 60 * 1000);
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === 'visible' && state.weather && Date.now() - suite.modelFetchedAt > 5 * 60 * 1000)
                refreshAdvanced(false);
        });
    }
    async function refreshAdvanced(force = false) {
        if (!state.weather)
            return;
        const key = locationKey();
        if (suite.advancedRequest)
            return suite.advancedRequest;
        if (!force && suite.advancedLocationKey === key && Date.now() - suite.advancedRefreshedAt < 3000)
            return;
        const loadingPanels=[...document.querySelectorAll('#page-advanced article.glass-panel')];loadingPanels.forEach(panel=>SERVICES.get('panelLoader')?.set?.(panel,true,meteonexaText('panel.loading')));
        suite.advancedRequest = (async () => {
            await Promise.allSettled([loadModels(force), loadEnvironment(), loadOfficialAlerts(force)]);
            suite.advancedRefreshedAt = Date.now();
            renderModels();
            await renderNowcast(force);
            renderExtendedAlerts();
            renderAssistantSuggestions();
            await saveSnapshot();
            suite.advancedLocationKey = key;
            suite.advancedRefreshedAt = Date.now();
        })();
        try {
            return await suite.advancedRequest;
        }
        finally {
            loadingPanels.forEach(panel=>SERVICES.get('panelLoader')?.set?.(panel,false));
            suite.advancedRequest = null;
        }
    }
    async function onPage(page) {
        SERVICES.get('suite')?.syncVisibility?.();
        if (page === 'advanced')
            await refreshAdvanced(false);
        if (page === 'history' && !suite.history)
            setTimeout(() => loadEnhancedHistory().catch(() => { }), 120);
        if (page === 'alerts') {
            await loadEnvironment();
            renderExtendedAlerts();
        }
        if (page === "radar" && suite.mapLayer)
            setTimeout(() => showSuiteMapLayer(suite.mapLayer).catch(() => { }), 650);
        if (page === 'devices')
            configureBackgroundChecks();
    }
    let unregisterAdvancedLifecycle = null;
    function patchLifecycle() {
        const advanced = SERVICES.get('advanced');
        if (!advanced || unregisterAdvancedLifecycle)
            return;
        if (typeof advanced.registerLifecycleHook !== 'function')
            throw new Error('METEONEXA_ADVANCED_LIFECYCLE_API_NOT_READY');
        unregisterAdvancedLifecycle = advanced.registerLifecycleHook(Object.freeze({
            renderAll() {
                if (state.currentPage !== 'advanced' || !state.weather)
                    return;
                if (!suite.modelRows.length) {
                    refreshAdvanced(false).catch(error => console.warn('SUITE_ADVANCED_BACKGROUND_LOAD_FAILED', error));
                    return;
                }
                renderNowcast(false).catch(error => console.warn('SUITE_NOWCAST_BACKGROUND_RENDER_FAILED', error));
                renderExtendedAlerts();
                renderAssistantSuggestions();
                saveSnapshot().catch(() => { });
            },
            onPage,
            locationChanged() {
                suite.modelRows = []; suite.modelFetchedAt = 0; suite.canonicalConsensus = null; suite.advancedRefreshedAt = 0; suite.advancedLocationKey = '';
                suite.direction = null; suite.environment = null; suite.history = null; suite.route = null;
                removeSuiteMapLayer();
                configureBackgroundChecks();
            }
        }));
    }
    document.addEventListener('meteonexa:notifications-opened', () => {
        if (isGuest() || !state.weather) return;
        loadEnvironment().then(() => renderExtendedAlerts()).catch(() => renderExtendedAlerts());
    });
    document.addEventListener('meteonexa:ready', () => {
        bind();
        patchLifecycle();
        setTimeout(() => {
            if (state.currentPage === 'advanced' && state.weather && !suite.advancedRefreshedAt)
                refreshAdvanced(false).catch(() => { });
        }, 250);
    }, { once: true });
    const suiteService = Object.assign(SERVICES.get('suite') || {}, { build: BUILD, refresh: refreshAdvanced, calculateRoute: calculateRouteUI, openAssistant: openAssistantDialog, openAssistantWithAi });
    SERVICES.publish('suite', suiteService);
})();

(() => {
    'use strict';
    const SERVICES = window.MeteoNexaServices;
    if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
    const APP_RUNTIME = SERVICES.require('runtimeApi').get();
    const { appLocale } = APP_RUNTIME;
    const currentLocale = () => appLocale();
    const dialog = () => document.querySelector('#meteo-date-dialog');
    const state = { targetId: '', view: new Date(), selected: '' };
    const pad = value => String(value).padStart(2, '0');
    const iso = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const parse = value => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match)
            return null;
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0, 0);
        return Number.isNaN(date.getTime()) ? null : date;
    };
    const format = value => {
        const date = value instanceof Date ? value : parse(value);
        return date ? new Intl.DateTimeFormat(currentLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date) : meteonexaText("suite.format.select_date");
    };
    const calendarLocaleData = () => {
        const locale = currentLocale();
        let weekInfo = null;
        try {
            weekInfo = new Intl.Locale(locale).weekInfo || new Intl.Locale(locale).getWeekInfo?.();
        }
        catch { }
        const firstDay = Number(weekInfo?.firstDay || 1);
        const firstNative = firstDay % 7;
        const weekdayOrder = Array.from({ length: 7 }, (_, index) => ((firstNative + index) % 7));
        const weekdayLabels = weekdayOrder.map(dayIndex => {
            const reference = new Date(2024, 0, 7 + dayIndex, 12);
            return new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(reference).replace('.', '').slice(0, 2);
        });
        return { firstNative, weekdayLabels };
    };
    function sync(targetId) {
        const input = document.getElementById(targetId);
        if (!input)
            return;
        document.querySelectorAll(`[data-meteo-date-target="${CSS.escape(targetId)}"]`).forEach(trigger => {
            const label = trigger.querySelector('[data-meteo-date-label]');
            if (label)
                label.textContent = format(input.value);
            trigger.setAttribute('aria-label', meteonexaText('date.control.aria', { label: trigger.closest('label')?.querySelector(':scope > span')?.textContent || "" + meteonexaText("history.renderhistory.date"), date: format(input.value) }));
        });
    }
    function render() {
        const root = dialog();
        if (!root)
            return;
        const monthNode = root.querySelector('#meteo-date-month');
        const weekdays = root.querySelector('#meteo-date-weekdays');
        const grid = root.querySelector('#meteo-date-grid');
        const selectedDate = parse(state.selected);
        const year = state.view.getFullYear();
        const month = state.view.getMonth();
        const { firstNative, weekdayLabels } = calendarLocaleData();
        if (monthNode)
            monthNode.textContent = new Intl.DateTimeFormat(currentLocale(), { month: 'long', year: 'numeric' }).format(new Date(year, month, 1, 12));
        if (weekdays)
            weekdays.innerHTML = weekdayLabels.map(label => `<span>${safe(label)}</span>`).join('');
        if (!grid)
            return;
        const monthStart = new Date(year, month, 1, 12);
        const nativeDay = monthStart.getDay();
        const offset = (nativeDay - firstNative + 7) % 7;
        const days = new Date(year, month + 1, 0).getDate();
        const today = iso(new Date());
        const cells = [];
        for (let index = 0; index < offset; index += 1)
            cells.push('<span class="meteo-date-empty" aria-hidden="true"></span>');
        for (let day = 1; day <= days; day += 1) {
            const value = iso(new Date(year, month, day, 12));
            const selected = selectedDate && value === state.selected;
            const isToday = value === today;
            cells.push(`<button type="button" role="gridcell" data-meteo-date-value="${value}" class="${selected ? 'selected ' : ''}${isToday ? 'today' : ''}" aria-selected="${selected ? 'true' : 'false'}"><span>${day}</span></button>`);
        }
        grid.innerHTML = cells.join('');
    }
    function open(targetId) {
        const root = dialog();
        const input = document.getElementById(targetId);
        if (!root || !input)
            return;
        state.targetId = targetId;
        state.selected = input.value || iso(new Date());
        state.view = parse(state.selected) || new Date();
        render();
        if (typeof root.showModal === 'function')
            root.showModal();
        else
            root.setAttribute('open', '');
    }
    function close() {
        const root = dialog();
        if (!root)
            return;
        if (root.open && typeof root.close === 'function')
            root.close();
        else
            root.removeAttribute('open');
    }
    function select(value) {
        const input = document.getElementById(state.targetId);
        if (!input)
            return;
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event("meteo-date-sync"));
        sync(state.targetId);
        close();
    }
    function setValue(targetId, value, emit = false) {
        const input = document.getElementById(targetId);
        if (!input)
            return;
        input.value = value;
        sync(targetId);
        if (emit)
            input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.addEventListener('click', event => {
        const trigger = event.target.closest?.('[data-meteo-date-target]');
        if (trigger) {
            event.preventDefault();
            open(trigger.dataset.meteoDateTarget);
            return;
        }
        const valueButton = event.target.closest?.('[data-meteo-date-value]');
        if (valueButton) {
            event.preventDefault();
            select(valueButton.dataset.meteoDateValue);
        }
    });
    document.addEventListener("meteo-date-sync", event => {
        if (event.target?.id)
            sync(event.target.id);
    }, true);
    const bindDatePickerDom = () => {
        const root = dialog();
        if (!root)
            return;
        root.querySelector('#meteo-date-prev')?.addEventListener('click', () => { state.view = new Date(state.view.getFullYear(), state.view.getMonth() - 1, 1, 12); render(); });
        root.querySelector('#meteo-date-next')?.addEventListener('click', () => { state.view = new Date(state.view.getFullYear(), state.view.getMonth() + 1, 1, 12); render(); });
        root.querySelector('#meteo-date-today')?.addEventListener('click', () => select(iso(new Date())));
        root.querySelector('#meteo-date-cancel')?.addEventListener('click', close);
        root.querySelector('.meteo-date-close')?.addEventListener('click', close);
        root.addEventListener('click', event => {
            if (event.target === root)
                close();
        });
        document.querySelectorAll('[data-meteo-date-target]').forEach(trigger => sync(trigger.dataset.meteoDateTarget));
        const observer = new MutationObserver(records => {
            records.forEach(record => record.addedNodes.forEach(node => {
                if (!(node instanceof Element))
                    return;
                const triggers = node.matches?.('[data-meteo-date-target]') ? [node] : [...node.querySelectorAll?.('[data-meteo-date-target]') || []];
                triggers.forEach(trigger => sync(trigger.dataset.meteoDateTarget));
            }));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindDatePickerDom, { once: true });
    else queueMicrotask(bindDatePickerDom);
    const datePickerService = Object.assign(SERVICES.get('datePicker') || {}, { sync, setValue, format, open });
    SERVICES.publish('datePicker', datePickerService);
})();
