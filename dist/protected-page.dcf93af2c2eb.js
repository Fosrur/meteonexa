'use strict';
(() => {
    const redirectToLogin = () => {
        try {
            location.replace(new URL('./', location.href).href);
        }
        catch {
            location.href = './';
        }
    };
    try {
        const forceAuthentication = sessionStorage.getItem('meteonexa_force_auth_v1') === '1';
        const rawSession = localStorage.getItem('meteonexa_v3_session');
        let session = null;
        if (rawSession) { try { session = JSON.parse(rawSession); } catch { session = null; } }
        const validSession = Boolean(session && typeof session === 'object' && session.type && Number.isFinite(Number(session.at)));
        if (forceAuthentication || !validSession) {
            redirectToLogin();
            return;
        }
        document.documentElement.classList.add('session-authorized');
    }
    catch {
        redirectToLogin();
    }
})();
