'use strict';
(() => {
    const DEVICE_ID_KEY = 'meteonexa_suite_device_id';
    const DEVICE_SECRET_KEY = 'meteonexa_suite_device_key';
    if (!window.crypto?.getRandomValues)
        throw new Error('SECURE_RANDOM_UNAVAILABLE');
    const randomToken = length => {
        const bytes = new Uint8Array(length);
        crypto.getRandomValues(bytes);
        let binary = '';
        bytes.forEach(value => { binary += String.fromCharCode(value); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const randomId = () => crypto.randomUUID?.() || randomToken(18);
    const randomSecret = () => {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        let binary = '';
        bytes.forEach(value => { binary += String.fromCharCode(value); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const storedDeviceId = String(localStorage.getItem(DEVICE_ID_KEY) || '');
    const storedDeviceKey = String(localStorage.getItem(DEVICE_SECRET_KEY) || '');
    const hadValidDeviceId = /^[A-Za-z0-9._-]{12,128}$/.test(storedDeviceId);
    const hadValidDeviceKey = /^[A-Za-z0-9_-]{32,128}$/.test(storedDeviceKey);
    let deviceId = storedDeviceId.replace(/[^A-Za-z0-9._-]/g, '');
    if (deviceId.length < 12) {
        deviceId = randomId().replace(/[^A-Za-z0-9._-]/g, '');
        localStorage.setItem(DEVICE_ID_KEY, deviceId);
    }
    let deviceKey = storedDeviceKey;
    if (!/^[A-Za-z0-9_-]{32,128}$/.test(deviceKey)) {
        deviceKey = randomSecret();
        localStorage.setItem(DEVICE_SECRET_KEY, deviceKey);
    }
    const headers = () => ({
        'X-MeteoNexa-Device-Id': deviceId,
        'X-MeteoNexa-Device-Key': deviceKey,
        'X-MeteoNexa-Client-Mode': ((window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true) ? 'pwa' : 'web'),
        'X-MeteoNexa-Client-Timezone': (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch { return ''; } })(),
    });
    window.MeteoNexaSecurity = Object.freeze({
        deviceId, deviceKey, headers,
        freshCredential: !(hadValidDeviceId && hadValidDeviceKey),
        credentialState: hadValidDeviceId && hadValidDeviceKey ? 'restored' : 'generated'
    });
})();
