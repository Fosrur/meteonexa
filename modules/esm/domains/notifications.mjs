const PROVIDES = Object.freeze(['notifications']);
export const dependencies = Object.freeze([]);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const create = deps => {
                const {
                    state, STORAGE, APP_BUILD, $, meteonexaText, escapeHTML, appLocale, t, saveJSON,
                    apiRequest, accountSyncDeviceId, isGuestSession, showGuestAccessNotice, withLoader,
                    showToast, shortLocationLabel, locationKey, temperature, officialAlertStateId,
                    isAlertRead, officialAlertReadable, officialAlertLifecycleText, formatOfficialAlertTime,
                    markAlertRead, updateAlertBadgeCount, renderAlerts, loadHomeOfficialAlerts,
                    updateNetworkStatus
                } = deps;
        
                function isIOSDevice() {
                    return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                }
                function isAndroidDevice() { return /android/i.test(navigator.userAgent); }
                function isHandheldOrTabletDevice() {
                    const ua = String(navigator.userAgent || '');
                    const uaMobile = /android|iphone|ipad|ipod|mobile|tablet/i.test(ua);
                    const ipadDesktopMode = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
                    const touchTablet = navigator.maxTouchPoints > 0 && matchMedia('(max-width: 1180px)').matches;
                    return uaMobile || ipadDesktopMode || touchTablet;
                }
                function isStandalonePWA() { return matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }
                function notificationPermission() { return "Notification" in window ? Notification.permission : 'unsupported'; }
                function notificationEnvironment() {
                    const ios = isIOSDevice();
                    const android = isAndroidDevice();
                    const standalone = isStandalonePWA();
                    const secure = window.isSecureContext || ['localhost', '127.0.0.1'].includes(location.hostname);
                    const notifications = "Notification" in window;
                    const serviceWorker = 'serviceWorker' in navigator;
                    return { ios, android, standalone, secure, notifications, serviceWorker, supported: secure && notifications && serviceWorker };
                }
                function setNotificationCardState(cardId, stateName) {
                    const card = $(cardId);
                    if (!card)
                        return;
                    card.classList.remove('ok', 'warning', 'error');
                    if (stateName)
                        card.classList.add(stateName);
                }
                function updateNotificationCenterUI() {
                    const env = notificationEnvironment();
                    const permission = notificationPermission();
                    const platform = env.ios ? meteonexaText('platform.ios') : env.android ? meteonexaText('platform.android') : meteonexaText('platform.desktop');
                    const copy = $('#notification-device-copy');
                    if (copy) {
                        copy.textContent = env.ios && !env.standalone
                            ? "" + meteonexaText("notifications.iphone_ipad_notifications_become_available_after_adding_meteonexa") : meteonexaText("notifications.value_notifications_managed_by_service_worker_no_additional", { platform: platform });
                    }
                    $('#notification-support-status').textContent = env.supported ? "" + meteonexaText("notifications.updatenotificationcenterui.available") : "" + meteonexaText("intelligence.confidencefrommodels.unavailable");
                    $('#notification-support-note').textContent = !env.secure ? "" + meteonexaText("notifications.updatenotificationcenterui.https_required") : !env.serviceWorker ? "" + meteonexaText("notifications.updatenotificationcenterui.service_worker_missing") : !env.notifications ? "" + meteonexaText("notifications.updatenotificationcenterui.browser_not_compatible") : "" + meteonexaText("notifications.browser_service_worker_ready");
                    setNotificationCardState('#notification-support-card', env.supported ? 'ok' : 'error');
                    const handheld = isHandheldOrTabletDevice();
                    const installCard = $('#notification-install-card');
                    if (installCard) installCard.hidden = !handheld;
                    $('#notification-install-status').textContent = env.standalone ? "" + meteonexaText("notifications.updatenotificationcenterui.app_installed") : "" + meteonexaText("notifications.updatenotificationcenterui.browser");
                    $('#notification-install-note').textContent = env.ios && !env.standalone ? "" + meteonexaText("notifications.updatenotificationcenterui.installation_required_ios") : env.standalone ? "" + meteonexaText("notifications.updatenotificationcenterui.standalone_mode_active") : "" + meteonexaText("notifications.updatenotificationcenterui.installation_recommended");
                    setNotificationCardState('#notification-install-card', env.standalone ? 'ok' : env.ios ? 'warning' : '');
                    const permissionLabel = permission === 'granted' ? "" + meteonexaText("notifications.updatenotificationcenterui.allowed") : permission === 'denied' ? "" + meteonexaText("notifications.updatenotificationcenterui.blocked") : permission === 'default' ? "" + meteonexaText("notifications.updatenotificationcenterui.requested") : "" + meteonexaText("notifications.updatenotificationcenterui.not_supported");
                    $('#notification-permission-status').textContent = permissionLabel;
                    $('#notification-permission-note').textContent = permission === 'granted' ? "" + meteonexaText("notifications.device_can_show_alerts") : permission === 'denied' ? "" + meteonexaText("notifications.re_enable_site_settings") : "" + meteonexaText("notifications.will_asked_after_tap");
                    setNotificationCardState('#notification-permission-card', permission === 'granted' ? 'ok' : permission === 'denied' ? 'error' : 'warning');
                    $('#notification-delivery-status').textContent = permission === 'granted' ? "" + meteonexaText("notifications.updatenotificationcenterui.ready") : "" + meteonexaText("notifications.updatenotificationcenterui.waiting");
                    $('#notification-delivery-note').textContent = permission === 'granted' ? "" + meteonexaText("notifications.tests_local_alerts_enabled") : "" + meteonexaText("notifications.enable_permission_get_started");
                    setNotificationCardState('#notification-delivery-card', permission === 'granted' ? 'ok' : '');
                    const guide = $('#notification-platform-guide');
                    const installButton = $('#notification-install');
                    $('#notification-dialog')?.classList.toggle("needs-install", handheld && env.ios && !env.standalone);
                    if (guide)
                        guide.hidden = !(env.ios && !env.standalone);
                    if (installButton)
                        installButton.hidden = env.standalone || !handheld;
                    if (env.ios && !env.standalone) {
                        $('#notification-guide-title').textContent = "" + meteonexaText("notifications.first_add_meteonexa_home_screen");
                        $('#notification-guide-copy').textContent = "" + meteonexaText("notifications.safari_use_share_add_home_screen_then_open");
                    }
                    const primary = $('#notification-primary');
                    const primaryLabel = primary ? $('span', primary) : null;
                    if (primary) {
                        primary.disabled = permission === 'granted' || !env.supported || (env.ios && !env.standalone);
                        primary.classList.toggle('is-ready', permission === 'granted');
                    }
                    if (primaryLabel)
                        primaryLabel.textContent = permission === 'granted' ? "" + meteonexaText("notifications.updatenotificationcenterui.notifications_enabled") : env.ios && !env.standalone ? "" + meteonexaText("notifications.updatenotificationcenterui.install_app_first") : permission === 'denied' ? "" + meteonexaText("notifications.updatenotificationcenterui.permission_blocked") : "" + meteonexaText("notifications.updatenotificationcenterui.enable_notifications");
                    const test = $('#notification-test');
                    if (test)
                        test.disabled = permission !== 'granted';
                    if ($('#notify-severe'))
                        $('#notify-severe').checked = state.notifications.severe !== false;
                    if ($('#notify-rain'))
                        $('#notify-rain').checked = state.notifications.rain !== false;
                }
                function updateProfileNotificationBadge() {
                    // notifications are reached from the dedicated Alerts/Settings entry; no redundant profile badge.
                }
                function syncNotificationButton() {
                    const button = $('#notification-button');
                    const env = notificationEnvironment();
                    const permission = notificationPermission();
                    const granted = permission === 'granted';
                    if (button) {
                        const label = $('span', button);
                        button.classList.toggle("notifications-enabled", granted);
                        button.classList.toggle("notifications-blocked", permission === 'denied');
                        if (label)
                            label.textContent = granted ? "" + meteonexaText("notifications.syncnotificationbutton.manage_notifications") : env.ios && !env.standalone ? "" + meteonexaText("notifications.syncnotificationbutton.install_notifications") : permission === 'denied' ? "" + meteonexaText("notifications.syncnotificationbutton.notifications_blocked") : env.supported ? "" + meteonexaText("notifications.updatenotificationcenterui.enable_notifications") : "" + meteonexaText("notifications.syncnotificationbutton.unavailable");
                    }
                    const settingsStatus = $('#settings-notification-status');
                    if (settingsStatus)
                        settingsStatus.textContent = granted ? meteonexaText('settings.notifications.status.enabled') : meteonexaText('settings.notifications.status.disabled');
                    updateNotificationCenterUI();
                    updateProfileNotificationBadge();
                    updatePwaSettingsStatus();
                }
                function renderNotificationOfficialAlert() {
                    const root = $('#public-official-alert-card');
                    if (!root) return;
                    const data = state.officialAlerts;
                    const rows = Array.isArray(data?.relevant) ? data.relevant : [];
                    const rank = {red:3,orange:2,yellow:1};
                    const warning = rows.length ? [...rows].sort((a,b)=>(rank[String(b?.severity||'yellow')]||1)-(rank[String(a?.severity||'yellow')]||1))[0] : null;
                    const alertId = warning ? officialAlertStateId(warning) : '';
                    const read = alertId ? isAlertRead(alertId) : false;
                    root.className = `public-official-alert-card ${warning ? `severity-${officialAlertReadable(warning).severity}` : 'severity-green'}${read ? ' is-read' : ''}`;
                    root.dataset.alertId = alertId;
                    if (!data) {
                        root.innerHTML = `<span class="public-official-icon"><svg><use href="#i-shield"/></svg></span><div><small>${escapeHTML(meteonexaText('guest.alerts.official.kicker'))}</small><strong>${escapeHTML(meteonexaText('guest.alerts.official.loading'))}</strong></div>`;
                        updateAlertBadgeCount();
                        return;
                    }
                    if (!warning) {
                        root.innerHTML = `<span class="public-official-icon"><svg><use href="#i-shield"/></svg></span><div><small>${escapeHTML(meteonexaText('guest.alerts.official.kicker'))}</small><strong>${escapeHTML(meteonexaText('home.official.none.title'))}</strong><p>${escapeHTML(meteonexaText('home.official.none.copy',{location:shortLocationLabel(state.location)}))}</p></div>`;
                        updateAlertBadgeCount();
                        return;
                    }
                    const readable = officialAlertReadable(warning);
                    const lifecycle = officialAlertLifecycleText(warning);
                    const validUntil = warning.endsAt ? meteonexaText('guest.alerts.official.until',{until:formatOfficialAlertTime(warning.endsAt)}) : '';
                    root.innerHTML = `<span class="public-official-icon"><svg><use href="#i-alert"/></svg></span><div><small>${escapeHTML(meteonexaText('guest.alerts.official.kicker'))}</small><strong>${escapeHTML(readable.label)}</strong><p>${escapeHTML([lifecycle,validUntil].filter(Boolean).join(' · '))}</p></div>${read ? '' : `<button class="alert-read-button official-alert-read" type="button" aria-label="${escapeHTML(meteonexaText('alerts.mark_read'))}" title="${escapeHTML(meteonexaText('alerts.mark_read'))}"><svg><use href="#i-eye"/></svg></button>`}`;
                    $('.official-alert-read', root)?.addEventListener('click', () => {
                        markAlertRead(alertId);
                        renderNotificationOfficialAlert();
                        renderAlerts();
                    });
                    updateAlertBadgeCount();
                }
        
                function setNotificationCenterAudience() {
                    const dialog = $('#notification-dialog');
                    const guestPublic = isGuestSession();
                    if (!dialog) return guestPublic;
                    dialog.dataset.guestPublic = guestPublic ? 'true' : 'false';
                    const note = $('#guest-public-alert-note');
                    if (note) note.hidden = !guestPublic;
                    const title = $('#notification-dialog-title');
                    const copy = $('#notification-device-copy');
                    if (guestPublic) {
                        if (title) title.textContent = meteonexaText('guest.alerts.public.title');
                        if (copy) copy.textContent = meteonexaText('guest.alerts.public.subtitle',{location:shortLocationLabel(state.location)});
                    } else {
                        if (title) title.textContent = meteonexaText('notifications.setnotificationcenteraudience.weather_alerts_device');
                        if (copy) copy.textContent = meteonexaText('notifications.checking_compatibility_installation_permissions');
                    }
                    return guestPublic;
                }
                function formatNotificationInboxTime(value) {
                    const date = new Date(value || '');
                    if (Number.isNaN(date.getTime())) return '';
                    try { return new Intl.DateTimeFormat(appLocale(), { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' }).format(date); }
                    catch { return ''; }
                }
                function renderNotificationInbox(rows = []) {
                    const section = $('#notification-inbox-section');
                    const list = $('#notification-inbox-list');
                    const markAll = $('#notification-inbox-mark-all');
                    if (!section || !list) return;
                    if (isGuestSession()) {
                        section.hidden = true;
                        state.notificationInboxUnread = 0;
                        state.notificationInboxLoaded = false;
                        updateAlertBadgeCount();
                        return;
                    }
                    section.hidden = false;
                    const safeRows = Array.isArray(rows) ? rows : [];
                    state.notificationInboxUnread = safeRows.filter(item => !item.readAt).length;
                    state.notificationInboxLoaded = true;
                    if (markAll) markAll.hidden = state.notificationInboxUnread === 0;
                    list.innerHTML = safeRows.length ? safeRows.map(item => `<article class="notification-inbox-item ${item.readAt ? '' : 'unread'}" data-notification-id="${Number(item.id)||0}"><span class="notification-inbox-item-icon"><svg><use href="#i-bell"/></svg></span><div class="notification-inbox-copy"><strong>${escapeHTML(String(item.title||''))}</strong><span>${escapeHTML(String(item.body||''))}</span><small>${escapeHTML(formatNotificationInboxTime(item.createdAt))}</small></div><div class="notification-inbox-actions">${item.readAt ? '' : `<button type="button" data-notification-read aria-label="${escapeHTML(meteonexaText('alerts.mark_read'))}" title="${escapeHTML(meteonexaText('alerts.mark_read'))}"><svg><use href="#i-eye"/></svg></button>`}<button type="button" data-notification-dismiss aria-label="${escapeHTML(meteonexaText('notifications.dismiss'))}" title="${escapeHTML(meteonexaText('notifications.dismiss'))}"><svg><use href="#i-trash"/></svg></button></div></article>`).join('') : `<div class="notification-inbox-empty">${escapeHTML(meteonexaText('notifications.inbox.empty'))}</div>`;
                    updateAlertBadgeCount();
                }
                async function loadNotificationInbox({ silent = true } = {}) {
                    if (isGuestSession()) { renderNotificationInbox([]); return []; }
                    const deviceId = accountSyncDeviceId();
                    if (!deviceId) return [];
                    try {
                        const result = await apiRequest('api/push/inbox.php', { deviceId, action:'list' }, { timeout: 6500, notifyAuthRequired: false });
                        renderNotificationInbox(result?.rows || []);
                        return result?.rows || [];
                    } catch (error) {
                        state.notificationInboxLoaded = false;
                        if (!silent) showToast(meteonexaText('notifications.error.title'), error?.message || meteonexaText('notifications.error.copy'), 'warning');
                        updateAlertBadgeCount();
                        return [];
                    }
                }
                async function updateNotificationInbox(action, id = 0) {
                    if (isGuestSession()) return;
                    const deviceId = accountSyncDeviceId();
                    if (!deviceId) return;
                    await apiRequest('api/push/inbox.php', { deviceId, action, id: Number(id) || 0 }, { timeout: 6500, notifyAuthRequired: false });
                    await loadNotificationInbox({ silent: false });
                }
                async function openNotificationCenter() {
                    const guestPublic = setNotificationCenterAudience();
                    if (!guestPublic) { updateNotificationCenterUI(); loadNotificationInbox({ silent: true }); }
                    renderAlerts();
                    renderNotificationOfficialAlert();
                    loadHomeOfficialAlerts({ force: false }).then(() => renderNotificationOfficialAlert()).catch(() => renderNotificationOfficialAlert());
                    const dialog = $('#notification-dialog');
                    if (dialog && !dialog.open) dialog.showModal();
                    if (!guestPublic) document.dispatchEvent(new CustomEvent('meteonexa:notifications-opened'));
                }
                async function sendDeviceNotification(title, body, options = {}) {
                    if (notificationPermission() !== 'granted')
                        throw new Error(t("notifications.notification_permission_not_granted"));
                    title = t(title);
                    body = t(body);
                    const registration = await navigator.serviceWorker?.ready.catch(() => null);
                    const notificationOptions = {
                        body,
                        icon: 'assets/icons/icon-192.png',
                        badge: 'assets/icons/favicon-32.png',
                        tag: options.tag || 'meteonexa-notification',
                        renotify: options.renotify ?? true,
                        data: { url: options.url || './#notifications', ...(options.data || {}) },
                        vibrate: options.vibrate || [120, 80, 120],
                        timestamp: Date.now()
                    };
                    if (registration?.showNotification) {
                        await registration.showNotification(title, notificationOptions);
                    }
                    else if ("Notification" in window) {
                        new Notification(title, notificationOptions);
                    }
                    else {
                        throw new Error(t("notifications.senddevicenotification.notification_system_unavailable"));
                    }
                }
                async function enableNotifications() {
                    if (isGuestSession()) {
                        showGuestAccessNotice();
                        return;
                    }
                    const env = notificationEnvironment();
                    if (env.ios && !env.standalone) {
                        updateNotificationCenterUI();
                        showToast("" + meteonexaText("notifications.enablenotifications.install_meteonexa_first"), "" + meteonexaText("notifications.iphone_ipad_add_home_screen_reopen"), 'warning', 6000);
                        return;
                    }
                    await withLoader("" + meteonexaText("notifications.enablenotifications.enabling_notifications"), "" + meteonexaText("notifications.enablenotifications.connecting_meteonexa_device"), async () => {
                        if (!env.secure) {
                            showToast("" + meteonexaText("notifications.enablenotifications.https_required"), "" + meteonexaText("notifications.notifications_work_over_https_localhost"), 'warning');
                            return;
                        }
                        if (!env.supported) {
                            showToast("" + meteonexaText("notifications.enablenotifications.notifications_not_supported"), "" + meteonexaText("notifications.browser_does_not_provide_notification_api_service_workers"), 'warning');
                            return;
                        }
                        const permission = await Notification.requestPermission();
                        if (permission === 'granted') {
                            state.notifications.enabled = true;
                            saveJSON(STORAGE.notifications, state.notifications);
                            await sendDeviceNotification("" + meteonexaText("notifications.enablenotifications.meteonexa_ready"), "" + meteonexaText("notifications.local_weather_alerts_have_been_enabled_device"), { tag: 'meteonexa-enabled', url: './#notifications' });
                            showToast("" + meteonexaText("notifications.enablenotifications.notifications_enabled"), "" + meteonexaText("notifications.can_send_test_customize_alerts"), 'success');
                            evaluateLocalWeatherNotifications();
                        }
                        else {
                            showToast("" + meteonexaText("notifications.enablenotifications.permission_not_granted"), "" + meteonexaText("notifications.can_enable_again_site_device_settings"), 'warning');
                        }
                        syncNotificationButton();
                    }, 450);
                }
                async function testDeviceNotification() {
                    if (isGuestSession()) {
                        showGuestAccessNotice();
                        return;
                    }
                    try {
                        await sendDeviceNotification(meteonexaText('notification.test.title'), meteonexaText("notifications.testdevicenotification.notifications_working_value", { location: shortLocationLabel() }), { tag: 'meteonexa-test', url: './#notifications' });
                        showToast("" + meteonexaText("notifications.testdevicenotification.test_notification_sent"), "" + meteonexaText("notifications.check_device_notification_center"), 'success');
                    }
                    catch (error) {
                        console.warn('NOTIFICATION_TEST_FAILED', error);
                        showToast("" + meteonexaText("notifications.testdevicenotification.test_failed"), "" + meteonexaText("notifications.check_permissions_make_sure_app_installed"), 'warning');
                    }
                }
                function saveNotificationPreferences() {
                    if (isGuestSession()) {
                        showGuestAccessNotice();
                        return;
                    }
                    state.notifications.severe = $('#notify-severe')?.checked !== false;
                    state.notifications.rain = $('#notify-rain')?.checked !== false;
                    saveJSON(STORAGE.notifications, state.notifications);
                    showToast("" + meteonexaText("notifications.savenotificationpreferences.notification_preferences_saved"), "" + meteonexaText("notifications.future_analyses_will_use_these_choices"), 'success', 2200);
                    evaluateLocalWeatherNotifications();
                }
                function notificationCandidates() {
                    const daily = state.weather?.daily;
                    if (!daily?.time?.length)
                        return [];
                    const today = daily.time[0];
                    const code = Number(daily.weather_code?.[0] || 0);
                    const rain = Number(daily.precipitation_probability_max?.[0] || 0);
                    const wind = Number(daily.wind_gusts_10m_max?.[0] || 0);
                    const heat = Number(daily.temperature_2m_max?.[0] || 0);
                    const items = [];
                    if (state.notifications.severe !== false && [95, 96, 99].includes(code))
                        items.push({ key: `${today}:storm`, title: "" + meteonexaText("notifications.notificationcandidates.possible_thunderstorms"), body: "" + meteonexaText("notifications.check_radar_local_updates_before_going_out") });
                    if (state.notifications.rain !== false && rain >= state.thresholds.rain)
                        items.push({ key: `${today}:rain:${state.thresholds.rain}`, title: "" + meteonexaText("notifications.notificationcandidates.rain_above_threshold"), body: meteonexaText("notifications.maximum_probability_value_value", { value: Math.round(rain), location: shortLocationLabel() }) });
                    if (state.notifications.severe !== false && wind >= state.thresholds.wind)
                        items.push({ key: `${today}:wind:${state.thresholds.wind}`, title: "" + meteonexaText("notifications.notificationcandidates.gusts_above_threshold"), body: meteonexaText("notifications.gusts_forecast_up_value_km_h", { value: Math.round(wind) }) });
                    if (state.notifications.severe !== false && heat >= state.thresholds.heat)
                        items.push({ key: `${today}:heat:${state.thresholds.heat}`, title: "" + meteonexaText("notifications.notificationcandidates.heat_above_threshold"), body: meteonexaText("notifications.notificationcandidates.forecast_high_value", { value: temperature(heat) }) });
                    return items;
                }
                async function evaluateLocalWeatherNotifications() {
                    updateProfileNotificationBadge();
                    if (isGuestSession()) {
                        if ('clearAppBadge' in navigator) { try { await navigator.clearAppBadge(); } catch { } }
                        return;
                    }
                    const candidates = notificationCandidates();
                    if ('setAppBadge' in navigator) {
                        try {
                            candidates.length ? await navigator.setAppBadge(candidates.length) : await navigator.clearAppBadge();
                        }
                        catch { }
                    }
                    if (!state.notifications.enabled || notificationPermission() !== 'granted' || !candidates.length)
                        return;
                    state.notifications.lastSent ||= {};
                    const pending = candidates.filter(item => !state.notifications.lastSent[`${locationKey()}:${item.key}`]);
                    if (!pending.length)
                        return;
                    const first = pending[0];
                    const extra = pending.length > 1 ? ` ${t(meteonexaText("notifications.value_more_events_alert_center", { count: pending.length - 1 }))}` : '';
                    try {
                        await sendDeviceNotification(first.title, first.body + extra, { tag: `meteonexa-alert-${locationKey()}`, url: './#notifications' });
                        pending.forEach(item => { state.notifications.lastSent[`${locationKey()}:${item.key}`] = Date.now(); });
                        const entries = Object.entries(state.notifications.lastSent).sort((a, b) => b[1] - a[1]).slice(0, 80);
                        state.notifications.lastSent = Object.fromEntries(entries);
                        saveJSON(STORAGE.notifications, state.notifications);
                    }
                    catch (error) {
                        console.warn(meteonexaText("notifications.local_notification_not_sent"), error);
                    }
                }
        
                function updatePwaSettingsStatus() {
                    const action = $('#settings-pwa-action');
                    if (action) action.hidden = !isHandheldOrTabletDevice();
                    const status = $('#settings-pwa-status');
                    if (!status)
                        return;
                    status.textContent = isStandalonePWA()
                        ? meteonexaText('settings.pwa.status.installed')
                        : state.deferredInstallPrompt
                            ? meteonexaText('settings.pwa.status.available')
                            : meteonexaText('settings.pwa.status.browser');
                }
                function scheduleOptionalShellWarmup(registration) {
                    const send = () => {
                        const worker = navigator.serviceWorker?.controller || registration?.active;
                        if (!worker || typeof worker.postMessage !== 'function') return false;
                        worker.postMessage({ type: 'WARM_OPTIONAL_SHELL', language: String(state.settings?.language || 'it').slice(0, 5) });
                        return true;
                    };
                    const run = () => {
                        if (send()) return;
                        navigator.serviceWorker?.ready?.then(ready => send() || ready?.active?.postMessage?.({ type: 'WARM_OPTIONAL_SHELL', language: String(state.settings?.language || 'it').slice(0, 5) })).catch(() => null);
                    };
                    if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(run, { timeout: 3500 });
                    else window.setTimeout(run, 1200);
                }
                function registerPWA() {
                    const serviceWorker = navigator.serviceWorker;
                    if (serviceWorker && typeof serviceWorker.register === 'function') {
                        const registerServiceWorker = async () => {
                            try {
                                const registration = await serviceWorker.register(`./js/sw.js?v=${APP_BUILD}`, { scope: '/', updateViaCache: 'none' });
                                await registration.update().catch(() => null);
                                if (registration.waiting)
                                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                                scheduleOptionalShellWarmup(registration);
                                syncNotificationButton();
                            }
                            catch (error) {
                                console.warn(meteonexaText('log.sw.register_failed'), error);
                            }
                        };
                        if (document.readyState === 'complete')
                            void registerServiceWorker();
                        else
                            addEventListener('load', () => { void registerServiceWorker(); }, { once: true });
                        if (typeof serviceWorker.addEventListener === 'function') serviceWorker.addEventListener('message', event => {
                            if (event.data?.type === 'METEONEXA_SAFE_SYNC') {
                                updateNetworkStatus();
                            }
                        });
                        if (typeof serviceWorker.addEventListener === 'function') serviceWorker.addEventListener('controllerchange', () => {
                            scheduleOptionalShellWarmup(null);
                            // A newly activated worker must not reload an already booting page.
                            // All shell assets are build-versioned and navigation is network-first,
                            // so the next navigation naturally uses the new worker without causing
                            // the double bootstrap/refresh previously visible after each deploy.
                            syncNotificationButton();
                            updatePwaSettingsStatus();
                        });
                    }
                    addEventListener('beforeinstallprompt', event => {
                        event.preventDefault();
                        state.deferredInstallPrompt = event;
                        updatePwaSettingsStatus();
                    });
                    addEventListener('appinstalled', () => {
                        state.deferredInstallPrompt = null;
                        updatePwaSettingsStatus();
                        showToast("" + meteonexaText("notifications.registerpwa.meteonexa_installed"), "" + meteonexaText("notifications.app_now_available_device"), 'success');
                        syncNotificationButton();
                    });
                }
                async function installPWA() {
                    await withLoader("" + meteonexaText("notifications.installpwa.app_installation"), "" + meteonexaText("notifications.installpwa.preparing_meteonexa_device"), async () => {
                        if (state.deferredInstallPrompt) {
                            state.deferredInstallPrompt.prompt();
                            const choice = await state.deferredInstallPrompt.userChoice;
                            if (choice.outcome === 'accepted')
                                showToast("" + meteonexaText("notifications.installpwa.installation_started"), "" + meteonexaText("notifications.installpwa.follow_instructions_device"), 'success');
                            state.deferredInstallPrompt = null;
                            updatePwaSettingsStatus();
                            return;
                        }
                        const apple = /iphone|ipad|ipod/i.test(navigator.userAgent);
                        showToast("" + meteonexaText("notifications.installpwa.manual_installation"), apple ? "" + meteonexaText("notifications.safari_share_add_home_screen") : "" + meteonexaText("notifications.open_browser_menu_choose_install_app"), 'info', 6500);
                    }, 400);
                }
        
                function bindNotificationEvents() {
                    $('#settings-notifications')?.addEventListener('click', () => {
                        $('#settings-dialog')?.close();
                        openNotificationCenter();
                    });
                    $('#settings-pwa-action')?.addEventListener('click', () => {
                        if (!isHandheldOrTabletDevice()) return;
                        installPWA();
                    });
                    $('#notification-button')?.addEventListener('click', openNotificationCenter);
                    $('#notification-primary')?.addEventListener('click', enableNotifications);
                    $('#notification-test')?.addEventListener('click', () => withLoader(
                        t('notification.test.title'),
                        t('notifications.testdevicenotification.notifications_working_value', { location: shortLocationLabel() }),
                        testDeviceNotification,
                        360
                    ));
                    $('#notification-install')?.addEventListener('click', () => {
                        if (isHandheldOrTabletDevice()) installPWA();
                    });
                    $('#notify-severe')?.addEventListener('change', saveNotificationPreferences);
                    $('#notify-rain')?.addEventListener('change', saveNotificationPreferences);
                    $('#notification-dialog')?.addEventListener('close', syncNotificationButton);
                    $('#alerts-jump-settings')?.addEventListener('click', openNotificationCenter);
                    $('#notification-inbox-mark-all')?.addEventListener('click', () => updateNotificationInbox('read-all').catch(() => {}));
                    $('#notification-inbox-list')?.addEventListener('click', event => {
                        const item = event.target.closest('[data-notification-id]');
                        if (!item) return;
                        if (event.target.closest('[data-notification-read]')) {
                            updateNotificationInbox('read', item.dataset.notificationId).catch(() => {});
                        } else if (event.target.closest('[data-notification-dismiss]')) {
                            updateNotificationInbox('dismiss', item.dataset.notificationId).catch(() => {});
                        }
                    });
                }
        
                return Object.freeze({
                    isIOSDevice, isAndroidDevice, isHandheldOrTabletDevice, isStandalonePWA,
                    notificationPermission, notificationEnvironment, updateNotificationCenterUI,
                    updateProfileNotificationBadge, syncNotificationButton, renderNotificationOfficialAlert,
                    setNotificationCenterAudience, formatNotificationInboxTime, renderNotificationInbox,
                    loadNotificationInbox, updateNotificationInbox, openNotificationCenter,
                    sendDeviceNotification, enableNotifications, testDeviceNotification,
                    saveNotificationPreferences, notificationCandidates, evaluateLocalWeatherNotifications,
                    updatePwaSettingsStatus, registerPWA, installPWA, bindNotificationEvents
                });
            };
        
            provided.notifications = Object.freeze({ create });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
