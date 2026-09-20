'use strict';
(() => {
    const SESSION_KEY = 'meteonexa_v3_session';
    const ALLOWED_RETURNS = new Set(['home','radar','favorites','details','history','intelligence','advanced','bug-report','route','devices']);
    const DEFAULT_VISIBILITY = Object.freeze({
        'feature.assistant': { guest: true, authenticated: true },
        'feature.ai.briefing': { guest: false, authenticated: true },
        'feature.ai.proactive': { guest: false, authenticated: true },
        'feature.notifications': { guest: false, authenticated: true },
        'feature.integrations': { guest: false, authenticated: true },
        'feature.model.accuracy': { guest: false, authenticated: true },
        'feature.radar.predictive': { guest: true, authenticated: true },
        'feature.smart.demo': { guest: true, authenticated: true },
        'feature.smart.alerts': { guest: false, authenticated: true },
        'feature.official.alerts': { guest: true, authenticated: true },
        'feature.hyperlocal': { guest: false, authenticated: true }
    });

    const readLocalSession = () => {
        try {
            const value = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
            return value && value.type === 'email' ? value : null;
        }
        catch { return null; }
    };

    const normalizedReturnHash = () => {
        const params = new URLSearchParams(location.search);
        const raw = String(params.get('return') || '').trim();
        let hash = raw;
        try { hash = decodeURIComponent(raw); } catch { }
        if (hash.startsWith('./')) hash = hash.slice(2);
        if (hash.startsWith('/')) hash = hash.slice(1);
        if (!hash.startsWith('#')) hash = `#${hash.replace(/^#+/, '')}`;
        const page = hash.slice(1).split(/[?&/]/, 1)[0].toLowerCase();
        return ALLOWED_RETURNS.has(page) ? `#${page}` : '#home';
    };

    const setBackTarget = () => {
        const back = document.getElementById('privacy-back');
        if (!back) return;
        const hash = normalizedReturnHash();
        const target = `./${hash}`;
        back.href = target;
        back.addEventListener('click', event => {
            event.preventDefault();
            location.assign(target);
        });
    };

    const requestJson = async (url, { secure = false } = {}) => {
        const headers = { Accept: 'application/json' };
        if (secure && window.MeteoNexaSecurity?.headers)
            Object.assign(headers, window.MeteoNexaSecurity.headers());
        const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}_=${Date.now()}`, {
            credentials: 'same-origin', cache: 'no-store', headers
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) throw new Error(payload?.code || `HTTP_${response.status}`);
        return payload;
    };

    const featureVisible = (features, featureKey, mode) => {
        const configured = features?.[featureKey];
        if (configured && typeof configured[mode] === 'boolean') return configured[mode];
        const fallback = DEFAULT_VISIBILITY[featureKey];
        if (fallback && typeof fallback[mode] === 'boolean') return fallback[mode];
        return mode === 'authenticated';
    };

    const setKey = (id, key) => {
        const node = document.getElementById(id);
        if (!node) return;
        node.setAttribute('data-i18n-key', key);
        node.textContent = '';
    };

    const serviceEnabled = (services, serviceKey, fallback = false) =>
        typeof services?.[serviceKey] === 'boolean' ? services[serviceKey] : fallback;

    const applyPrivacyContext = ({ authenticated, verified, features = {}, services = {}, contact = {} }) => {
        const mode = authenticated ? 'authenticated' : 'guest';
        document.body.dataset.accessMode = mode;
        document.body.dataset.sessionVerified = verified ? 'true' : 'false';
        const controllerName = String(contact?.controllerName || '').trim();
        const controllerAddress = String(contact?.controllerAddress || '').trim();
        const privacyEmail = String(contact?.privacyEmail || '').trim();
        const dpoEmail = String(contact?.dpoEmail || '').trim();
        const siteUrl = String(contact?.siteUrl || 'https://www.meteonexa.com/').trim();
        const legalConfigured = contact?.legalConfigured === true;
        const aiProvider = String(contact?.aiProvider || '').trim().toLowerCase();
        const controllerDetails = document.getElementById('privacy-controller-details');
        const controllerNameNode = document.getElementById('privacy-controller-name');
        const controllerAddressNode = document.getElementById('privacy-controller-address');
        const controllerSite = document.getElementById('privacy-controller-site');
        const privacyLink = document.getElementById('privacy-contact-email');
        const privacySeparator = document.getElementById('privacy-contact-separator');
        if (privacyLink) {
            const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(privacyEmail);
            privacyLink.hidden = !validEmail;
            if (validEmail) {
                privacyLink.href = `mailto:${privacyEmail}`;
                privacyLink.textContent = privacyEmail;
            }
            else {
                privacyLink.removeAttribute('href');
                privacyLink.textContent = '';
            }
            if (privacySeparator) privacySeparator.hidden = !validEmail;
        }
        if (controllerNameNode) controllerNameNode.textContent = controllerName;
        if (controllerAddressNode) controllerAddressNode.textContent = controllerAddress;
        if (controllerDetails) controllerDetails.hidden = !legalConfigured;
        if (controllerSite) {
            try {
                const parsed = new URL(siteUrl, location.href);
                controllerSite.href = parsed.protocol === 'https:' ? parsed.href : 'https://www.meteonexa.com/';
                controllerSite.textContent = parsed.protocol === 'https:' ? parsed.host : 'www.meteonexa.com';
            } catch {
                controllerSite.href = 'https://www.meteonexa.com/';
                controllerSite.textContent = 'www.meteonexa.com';
            }
        }
        const dpoLink = document.getElementById('privacy-dpo-email');
        const validDpo = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dpoEmail);
        if (dpoLink) {
            dpoLink.hidden = !validDpo;
            if (validDpo) { dpoLink.href = `mailto:${dpoEmail}`; dpoLink.textContent = dpoEmail; }
            else { dpoLink.removeAttribute('href'); dpoLink.textContent = ''; }
        }
        if (validDpo) setKey('privacy-dpo-copy', 'privacy.art13.dpo.configured');

        const providerLinks = document.getElementById('privacy-ai-provider-links');
        const openrouterLink = document.getElementById('privacy-openrouter-link');
        const openrouterSeparator = document.getElementById('privacy-openrouter-separator');
        const nvidiaLink = document.getElementById('privacy-nvidia-link');
        const groqLink = document.getElementById('privacy-groq-link');
        const showOpenRouter = aiProvider === 'openrouter';
        const showGroq = aiProvider === 'groq';
        if (providerLinks) providerLinks.hidden = !(showOpenRouter || showGroq);
        if (openrouterLink) openrouterLink.hidden = !showOpenRouter;
        if (openrouterSeparator) openrouterSeparator.hidden = !showOpenRouter;
        if (nvidiaLink) nvidiaLink.hidden = !showOpenRouter;
        if (groqLink) groqLink.hidden = !showGroq;

        const offlineRemembered = authenticated && !verified;
        setKey('privacy-context-title', offlineRemembered
            ? 'privacy.context.offline.title'
            : authenticated ? 'privacy.context.auth.title' : 'privacy.context.guest.title');
        setKey('privacy-context-copy', offlineRemembered
            ? 'privacy.context.offline.copy'
            : authenticated ? 'privacy.context.auth.copy' : 'privacy.context.guest.copy');
        setKey('privacy-session-data', authenticated ? 'privacy.sign_session_email_sign_email_address_display_name' : 'privacy.storage.session.guest');
        const emailAvailable = serviceEnabled(services, 'email', false);
        const pushAvailable = serviceEnabled(services, 'push', false)
            && featureVisible(features, 'feature.notifications', mode);
        const externalAiConfigured = serviceEnabled(services, 'ai.external', false);

        setKey('privacy-external-ai', !authenticated
            ? 'privacy.ai.external_service.guest'
            : externalAiConfigured ? 'privacy.ai.external_service' : 'privacy.ai.external_service.disabled');
        setKey('privacy-ai-intro', authenticated ? 'privacy.ai.intro' : 'privacy.ai.guest.intro');
        setKey('privacy-ensemble-learning', authenticated ? 'privacy.ensemble.learning' : 'privacy.ensemble.guest');
        setKey('privacy-offline-sync', authenticated ? 'privacy.offline.sync' : 'privacy.offline.guest');
        setKey('privacy-email-copy', !emailAvailable
            ? 'privacy.email.disabled'
            : authenticated ? 'privacy.email.auth.v2' : 'privacy.email.guest.v2');
        setKey('privacy-notifications-copy', !authenticated
            ? 'privacy.notifications.guest.v2'
            : pushAvailable ? 'privacy.notifications.auth' : 'privacy.notifications.disabled');

        document.querySelectorAll('[data-auth-only]').forEach(node => { node.hidden = !authenticated; });
        // Access-history details contain authenticated-session metadata and must
        // never be exposed from a merely remembered/offline local session.
        document.querySelectorAll('[data-email-auth-only]').forEach(node => { node.hidden = !(authenticated && verified); });
        document.querySelectorAll('[data-guest-only]').forEach(node => { node.hidden = authenticated; });
        document.querySelectorAll('[data-service]').forEach(node => {
            const feature = String(node.getAttribute('data-service') || '').trim();
            node.hidden = feature ? !featureVisible(features, feature, mode) : false;
        });
        document.querySelectorAll('[data-provider-service]').forEach(node => {
            const service = String(node.getAttribute('data-provider-service') || '').trim();
            if (service && !serviceEnabled(services, service, false)) node.hidden = true;
        });

        const aiFeatures = ['feature.assistant','feature.ai.briefing','feature.ai.proactive'];
        const aiAvailable = authenticated && externalAiConfigured
            && aiFeatures.some(feature => featureVisible(features, feature, mode));
        document.querySelectorAll('[data-ai-service]').forEach(node => { node.hidden = !aiAvailable; });
        if (authenticated && !aiAvailable)
            setKey('privacy-ai-intro', 'privacy.ai.auth.disabled');

        window.MeteoNexaI18n?.apply?.(document);
    };

    const boot = async () => {
        setBackTarget();
        const localEmailSession = Boolean(readLocalSession());
        let authenticated = false;
        let verified = false;
        let features = {};
        let services = {};
        let contact = {};

        if (navigator.onLine !== false) {
            const [statusResult, uiResult] = await Promise.allSettled([
                requestJson('api/auth/status.php', { secure: true }),
                requestJson('api/ui-config.php')
            ]);
            if (statusResult.status === 'fulfilled') {
                authenticated = statusResult.value.authenticated === true;
                verified = true;
            }
            if (uiResult.status === 'fulfilled') {
                if (uiResult.value.features && typeof uiResult.value.features === 'object')
                    features = uiResult.value.features;
                if (uiResult.value.services && typeof uiResult.value.services === 'object')
                    services = uiResult.value.services;
                if (uiResult.value.contact && typeof uiResult.value.contact === 'object')
                    contact = uiResult.value.contact;
            }
        }
        else if (localEmailSession) {
            // Offline we can only describe the locally remembered access mode.
            // We never claim the server session is verified until it is checked.
            authenticated = true;
            verified = false;
        }

        // A failed online status check is treated conservatively as guest: the
        // privacy page must never claim private/AI processing without proof.
        applyPrivacyContext({ authenticated, verified, features, services, contact });
    };

    document.addEventListener('DOMContentLoaded', async () => {
        try { await window.MeteoNexaI18n?.ready; } catch { }
        await boot();
    }, { once: true });
})();
