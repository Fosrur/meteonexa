export function createPwaNotifications(context) {
    const {
        window, state, APP_BUILD, $, isHandheldOrTabletDevice, isStandalonePWA,
        syncNotificationButton, updateNetworkStatus, meteonexaText, showToast, withLoader
    } = context;
    if (!state || typeof $ !== 'function') {
        throw new Error('METEONEXA_PWA_NOTIFICATIONS_CONTEXT_INVALID');
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

    return Object.freeze({ updatePwaSettingsStatus, scheduleOptionalShellWarmup, registerPWA, installPWA });
}
