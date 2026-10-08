'use strict';
function loadJSON(key, fallback) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    }
    catch {
        return fallback;
    }
}
function saveJSON(key, value) {
    try {
        if (value === null || value === undefined)
            localStorage.removeItem(key);
        else
            localStorage.setItem(key, JSON.stringify(value));
    }
    catch (error) {
        console.warn("" + meteonexaText("app.savejson.unable_save_local_data"), error);
    }
}
function clearNavigationLoadingArtifacts() {
    $$('.nav-link, .mobile-nav-link, [data-page]').forEach(button => {
        const hadLoader = button.classList.contains('button-loading');
        if (hadLoader) button.classList.remove('button-loading');
        if (button.getAttribute('aria-busy') === 'true') button.removeAttribute('aria-busy');
        if (hadLoader && button.disabled) button.disabled = false;
    });
}
function setActionButtonLoading(active) {
    clearNavigationLoadingArtifacts();
    if (active) {
        const candidate = lastUserActionButton && performance.now() - lastUserActionAt < 650 ? lastUserActionButton : null;
        if (!candidate || candidate.disabled || candidate.matches('[data-no-preloader], [data-page], .nav-link, .mobile-nav-link')) return;
        loaderActionButton = candidate;
        loaderActionButtonWasDisabled = candidate.disabled;
        candidate.classList.add('button-loading');
        candidate.setAttribute('aria-busy', 'true');
        candidate.disabled = true;
        return;
    }
    if (!loaderActionButton) return;
    loaderActionButton.classList.remove('button-loading');
    loaderActionButton.removeAttribute('aria-busy');
    loaderActionButton.disabled = loaderActionButtonWasDisabled;
    loaderActionButton = null;
    loaderActionButtonWasDisabled = false;
}
function setLoader(active, title = "" + meteonexaText("app.setloader.weather_update"), copy = "" + meteonexaText("app.setloader.preparing_latest_data")) {
    const loader = $('#global-loader');
    if (!loader)
        return;
    const activeDialog = $$('dialog.app-dialog[open]').at(-1);
    if (active && activeDialog && loader.parentElement !== activeDialog)
        activeDialog.appendChild(loader);
    if (!active && loader.parentElement !== document.body)
        document.body.appendChild(loader);
    $('#loader-title').textContent = t(title);
    $('#loader-copy').textContent = t(copy);
    loader.classList.toggle('active', active);
    loader.setAttribute('aria-hidden', String(!active));
    document.body.classList.toggle('operation-loading', active);
    if (active) setActionButtonLoading(true);
    else setActionButtonLoading(false);
    if (loaderSafetyTimer) {
        clearTimeout(loaderSafetyTimer);
        loaderSafetyTimer = null;
    }
    if (active) {
        loaderSafetyTimer = setTimeout(() => {
            state.loaderDepth = 0;
            forceReleaseGlobalLoader();
        }, 8000);
    }
}
function forceReleaseGlobalLoader() {
    if (loaderSafetyTimer) {
        clearTimeout(loaderSafetyTimer);
        loaderSafetyTimer = null;
    }
    const loader = $('#global-loader');
    loader?.classList.remove('active');
    loader?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('operation-loading');
    try { setActionButtonLoading(false); }
    catch (error) {
        console.warn('LOADER_ACTION_RELEASE_FAILED', error);
        loaderActionButton = null;
        loaderActionButtonWasDisabled = false;
        clearNavigationLoadingArtifacts();
    }
    if (loader && loader.parentElement !== document.body) document.body.appendChild(loader);
}
async function withLoader(title, copy, task, minDuration = 420) {
    const startedAt = performance.now();
    state.loaderDepth += 1;
    try {
        setLoader(true, title, copy);
        return await task();
    }
    finally {
        const remaining = minDuration - (performance.now() - startedAt);
        if (remaining > 0) await sleep(remaining);
        state.loaderDepth = Math.max(0, state.loaderDepth - 1);
        if (state.loaderDepth === 0) {
            try { setLoader(false); }
            catch (error) {
                console.warn('LOADER_RELEASE_FAILED', error);
                forceReleaseGlobalLoader();
            }
        }
    }
}
async function fetchJSON(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeout || CONFIG.REQUEST_TIMEOUT_MS);
    try {
        const target = new URL(url, location.href);
        const sameOrigin = target.origin === location.origin;
        const credentialMode = options.credentials || (sameOrigin ? 'same-origin' : 'omit');
        const includeSecurityHeaders = sameOrigin && credentialMode !== 'omit' && options.securityHeaders !== false;
        const response = await fetch(target.toString(), {
            signal: controller.signal,
            headers: { Accept: 'application/json', ...(includeSecurityHeaders ? (SERVICES.get('security')?.headers?.() || {}) : {}) },
            credentials: credentialMode,
            cache: 'no-store'
        });
        if (!response.ok)
            throw new Error(meteonexaText("suite.apimessage.service_unavailable_value", { p0: response.status }));
        return await response.json();
    }
    catch (error) {
        if (error?.name === 'AbortError')
            throw new Error(meteonexaText('network.request.timeout'));
        if (error instanceof TypeError)
            throw new Error(meteonexaText('network.request.failed'));
        throw error;
    }
    finally {
        clearTimeout(timeout);
    }
}
function detectDevice() {
    const ua = navigator.userAgent.toLowerCase();
    const width = innerWidth;
    let device = width < 600 ? 'mobile' : width < 1025 ? 'tablet' : 'desktop';
    if (/ipad|tablet/.test(ua))
        device = 'tablet';
    else if (/iphone|android.+mobile/.test(ua))
        device = 'mobile';
    const platform = /iphone|ipad|mac/.test(ua) ? 'apple' : /android/.test(ua) ? 'android' : /windows/.test(ua) ? 'windows' : 'other';
    const standalone = Boolean(window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);
    document.documentElement.dataset.device = device;
    document.documentElement.dataset.platform = platform;
    document.documentElement.dataset.displayMode = standalone ? 'standalone' : 'browser';
    document.documentElement.classList.toggle('is-standalone', standalone);
    document.body?.classList.toggle('is-standalone', standalone);
}
function syncEnhancedSelect(id, value) {
    const select = document.getElementById(id);
    if (!select)
        return;
    select.value = String(value);
    const instance = enhancedSelects.get(id);
    if (instance)
        instance.setChoiceByValue(String(value));
}
function initializeEnhancedSelects() {
    if (!window.Choices) {
        document.documentElement.classList.add('choices-unavailable');
        return;
    }
    const briefingHourSelect = document.getElementById('briefing-hour');
    if (briefingHourSelect && personalWeatherPrefs?.briefingHourSet !== true)
        briefingHourSelect.value = String(currentLocationHour());
    $$('.enhanced-select').forEach(select => {
        if (enhancedSelects.has(select.id))
            return;
        const instance = new window.Choices(select, {
            searchEnabled: false,
            shouldSort: false,
            itemSelectText: '',
            allowHTML: false,
            position: select.dataset.choicePosition || 'auto'
        });
        const container = instance.containerOuter?.element;
        container?.classList.add('meteonexa-choices');
        const updatePlacement = () => {
            if (!container)
                return;
            container.classList.remove('is-flipped');
            const forced = select.dataset.choicePosition;
            if (forced === 'top') {
                container.classList.add('is-flipped');
                return;
            }
            requestAnimationFrame(() => {
                const dropdown = container.querySelector('.choices__list--dropdown, .choices__list[aria-expanded]');
                if (!dropdown)
                    return;
                const footer = select.closest('.dialog-layout')?.querySelector('.dialog-footer');
                const containerRect = container.getBoundingClientRect();
                const dropdownHeight = Math.max(dropdown.scrollHeight || 0, dropdown.getBoundingClientRect().height || 0, 150);
                const lowerLimit = footer ? footer.getBoundingClientRect().top - 10 : window.innerHeight - 12;
                const availableBelow = lowerLimit - containerRect.bottom;
                const availableAbove = containerRect.top - 12;
                if (availableBelow < dropdownHeight && availableAbove > availableBelow)
                    container.classList.add('is-flipped');
            });
        };
        select.addEventListener('showDropdown', () => {
            select.closest('.glass-panel')?.classList.add('select-dropdown-open');
            updatePlacement();
        });
        select.addEventListener('hideDropdown', () => {
            select.closest('.glass-panel')?.classList.remove('select-dropdown-open');
            if (select.dataset.choicePosition !== 'top')
                container?.classList.remove('is-flipped');
        });
        enhancedSelects.set(select.id, instance);
    });
}
function applyThemePreference(preference = state.settings.theme, { persist = true, notify = true } = {}) {
    const normalized = ['system', 'light', 'dark'].includes(String(preference)) ? String(preference) : 'system';
    const resolved = normalized === 'system' ? browserTheme() : normalized;
    state.settings.theme = normalized;
    document.documentElement.dataset.theme = resolved;
    document.documentElement.style.colorScheme = resolved;
    if (document.body)
        document.body.dataset.theme = resolved;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolved === 'light' ? '#eef4f9' : '#06152f');
    try {
        const i18nState = SERVICES.get('i18n')?.state;
        if (i18nState) {
            i18nState.theme = normalized;
            i18nState.resolvedTheme = resolved;
        }
    }
    catch { }
    syncEnhancedSelect('theme-setting', normalized);
    if (persist)
        persistLocalSettings();
    if (notify)
        document.dispatchEvent(new CustomEvent('meteonexa:theme-changed', { detail: { preference: normalized, resolved } }));
    return resolved;
}
function applySettings({ rerender = true } = {}) {
    const theme = applyThemePreference(state.settings.theme, { persist: false, notify: false });
    document.title = t("app.applysettings.meteonexa_weather_forecasts_radar_alerts");
    document.body.classList.toggle('reduce-motion', Boolean(state.settings.reduceMotion));
    syncEnhancedSelect("temperature-unit", state.settings.unit);
    syncEnhancedSelect('language-setting', state.settings.language);
    $$('[data-language-setting]').forEach(select => {
        if (select.id !== 'language-setting')
            select.value = state.settings.language;
    });
    syncEnhancedSelect('theme-setting', state.settings.theme);
    syncEnhancedSelect('refresh-setting', String(state.settings.refresh));
    $('#reduce-motion-setting').checked = Boolean(state.settings.reduceMotion);
    if ($('#radar-mode-setting'))
        syncEnhancedSelect("radar-mode-setting", state.settings.radarMode || 'live');
    persistLocalSettings();
    scheduleRefresh();
    if (rerender && state.weather)
        renderAll();
    translateDOM(document);
}
function setOnboardingView(viewId) {
    $$('.onboarding-view').forEach(view => view.classList.toggle('active', view.id === viewId));
}
function persistSessionSafely(session = state.session) {
    if (!session) {
        localStorage.removeItem(STORAGE.session);
        return;
    }
    const persisted = { ...session };
    delete persisted.email;
    saveJSON(STORAGE.session, persisted);
}
function armGuestMobileFocusGuard(duration = 1800) {
    mobileFocusGuardUntil = Math.max(mobileFocusGuardUntil, Date.now() + duration);
    try { document.activeElement?.blur?.(); } catch { }
    const input = $('#onboarding-city');
    const locationView = $('#location-view');
    if (locationView) locationView.inert = true;
    if (input && !input.dataset.guestFocusGuard) {
        input.dataset.guestFocusGuard = '1';
        input.readOnly = true;
        input.disabled = true;
        input.setAttribute('aria-disabled','true');
    }
    document.documentElement.classList.add('mobile-focus-guard');
    clearTimeout(guestFocusGuardTimer);
    guestFocusGuardTimer = window.setTimeout(() => {
        mobileFocusGuardUntil = 0;
        document.documentElement.classList.remove('mobile-focus-guard');
        if (locationView) locationView.inert = false;
        const guarded = $('#onboarding-city');
        if (guarded?.dataset.guestFocusGuard === '1') {
            guarded.disabled = false;
            guarded.readOnly = false;
            guarded.removeAttribute('aria-disabled');
            delete guarded.dataset.guestFocusGuard;
        }
        try { document.activeElement?.blur?.(); } catch { }
    }, duration);
}
function resolveLoginWeatherScene(weather = state.weather) {
    const current = weather?.current || null;
    const fetchedAt = Number(weather?.fetchedAt || 0);
    const freshEnough = fetchedAt > 0 && Date.now() - fetchedAt <= 6 * 3600000;
    if (!current || !freshEnough) {
        const hour = new Date().getHours();
        return hour >= 20 || hour < 6 ? 'night' : 'neutral';
    }
    const code = Number(current.weather_code ?? -1);
    const isDay = Number(current.is_day ?? 1) === 1;
    if ([95,96,99].includes(code)) return 'storm';
    if ([71,73,75,77,85,86].includes(code)) return 'snow';
    if ([51,53,55,56,57,61,63,65,66,67,80,81,82].includes(code) || Number(current.rain || current.precipitation || 0) > 0) return 'rain';
    if ([45,48].includes(code)) return 'fog';
    if ([1,2,3].includes(code)) return isDay ? 'cloud' : 'night-cloud';
    if (code === 0) return isDay ? 'sun' : 'night';
    return isDay ? 'neutral' : 'night';
}
function applyLoginWeatherBackdrop(weather = state.weather) {
    const welcome = $('#welcome');
    if (!welcome) return;
    const scene = resolveLoginWeatherScene(weather);
    welcome.dataset.loginWeather = scene;
    const fetchedAt = Number(weather?.fetchedAt || 0);
    welcome.dataset.loginWeatherSource = weather?.current && fetchedAt > 0 && Date.now() - fetchedAt <= 6 * 3600000 ? 'local-cache' : 'neutral';
    welcome.dataset.loginWeatherReady = 'true';
    const versionBadge = $('#login-version-badge');
    if (versionBadge) versionBadge.textContent = `v${APP_RELEASE_LABEL}`;
}
async function startLocalSession(profile, { touchGuard = false } = {}) {
    if (touchGuard) armGuestMobileFocusGuard(2200);
    const type = ['guest', 'email'].includes(String(profile?.type || '')) ? String(profile.type) : 'guest';
    const enterSession = async () => {
        try { sessionStorage.removeItem(SESSION_FLAGS.forceAuth); } catch { }
        state.authServerVerified = type === 'email' && profile?.serverVerified === true;
        state.session = {
            type,
            name: String(profile?.name || '').slice(0, 120),
            ...(type === 'email' ? { verified: profile?.verified === true, ...(validEmail(profile?.email || '') ? { email: String(profile.email).trim().toLowerCase() } : {}) } : {}),
            at: Date.now()
        };
        persistSessionSafely();
        try { SERVICES.get('authDomain')?.publish?.(state.session, state.authServerVerified); } catch { }
        let accountHydration = null;
        if (type === 'email' && state.authServerVerified === true)
            accountHydration = await hydrateAuthenticatedAccountAfterLogin({ adoptSavedLocation: true });
        const canResumeAccount = type === 'email' && state.authServerVerified === true && Boolean(accountHydration?.hadLocalLocation || accountHydration?.adoptedLocation);
        if (canResumeAccount) {
            await showApp({ refresh: true, waitForWeather: true, forceWeather: true });
            return;
        }
        setOnboardingView('location-view');
        if (state.location && Number.isFinite(Number(state.location.latitude))) updateSelectedLocationUI();
    };
    if (type === 'guest') {
        await enterSession();
    } else {
        await withLoader("" + meteonexaText("app.entersession.signing"), "" + meteonexaText("app.entersession.preparing_weather_space"), enterSession, 650);
    }
    if (touchGuard) armGuestMobileFocusGuard(1400);
}
function clearFieldError(inputId) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(`${inputId}-error`);
    input?.classList.remove('is-invalid');
    input?.removeAttribute('aria-invalid');
    if (error)
        error.textContent = '';
}
function setFieldError(inputId, message) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(`${inputId}-error`);
    input?.classList.add('is-invalid');
    input?.setAttribute('aria-invalid', 'true');
    if (error)
        error.textContent = t(message);
    input?.focus();
}
function validEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(String(value).trim());
}
function isAuthenticatedSession() {
    return state.session?.type === 'email';
}
function hasVerifiedServerSession() {
    return isAuthenticatedSession() && state.authServerVerified === true;
}
function handleAuthRequiredResponse({ notify = true } = {}) {
    const wasAuthenticated = isAuthenticatedSession();
    state.authServerVerified = false;
    if (wasAuthenticated) {
        state.session = null;
        persistSessionSafely(null);
        try { sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1'); } catch { }
    }
    try { applyGuestAccessUI(); } catch { }
    try { syncPrivacyContextCopy(); } catch { }
    try { updateProfileUI(); } catch { }
    const now = Date.now();
    if (notify && now - Number(state.authRequiredHandledAt || 0) > 2500) {
        state.authRequiredHandledAt = now;
        showToast(meteonexaText('guest.login.required.title'), meteonexaText('api.security.auth_required'), 'warning', 5200);
    }
    try { SERVICES.get('authDomain')?.required?.(); } catch { }
    document.dispatchEvent(new CustomEvent('meteonexa:auth-required'));
}
function apiResponseMessage(data, status) {
    const raw = String(data?.message || '').trim();
    if (raw) {
        const translated = t(raw);
        return translated && translated !== raw ? translated : raw;
    }
    return meteonexaText("app.apiresponsemessage.server_error_value", { status });
}
async function apiRequest(path, payload = null, requestOptions = {}) {
    const targetUrl = new URL(path, window.location.href);
    const sameOrigin = targetUrl.origin === window.location.origin;
    const securityHeaders = sameOrigin ? (SERVICES.get('security')?.headers?.() || {}) : {};
    const options = {
        method: payload ? 'POST' : 'GET',
        headers: { Accept: 'application/json', ...securityHeaders, ...(sameOrigin && requestOptions?.headers ? requestOptions.headers : {}) },
        cache: 'no-store',
        credentials: sameOrigin ? 'same-origin' : 'omit'
    };
    if (payload) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
    }
    const controller = new AbortController();
    options.signal = controller.signal;
    const timeoutMs = clamp(Number(requestOptions?.timeout || 6500), 1000, 60000);
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(path, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.ok === false) {
            if (response.status === 401 && data?.code === 'AUTH_REQUIRED')
                handleAuthRequiredResponse({ notify: requestOptions?.notifyAuthRequired !== false });
            const error = new Error(apiResponseMessage(data, response.status));
            error.code = data.code || `HTTP_${response.status}`;
            error.details = data;
            throw error;
        }
        return data;
    }
    catch (error) {
        if (error.name === 'AbortError') {
            const timeoutError = new Error(meteonexaText("app.apirequest.sign_server_not_responding_try_again_shortly"));
            timeoutError.code = 'TIMEOUT';
            throw timeoutError;
        }
        if (error instanceof TypeError) {
            const networkError = new Error(meteonexaText('network.request.failed'));
            networkError.code = 'NETWORK_ERROR';
            throw networkError;
        }
        throw error;
    }
    finally {
        clearTimeout(timeout);
    }
}
function buildWeatherURL(locationData = state.location) {
    const params = new URLSearchParams({
        latitude: String(locationData.latitude), longitude: String(locationData.longitude),
        current: 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,rain,weather_code,cloud_cover,surface_pressure,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
        hourly: 'temperature_2m,apparent_temperature,dew_point_2m,precipitation_probability,precipitation,weather_code,relative_humidity_2m,visibility,wind_speed_10m,wind_direction_10m,wind_gusts_10m,surface_pressure,uv_index,cloud_cover,is_day',
        minutely_15: 'precipitation,rain,weather_code,temperature_2m,wind_gusts_10m',
        daily: 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,sunrise,sunset,daylight_duration,sunshine_duration,uv_index_max,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max',
        timezone: 'auto', forecast_days: '10', wind_speed_unit: 'kmh'
    });
    return `${CONFIG.WEATHER_API}?${params}`;
}
