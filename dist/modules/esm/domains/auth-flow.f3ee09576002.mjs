const PROVIDES = Object.freeze(['authFlow']);
export const dependencies = Object.freeze([]);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            function create(deps) {
                const { state, storage: STORAGE, sessionFlags: SESSION_FLAGS, $, t } = deps;
                const apiRequest = (...args) => deps.apiRequest(...args);
                const escapeHTML = (...args) => deps.escapeHTML(...args);
                const validEmail = (...args) => deps.validEmail(...args);
                const persistSessionSafely = (...args) => deps.persistSessionSafely(...args);
                const showToast = (...args) => deps.showToast(...args);
                const clearFieldError = (...args) => deps.clearFieldError(...args);
                const setFieldError = (...args) => deps.setFieldError(...args);
                const withLoader = (...args) => deps.withLoader(...args);
                const startLocalSession = (...args) => deps.startLocalSession(...args);
                let authResendTimer = null;
                let authResendSeconds = 0;
                function setAuthStep(step) {
                    const requestStep = $('#auth-request-step');
                    const verifyStep = $('#auth-verify-step');
                    const requestFooter = $('#auth-footer-request');
                    const verifyFooter = $('#auth-footer-verify');
                    const verify = step === 'verify';
                    requestStep.hidden = verify;
                    verifyStep.hidden = !verify;
                    requestFooter.hidden = verify;
                    verifyFooter.hidden = !verify;
                    requestStep.classList.toggle('active', !verify);
                    verifyStep.classList.toggle('active', verify);
                    $('#auth-dialog-title').textContent = verify ? t("auth.setauthstep.enter_code") : t("auth.setauthstep.sign_email");
                    $('#auth-dialog-copy').textContent = verify
                        ? t("auth.check_email_code_expires_after_10_minutes")
                        : t("auth.receive_one_time_code_valid_10_minutes");
                }
                function stopAuthResendTimer() {
                    if (authResendTimer)
                        clearInterval(authResendTimer);
                    authResendTimer = null;
                }
                function startAuthResendTimer(seconds = 60) {
                    stopAuthResendTimer();
                    authResendSeconds = seconds;
                    const button = $('#auth-resend');
                    const label = $('#auth-resend-countdown');
                    const tick = () => {
                        if (authResendSeconds <= 0) {
                            stopAuthResendTimer();
                            button.disabled = false;
                            label.textContent = '';
                            return;
                        }
                        button.disabled = true;
                        label.textContent = meteonexaText('auth.resend.countdown', { seconds: authResendSeconds });
                        authResendSeconds -= 1;
                    };
                    tick();
                    authResendTimer = setInterval(tick, 1000);
                }
                async function refreshAuthServerStatus() {
                    const status = $('#auth-smtp-status');
                    try {
                        const data = await apiRequest('api/auth/status.php');
                        status.classList.toggle('warning', !data.smtpConfigured);
                        status.innerHTML = data.smtpConfigured
                            ? `<svg><use href="#i-shield"/></svg><span>${escapeHTML(t("auth.service_available_youll_receive_code_valid_10_minutes"))}</span>`
                            : `<svg><use href="#i-shield"/></svg><span>${escapeHTML(t("auth.email_service_temporarily_unavailable_try_again_later"))}</span>`;
                    }
                    catch {
                        status.classList.add('warning');
                        status.innerHTML = `<svg><use href="#i-shield"/></svg><span>${escapeHTML(t("auth.email_service_temporarily_unavailable_try_again_later"))}</span>`;
                    }
                }
                async function reconcileEmailServerSession() {
                    // The server session is authoritative only when this browser also retains the
                    // local email-session continuity marker created after OTP/trusted re-entry.
                    // This is intentional: if browser/site-data cleanup removes local auth state,
                    // a surviving HttpOnly cookie must not silently rebuild the authenticated UI.
                    const hadLocalEmailSession = state.session?.type === 'email';
                    const hadLocalGuestSession = state.session?.type === 'guest';
                    let forceAuth = false;
                    try { forceAuth = sessionStorage.getItem(SESSION_FLAGS.forceAuth) === '1'; } catch { }
                    if (forceAuth) return false;
                    if (navigator.onLine === false) { state.authServerVerified = false; state.diagnosticsAllowed = false; return hadLocalEmailSession; }
                    try {
                        const data = await apiRequest('api/auth/status.php', null, { timeout: 12000, notifyAuthRequired: false });
                        if (data.authenticated === true) {
                            if (!hadLocalEmailSession) {
                                // Orphaned server auth: this happens after partial cache/site-data
                                // eviction when cookies survive longer than the local auth marker.
                                // Never resurrect the email session from the cookie alone.
                                try {
                                    await apiRequest('api/auth/logout.php', {
                                        logout: true,
                                        revokeTrustedDevice: !hadLocalGuestSession
                                    }, { notifyAuthRequired: false });
                                } catch { }
                                state.authServerVerified = false;
                                state.diagnosticsAllowed = false;
                                if (!hadLocalGuestSession) {
                                    state.session = null;
                                    try { localStorage.removeItem(STORAGE.session); } catch { }
                                    try {
                                        sessionStorage.setItem(SESSION_FLAGS.forceAuth, '1');
                                        sessionStorage.setItem(SESSION_FLAGS.cacheReset, '1');
                                    } catch { }
                                }
                                return false;
                            }
                            const cleanName = String(data.displayName || state.session?.name || '').slice(0, 120);
                            const cleanEmail = validEmail(data.email || '') ? String(data.email).trim().toLowerCase() : '';
                            state.session = { type: 'email', name: cleanName, verified: true, ...(cleanEmail ? { email: cleanEmail } : {}), at: Number(state.session?.at) || Date.now() };
                            state.authServerVerified = true;
                            state.diagnosticsAllowed = data.diagnosticsAllowed === true;
                            persistSessionSafely();
                            return true;
                        }
                        state.authServerVerified = false;
                        state.diagnosticsAllowed = false;
                        if (state.session?.type === 'email') {
                            localStorage.removeItem(STORAGE.session);
                            state.session = null;
                        }
                        return false;
                    }
                    catch {
                        // Temporary network/server errors never revoke an already-restored local
                        // email marker; private APIs still remain blocked until server proof is
                        // verified again.
                        state.authServerVerified = false;
                        state.diagnosticsAllowed = false;
                        return hadLocalEmailSession;
                    }
                }
        
                function openAuthDialog(type) {
                    if (type === 'sms') {
                        showToast("" + meteonexaText("auth.openauthdialog.sms_unavailable"), "" + meteonexaText("auth.sms_sign_isnt_available_yet_use_email_continue"), 'info');
                        return;
                    }
                    $('#auth-dialog-icon').innerHTML = '<svg><use href="#i-mail"/></svg>';
                    $('#auth-primary-label').textContent = t("auth.openauthdialog.email");
                    $('#auth-primary').type = 'email';
                    $('#auth-primary').placeholder = t("auth.openauthdialog.name_example_com");
                    $('#auth-primary').value = '';
                    $('#auth-code').value = '';
                    $('#auth-form').dataset.type = 'email';
                    clearFieldError('auth-primary');
                    clearFieldError('auth-code');
                    setAuthStep('request');
                    $('#auth-dialog').showModal();
                    refreshAuthServerStatus();
                    setTimeout(() => $('#auth-primary').focus(), 120);
                }
                async function beginEmailAccessFlow() {
                    if (navigator.onLine === false) {
                        openAuthDialog('email');
                        return;
                    }
                    let trusted = null;
                    try {
                        trusted = await withLoader(
                            meteonexaText('auth.trusted.checking.title'),
                            meteonexaText('auth.trusted.checking.copy'),
                            () => apiRequest('api/auth/trusted-check.php', { action: 'trusted-reentry' }, { timeout: 5500, notifyAuthRequired: false }),
                            280
                        );
                    } catch (error) {
                        console.warn('TRUSTED_DEVICE_PRECHECK_FAILED', error);
                    }
                    if (trusted?.authenticated === true && trusted?.trusted === true) {
                        await startLocalSession({ type: 'email', name: trusted.displayName || meteonexaText('app.name'), verified: true, serverVerified: true });
                        showToast(meteonexaText('auth.trusted.reentry.title'), meteonexaText('auth.trusted.reentry.copy'), 'success');
                        return;
                    }
                    openAuthDialog('email');
                }
                async function requestEmailCode({ resend = false } = {}) {
                    const email = $('#auth-primary').value.trim().toLowerCase();
                    clearFieldError('auth-primary');
                    if (!validEmail(email)) {
                        setFieldError('auth-primary', t("auth.enter_valid_email_address"));
                        showToast("" + meteonexaText("auth.requestemailcode.invalid_email"), "" + meteonexaText("auth.check_email_address_entered"), 'error');
                        return;
                    }
                    try {
                        let trustedReentry = false;
                        await withLoader(resend ? "" + meteonexaText("auth.requestemailcode.new_code") : "" + meteonexaText("auth.requestemailcode.sending_code"), "" + meteonexaText("auth.requestemailcode.sending_verification_email"), async () => {
                            const result = await apiRequest('api/auth/request-code.php', { email, language: state.settings.language });
                            if (result.authenticated === true && result.trustedDevice === true) {
                                trustedReentry = true;
                                            stopAuthResendTimer();
                                $('#auth-dialog').close();
                                await startLocalSession({ type: 'email', name: result.displayName || email.split('@')[0], email: result.email || email, verified: true, serverVerified: true });
                                return;
                            }
                            $('#auth-email-target').textContent = result.maskedEmail || email;
                            setAuthStep('verify');
                            startAuthResendTimer(Number(result.resendAfter || 60));
                            setTimeout(() => $('#auth-code').focus(), 100);
                        }, 420);
                        showToast("" + meteonexaText(trustedReentry ? 'auth.requestemailcode.email_verified' : 'auth.requestemailcode.code_sent'), "" + meteonexaText(trustedReentry ? 'auth.requestemailcode.secure_access_completed' : 'auth.also_check_spam_folder_code_expires_10_minutes'), 'success');
                    }
                    catch (error) {
                        showToast("" + meteonexaText("auth.requestemailcode.send_failed"), error.message, 'error', 5200);
                    }
                }
                async function verifyEmailCode() {
                    const email = $('#auth-primary').value.trim().toLowerCase();
                    const code = $('#auth-code').value.replace(/\D/g, '').slice(0, 6);
                    clearFieldError('auth-code');
                    if (!/^\d{6}$/.test(code)) {
                        setFieldError('auth-code', t("auth.enter_6_digits_received_by_email"));
                        showToast("" + meteonexaText("auth.verifyemailcode.incomplete_code"), "" + meteonexaText("auth.code_must_contain_6_digits"), 'error');
                        return;
                    }
                    try {
                        const result = await withLoader("" + meteonexaText("auth.verifyemailcode.verify_code"), "" + meteonexaText("auth.checking_one_time_code"), () => apiRequest('api/auth/verify-code.php', { email, code, language: state.settings.language }), 420);
                        stopAuthResendTimer();
                            $('#auth-dialog').close();
                        await startLocalSession({ type: 'email', name: result.displayName || email.split('@')[0], email: result.email || email, verified: true, serverVerified: true });
                        showToast("" + meteonexaText("auth.requestemailcode.email_verified"), "" + meteonexaText("auth.requestemailcode.secure_access_completed"), 'success');
                    }
                    catch (error) {
                        setFieldError('auth-code', error.message || "" + meteonexaText("auth.code_invalid_has_expired"));
                        showToast("" + meteonexaText("auth.verifyemailcode.verification_failed"), error.message || "" + meteonexaText("auth.request_new_code_try_again"), 'error');
                    }
                }
        
                return Object.freeze({
                    setAuthStep, stopAuthResendTimer, startAuthResendTimer, refreshAuthServerStatus,
                    reconcileEmailServerSession, openAuthDialog, beginEmailAccessFlow, requestEmailCode, verifyEmailCode
                });
            }
            provided.authFlow = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
