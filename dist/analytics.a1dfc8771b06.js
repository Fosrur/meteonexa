'use strict';
(() => {
  const SERVICES = window.MeteoNexaServices || null;
  const STORAGE_KEY = 'meteonexa_analytics_optout_v1';
  const CONFIG_URL = 'api/analytics/config.php';
  const DEFAULT_ROUTE = 'home';
  const ROUTES = new Set(['home','radar','favorites','details','history','intelligence','advanced','bug-report','route','devices','privacy','cookie-policy']);
  const ACQUISITION_KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ref','source'];
  const status = {
    ready: false,
    configured: false,
    enabled: false,
    optedOut: false,
    privacySignal: false,
    provider: 'plausible',
    sent: 0,
    failed: 0,
    lastRoute: '',
    lastError: '',
  };
  let config = null;
  let firstPageview = true;

  function localOptOut() {
    try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch { return false; }
  }
  function privacySignalActive() {
    const gpc = navigator.globalPrivacyControl === true;
    const dnt = String(navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack || '') === '1';
    return gpc || dnt;
  }
  function effectiveOptOut() {
    return localOptOut() || privacySignalActive();
  }
  function setOptOut(value) {
    try {
      if (value) localStorage.setItem(STORAGE_KEY, '1');
      else localStorage.removeItem(STORAGE_KEY);
    } catch { }
    syncStatus();
    updatePrivacyControl();
    document.dispatchEvent(new CustomEvent('meteonexa:analytics-preference', { detail: publicStatus() }));
    if (!value && status.enabled) sendPageview(currentRoute());
  }
  function syncStatus() {
    status.privacySignal = privacySignalActive();
    status.optedOut = effectiveOptOut();
    status.configured = Boolean(config?.enabled && config?.domain && config?.endpoint);
    status.enabled = status.configured && !status.optedOut;
  }
  function publicStatus() {
    return Object.freeze({ ...status, domain: config?.domain || '', endpointHost: safeHost(config?.endpoint || '') });
  }
  function safeHost(value) {
    try { return new URL(value).host; } catch { return ''; }
  }
  function routeName(value) {
    const route = String(value || '').trim().toLowerCase();
    return ROUTES.has(route) ? route : DEFAULT_ROUTE;
  }
  function acquisitionSearch() {
    if (!firstPageview) return '';
    const source = new URLSearchParams(location.search || '');
    const clean = new URLSearchParams();
    for (const key of ACQUISITION_KEYS) {
      const value = String(source.get(key) || '').trim();
      if (!value) continue;
      clean.set(key, value.slice(0, 160));
    }
    const text = clean.toString();
    return text ? `?${text}` : '';
  }
  function analyticsUrl(route) {
    const protocol = location.protocol === 'https:' ? 'https:' : 'https:';
    return `${protocol}//${config.domain}/${encodeURIComponent(routeName(route))}${acquisitionSearch()}`;
  }
  function safeReferrer() {
    if (!firstPageview || !document.referrer) return '';
    try {
      const ref = new URL(document.referrer);
      if (!/^https?:$/.test(ref.protocol)) return '';
      if (ref.hostname === location.hostname) return '';
      return `${ref.protocol}//${ref.hostname}/`;
    } catch { return ''; }
  }
  async function sendPageview(route) {
    syncStatus();
    if (!status.enabled || document.visibilityState === 'prerender') return false;
    const payload = {
      name: 'pageview',
      url: analyticsUrl(route),
      domain: config.domain,
    };
    const referrer = safeReferrer();
    if (referrer) payload.referrer = referrer;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 2500);
    try {
      const response = await fetch(config.endpoint, {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`HTTP_${response.status}`);
      status.sent += 1;
      status.lastError = '';
      status.lastRoute = routeName(route);
      firstPageview = false;
      document.dispatchEvent(new CustomEvent('meteonexa:analytics-delivered', { detail: publicStatus() }));
      return true;
    } catch (error) {
      status.failed += 1;
      status.lastError = String(error?.name === 'AbortError' ? 'TIMEOUT' : error?.message || error || 'ANALYTICS_FAILED').slice(0, 80);
      return false;
    } finally {
      clearTimeout(timeout);
    }
  }
  function updatePrivacyControl() {
    const button = document.getElementById('privacy-analytics-toggle');
    const copy = document.getElementById('privacy-analytics-status');
    if (!button || !copy) return;
    syncStatus();
    button.setAttribute('aria-checked', status.enabled ? 'true' : 'false');
    button.disabled = status.privacySignal;
    copy.setAttribute('data-i18n-key', status.privacySignal
      ? 'privacy.analytics.status.signal'
      : status.enabled ? 'privacy.analytics.status.on' : 'privacy.analytics.status.off');
    window.MeteoNexaI18n?.apply?.(copy);
  }
  async function loadConfig() {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 1800);
    try {
      const response = await fetch(CONFIG_URL, { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      if (!response.ok) throw new Error(`HTTP_${response.status}`);
      const data = await response.json();
      const endpoint = String(data?.endpoint || '');
      const endpointUrl = new URL(endpoint);
      const domain = String(data?.domain || '').trim().toLowerCase();
      if (data?.provider !== 'plausible' || endpointUrl.protocol !== 'https:' || endpointUrl.hostname !== 'plausible.io' || !/^[a-z0-9.-]+$/.test(domain)) {
        throw new Error('CONFIG_INVALID');
      }
      config = { enabled: data.enabled === true, provider: 'plausible', endpoint: endpointUrl.href, domain };
      status.ready = true;
      syncStatus();
      updatePrivacyControl();
      return true;
    } catch (error) {
      status.ready = true;
      status.lastError = String(error?.name === 'AbortError' ? 'CONFIG_TIMEOUT' : error?.message || error || 'CONFIG_FAILED').slice(0, 80);
      config = null;
      syncStatus();
      updatePrivacyControl();
      return false;
    } finally {
      clearTimeout(timeout);
    }
  }
  function currentRoute() {
    const path = String(location.pathname || '').toLowerCase();
    if (path.endsWith('/privacy.html')) return 'privacy';
    if (path.endsWith('/cookie-policy.html')) return 'cookie-policy';
    return routeName(SERVICES?.get?.('core')?.getState?.()?.navigation?.currentPage || location.hash.replace(/^#/, '') || DEFAULT_ROUTE);
  }
  function bindNavigation() {
    let observedRoute = '';
    const onState = event => {
      const route = routeName(event?.detail?.state?.navigation?.currentPage || currentRoute());
      if (route === observedRoute) return;
      observedRoute = route;
      sendPageview(route);
    };
    document.addEventListener('meteonexa:store:changed', onState);
    window.addEventListener('hashchange', () => {
      const route = currentRoute();
      if (route === observedRoute) return;
      observedRoute = route;
      sendPageview(route);
    });
    window.setTimeout(() => {
      const route = currentRoute();
      if (route !== observedRoute) {
        observedRoute = route;
        sendPageview(route);
      }
    }, 1200);
  }
  function bindPrivacyControl() {
    const button = document.getElementById('privacy-analytics-toggle');
    if (!button) return;
    button.addEventListener('click', () => {
      if (privacySignalActive()) return updatePrivacyControl();
      setOptOut(button.getAttribute('aria-checked') === 'true');
    });
    updatePrivacyControl();
  }
  async function init() {
    bindPrivacyControl();
    await loadConfig();
    bindNavigation();
    updatePrivacyControl();
  }

  const analyticsService = Object.freeze({
    init,
    trackPage: route => sendPageview(route),
    setOptOut,
    isOptedOut: effectiveOptOut,
    status: publicStatus,
  });
  if (SERVICES?.publish) SERVICES.publish('analytics', analyticsService);
  else window.MeteoNexaAnalytics = analyticsService;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
