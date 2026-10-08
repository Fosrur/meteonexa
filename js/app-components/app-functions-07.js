'use strict';
function synchronizeLocalState(event) {
    if (!event.key)
        return;
    if (event.key === STORAGE.session) {
        reconcileRootView({ refresh: true });
        return;
    }
    if (event.key === STORAGE.location) {
        state.location = loadJSON(STORAGE.location, state.location);
        if (!$('#weather-app').hidden)
            loadWeather({ force: true, silent: true });
        else if (hasUsableLocation())
            updateSelectedLocationUI();
        return;
    }
    if (event.key === STORAGE.weather) {
        state.weather = loadJSON(STORAGE.weather, state.weather);
        if (state.weather) {
            applyWeatherTimeZoneMetadata(state.weather);
            renderAll();
        }
        return;
    }
    if (event.key === STORAGE.air) {
        state.air = loadJSON(STORAGE.air, state.air);
        if (state.weather)
            renderAll();
        return;
    }
    if (event.key === STORAGE.favorites || event.key === STORAGE.recent) {
        state.favorites = loadJSON(STORAGE.favorites, state.favorites);
        state.recent = loadJSON(STORAGE.recent, state.recent);
        syncFavoriteUI();
        if (state.currentPage === 'favorites')
            renderFavorites();
        return;
    }
    if (event.key === STORAGE.thresholds) {
        state.thresholds = { ...DEFAULT_THRESHOLDS, ...loadJSON(STORAGE.thresholds, {}) };
        initializeThresholds();
        if (state.weather)
            renderAlerts();
        return;
    }
    if (event.key === STORAGE.notifications) {
        state.notifications = { ...DEFAULT_NOTIFICATIONS, ...loadJSON(STORAGE.notifications, {}) };
        syncNotificationButton();
        return;
    }
    if (event.key === STORAGE.settings) {
        const remoteLocalSettings = loadJSON(STORAGE.settings, {});
        delete remoteLocalSettings.language;
        delete remoteLocalSettings.theme;
        state.settings = { ...state.settings, ...remoteLocalSettings };
        applySettings();
    }
}
function updateProfileUI() {
    const profile = state.session || { type: 'guest', name: "" + meteonexaText("app.updateprofileui.guest") };
    const name = profile.name || "" + meteonexaText("app.updateprofileui.guest");
    const initials = name === "" + meteonexaText("app.updateprofileui.guest") ? "" + meteonexaText("app.updateprofileui.ao") : name.split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map(item => item[0].toUpperCase()).join('');
    $$('#profile-button span, .profile-avatar').forEach(node => { node.textContent = initials || "" + meteonexaText("app.updateprofileui.ao"); });
    $('#profile-name').textContent = meteonexaText('profile.dialog.title');
    const emailNode = $('#profile-email');
    if (emailNode) {
        emailNode.textContent = profile.type === 'email' && validEmail(profile.email || '')
            ? String(profile.email).trim().toLowerCase()
            : (profile.type === 'email' ? meteonexaText('app.updateprofileui.local_email_access') : meteonexaText('app.updateprofileui.access_without_registration'));
    }
    $('#profile-method').textContent = profile.type === 'email' ? "" + meteonexaText("app.updateprofileui.local_email_access") : profile.type === 'sms' ? "" + meteonexaText("app.updateprofileui.local_sms_access") : "" + meteonexaText("app.updateprofileui.access_without_registration");
    updateProfileNotificationBadge();
    syncDevicesSettingsButton();
}
function closeOpenAppDialogs(exceptId = '') {
    $$('dialog.app-dialog[open]').forEach(dialog => {
        if (dialog.id !== exceptId) {
            try {
                dialog.close('cancel');
            }
            catch {
                dialog.removeAttribute('open');
            }
        }
    });
}
function nextPaint() {
    return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
}
function settleConfirmDialog(confirmed, { closeDialog = true } = {}) {
    const dialog = $('#confirm-dialog');
    const request = pendingConfirmRequest;
    pendingConfirmRequest = null;
    if (dialog) {
        dialog.returnValue = confirmed ? 'confirm' : 'cancel';
        if (closeDialog && dialog.open) {
            try {
                dialog.close(dialog.returnValue);
            }
            catch {
                dialog.removeAttribute('open');
            }
        }
    }
    if (request)
        request.resolve(Boolean(confirmed));
}
async function confirmAction(title, copy, { confirmLabel = "" + meteonexaText("app.confirmaction.confirm"), icon = '#i-alert', kind = 'default' } = {}) {
    const dialog = $('#confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function')
        return window.confirm(`${t(title)}

${t(copy)}`);
    if (pendingConfirmRequest)
        settleConfirmDialog(false);
    $('#confirm-title').textContent = t(title);
    $('#confirm-copy').textContent = t(copy);
    $('#confirm-ok').textContent = t(confirmLabel);
    const iconUse = $('#confirm-icon-use');
    if (iconUse)
        iconUse.setAttribute('href', icon);
    dialog.dataset.confirmKind = kind;
    closeOpenAppDialogs(dialog.id);
    await nextPaint();
    dialog.returnValue = '';
    return new Promise(resolve => {
        pendingConfirmRequest = { resolve };
        try {
            if (!dialog.open)
                dialog.showModal();
            requestAnimationFrame(() => $('#confirm-cancel')?.focus({ preventScroll: true }));
        }
        catch (error) {
            pendingConfirmRequest = null;
            console.warn(meteonexaText("app.confirmaction.confirmation_dialog_unavailable_using_browser_fallback"), error);
            resolve(window.confirm(`${t(title)}

${t(copy)}`));
        }
    });
}
async function logout({ returnToProfile = false } = {}) {
    if (state.logoutInProgress)
        return;
    state.logoutInProgress = true;
    try {
        closeOpenAppDialogs();
        await nextPaint();
        const confirmed = await confirmAction("" + meteonexaText("app.logout.sign_out_meteonexa"), "" + meteonexaText("app.logout.local_session_will_closed_favorites_settings_will_remain"), { confirmLabel: "" + meteonexaText("app.logout.sign_out"), icon: '#i-logout', kind: 'logout' });
        if (!confirmed) {
            if (returnToProfile) {
                await nextPaint();
                const profileDialog = $('#profile-dialog');
                try {
                    if (profileDialog && !profileDialog.open)
                        profileDialog.showModal();
                }
                catch { }
            }
            return;
        }
        let logoutTarget = location.pathname || '/';
        await withLoader("" + meteonexaText("app.logout.signing_out"), "" + meteonexaText("app.logout.see_soon_returning_sign_screen"), async () => {
            if (state.session?.type === 'email') {
                try { await apiRequest('api/auth/logout.php', { logout: true }); }
                catch { }
            }
            try {
                localStorage.removeItem(STORAGE.session);
            }
            catch { }
            try {
                sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
            }
            catch { }
            state.session = null;
            state.authServerVerified = false;
            closeOpenAppDialogs();
            setMobileSidebarOpen(false);
            const cleanUrl = new URL(location.href);
            cleanUrl.searchParams.delete('preview');
            cleanUrl.searchParams.delete('app');
            cleanUrl.hash = '';
            logoutTarget = `${cleanUrl.pathname}${cleanUrl.search}` || '/';
            await sleep(80);
        }, 420);
        try {
            location.replace(logoutTarget);
        }
        catch {
            location.href = logoutTarget;
        }
    }
    finally {
        state.logoutInProgress = false;
    }
}
function cacheResetSafeUiSettings() {
    const language = ['it', 'en', 'fr', 'es', 'de'].includes(String(state.settings?.language || '')) ? String(state.settings.language) : DEFAULT_SETTINGS.language;
    const theme = ['system', 'light', 'dark'].includes(String(state.settings?.theme || '')) ? String(state.settings.theme) : DEFAULT_SETTINGS.theme;
    return { language, theme };
}
function settleBrowserOperation(promise, timeoutMs = 900, fallback = null) {
    return new Promise(resolve => {
        let settled = false;
        const finish = value => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve(value);
        };
        const timer = setTimeout(() => finish(fallback), Math.max(100, Number(timeoutMs) || 900));
        Promise.resolve(promise).then(finish, () => finish(fallback));
    });
}
async function deleteMeteoNexaIndexedDb() {
    if (!('indexedDB' in window)) return;
    try {
        const databases = typeof indexedDB.databases === 'function'
            ? await settleBrowserOperation(indexedDB.databases(), 700, [])
            : [];
        await Promise.all((databases || []).filter(entry => String(entry?.name || '').toLowerCase().startsWith('meteonexa')).map(entry => settleBrowserOperation(new Promise(resolve => {
            try {
                const request = indexedDB.deleteDatabase(entry.name);
                request.onsuccess = request.onerror = request.onblocked = () => resolve(true);
            } catch { resolve(false); }
        }), 700, false)));
    } catch (error) {
        console.warn('INDEXED_DB_CLEAR_FAILED', error);
    }
}
async function unregisterMeteoNexaServiceWorkers() {
    if (!navigator.serviceWorker?.getRegistrations) return;
    try {
        const registrations = await settleBrowserOperation(navigator.serviceWorker.getRegistrations(), 800, []);
        const appPath = new URL('./', location.href).pathname.replace(/[^/]+$/, '');
        await Promise.all((registrations || []).filter(registration => {
            try { return new URL(registration.scope).pathname.startsWith(appPath); } catch { return false; }
        }).map(registration => settleBrowserOperation(registration.unregister(), 700, false)));
    } catch (error) {
        console.warn('SERVICE_WORKER_UNREGISTER_FAILED', error);
    }
}
function clearMeteoNexaLocalRuntimeState({ preservePreferences = true } = {}) {
    try {
        const safeUiSettings = preservePreferences ? cacheResetSafeUiSettings() : null;
        for (let index = localStorage.length - 1; index >= 0; index -= 1) {
            const key = localStorage.key(index);
            if (!key) continue;
            if (key.startsWith('meteonexa_') || key.startsWith('meteonexa.') || key.startsWith('meteonexa-')) localStorage.removeItem(key);
        }
        if (safeUiSettings) localStorage.setItem(STORAGE.settings, JSON.stringify(safeUiSettings));
    } catch (error) {
        console.warn('LOCAL_STORAGE_CLEAR_FAILED', error);
    }
    try {
        for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
            const key = sessionStorage.key(index);
            if (key && (key.startsWith('meteonexa_') || key.startsWith('meteonexa.') || key.startsWith('meteonexa-'))) sessionStorage.removeItem(key);
        }
        sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
        sessionStorage.setItem(SESSION_FLAGS.cacheReset, '1');
    } catch { }
}
async function clearMeteoNexaCacheStorage() {
    if (!('caches' in window)) return;
    try {
        const names = await settleBrowserOperation(caches.keys(), 800, []);
        const targets = (names || []).filter(name => name.startsWith('meteonexa-') || name === CACHE_SENTINEL.cacheName);
        await Promise.all(targets.map(name => settleBrowserOperation(caches.delete(name), 700, false)));
    } catch (error) {
        console.warn('CACHE_STORAGE_CLEAR_FAILED', error);
    }
}
function notifyMeteoNexaServiceWorkerReset() {
    try {
        const worker = navigator.serviceWorker?.controller;
        worker?.postMessage({ type: 'METEONEXA_CLEAR_BACKGROUND' });
        worker?.postMessage({ type: 'CLEAR_APP_CACHES' });
    } catch { }
}
async function clearBrowserApplicationState({ localAlreadyCleared = false } = {}) {
    if (!localAlreadyCleared) clearMeteoNexaLocalRuntimeState({ preservePreferences: true });
    notifyMeteoNexaServiceWorkerReset();
    await Promise.allSettled([
        clearMeteoNexaCacheStorage(),
        deleteMeteoNexaIndexedDb(),
        unregisterMeteoNexaServiceWorkers()
    ]);
}
async function requestGuestCacheReceiptProof() {
    const language = String(state.settings?.language || 'it');
    const startChallenge = async email => apiRequest('api/privacy/cache-reset-challenge.php', {
        action: 'start', email, language
    }, { timeout: 7000, notifyAuthRequired: false });
    const verifyChallenge = async (challengeToken, code) => apiRequest('api/privacy/cache-reset-challenge.php', {
        action: 'verify', challengeToken, code, language
    }, { timeout: 5000, notifyAuthRequired: false });

    const dialog = $('#cache-privacy-dialog');
    const form = $('#cache-privacy-form');
    const emailInput = $('#cache-receipt-email');
    const codeInput = $('#cache-receipt-code');
    const emailStep = $('#cache-privacy-email-step');
    const codeStep = $('#cache-privacy-code-step');
    const verificationCopy = $('#cache-privacy-verification-copy');
    const confirmLabel = $('#cache-privacy-confirm-label');

    if (!dialog || !form || !emailInput || !codeInput || typeof dialog.showModal !== 'function') {
        const fallback = window.prompt(meteonexaText('settings.cache.receipt.guest.email_label'), '') || '';
        const email = String(fallback).trim().toLowerCase();
        if (!validEmail(email)) {
            showToast(meteonexaText('settings.cache.receipt.invalid.title'), meteonexaText('settings.cache.receipt.invalid_email'), 'warning', 4200);
            return null;
        }
        let challenge;
        try {
            challenge = await withLoader(meteonexaText('settings.cache.verify.sending.title'), meteonexaText('settings.cache.verify.sending.copy'), () => startChallenge(email), 240);
        } catch (error) {
            showToast(meteonexaText('settings.cache.verify.error.title'), error?.message || meteonexaText('settings.cache.verify.send_failed'), 'warning', 5200);
            return null;
        }
        const code = String(window.prompt(meteonexaText('settings.cache.verify.code_prompt', { email: String(challenge.maskedEmail || '') }), '') || '').replace(/\D+/g, '').slice(0, 6);
        if (!/^\d{6}$/.test(code)) return null;
        try {
            const verified = await withLoader(meteonexaText('settings.cache.verify.checking.title'), meteonexaText('settings.cache.verify.checking.copy'), () => verifyChallenge(String(challenge.challengeToken || ''), code), 220);
            return verified?.verified === true ? { guestProof: String(verified.guestProof || ''), maskedEmail: String(verified.maskedEmail || '') } : null;
        } catch (error) {
            showToast(meteonexaText('settings.cache.verify.error.title'), error?.message || meteonexaText('settings.cache.verify.invalid_code'), 'warning', 5200);
            return null;
        }
    }

    closeOpenAppDialogs(dialog.id);
    clearFieldError('cache-receipt-email');
    clearFieldError('cache-receipt-code');
    emailInput.value = '';
    emailInput.disabled = false;
    codeInput.value = '';
    if (emailStep) emailStep.hidden = false;
    if (codeStep) codeStep.hidden = true;
    if (confirmLabel) {
        confirmLabel.dataset.i18nKey = 'settings.cache.verify.send_code';
        confirmLabel.textContent = meteonexaText('settings.cache.verify.send_code');
    }
    await nextPaint();

    return new Promise(resolve => {
        let settled = false;
        let challengeToken = '';
        let maskedEmail = '';
        let busy = false;
        const cleanup = () => {
            form.removeEventListener('submit', onSubmit);
            dialog.removeEventListener('cancel', onCancel);
            dialog.removeEventListener('close', onClose);
            emailInput.removeEventListener('input', onEmailInput);
            codeInput.removeEventListener('input', onCodeInput);
        };
        const finish = value => {
            if (settled) return;
            settled = true;
            cleanup();
            if (dialog.open) dialog.close(value ? 'confirm' : 'cancel');
            resolve(value);
        };
        const onSubmit = async event => {
            event.preventDefault();
            if (busy) return;
            if (!challengeToken) {
                const email = String(emailInput.value || '').trim().toLowerCase();
                if (!validEmail(email)) {
                    setFieldError('cache-receipt-email', 'settings.cache.receipt.invalid_email');
                    return;
                }
                busy = true;
                try {
                    const challenge = await withLoader(
                        meteonexaText('settings.cache.verify.sending.title'),
                        meteonexaText('settings.cache.verify.sending.copy'),
                        () => startChallenge(email),
                        240
                    );
                    challengeToken = String(challenge.challengeToken || '');
                    maskedEmail = String(challenge.maskedEmail || '');
                    if (!challengeToken) throw new Error(meteonexaText('settings.cache.verify.send_failed'));
                    emailInput.disabled = true;
                    if (emailStep) emailStep.hidden = true;
                    if (codeStep) codeStep.hidden = false;
                    if (verificationCopy) {
                        verificationCopy.dataset.i18nDynamic = 'true';
                        verificationCopy.textContent = meteonexaText('settings.cache.verify.sent', { email: maskedEmail });
                    }
                    if (confirmLabel) {
                        confirmLabel.dataset.i18nKey = 'settings.cache.verify.verify_submit';
                        confirmLabel.textContent = meteonexaText('settings.cache.verify.verify_submit');
                    }
                    await nextPaint();
                    codeInput.focus({ preventScroll: true });
                } catch (error) {
                    setFieldError('cache-receipt-email', error?.message || 'settings.cache.verify.send_failed');
                } finally {
                    busy = false;
                }
                return;
            }

            const code = String(codeInput.value || '').replace(/\D+/g, '').slice(0, 6);
            if (!/^\d{6}$/.test(code)) {
                setFieldError('cache-receipt-code', 'settings.cache.verify.invalid_code');
                return;
            }
            busy = true;
            try {
                const verified = await withLoader(
                    meteonexaText('settings.cache.verify.checking.title'),
                    meteonexaText('settings.cache.verify.checking.copy'),
                    () => verifyChallenge(challengeToken, code),
                    220
                );
                const proof = String(verified.guestProof || '');
                if (verified.verified !== true || !proof) throw new Error(meteonexaText('settings.cache.verify.invalid_code'));
                finish({ guestProof: proof, maskedEmail: String(verified.maskedEmail || maskedEmail) });
            } catch (error) {
                setFieldError('cache-receipt-code', error?.message || 'settings.cache.verify.invalid_code');
            } finally {
                busy = false;
            }
        };
        const onCancel = event => { event.preventDefault(); if (!busy) finish(null); };
        const onClose = () => finish(null);
        const onEmailInput = () => clearFieldError('cache-receipt-email');
        const onCodeInput = () => {
            codeInput.value = String(codeInput.value || '').replace(/\D+/g, '').slice(0, 6);
            clearFieldError('cache-receipt-code');
        };
        form.addEventListener('submit', onSubmit);
        dialog.addEventListener('cancel', onCancel);
        dialog.addEventListener('close', onClose);
        emailInput.addEventListener('input', onEmailInput);
        codeInput.addEventListener('input', onCodeInput);
        try {
            dialog.showModal();
            requestAnimationFrame(() => emailInput.focus({ preventScroll: true }));
        } catch {
            finish(null);
        }
    });
}
