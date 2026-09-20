'use strict';
(() => {
  const supported = new Set(['it','en','fr','es','de']);
  const cookieLanguage = document.cookie.match(/(?:^|;\s*)meteonexa_language=([^;]+)/)?.[1] || '';
  const storedLanguage = (() => { try { return localStorage.getItem('meteonexa_language') || localStorage.getItem('language') || ''; } catch { return ''; } })();
  const candidate = String(cookieLanguage || storedLanguage || navigator.language || 'it').toLowerCase().split(/[-_]/)[0];
  const language = supported.has(candidate) ? candidate : 'it';
  document.documentElement.lang = language;
  const interpolate = (value, params = {}) => Object.entries(params).reduce((text,[key,replacement]) => text.replaceAll(`{${key}}`, String(replacement)), String(value ?? ''));
  fetch(`/assets/i18n/${language}.json`, { cache: 'no-store', credentials: 'same-origin' })
    .then(response => response.ok ? response.json() : Promise.reject(new Error('CATALOG_UNAVAILABLE')))
    .then(payload => {
      const catalog = payload?.translations || {};
      document.querySelectorAll('[data-i18n]').forEach(element => {
        const key = element.dataset.i18n;
        element.textContent = interpolate(catalog[key] ?? key);
      });
      document.title = catalog['maintenance.page_title'] || document.title;
    })
    .catch(() => null);

  let checking = false;
  const button = document.getElementById('maintenance-retry');
  const checkRelease = async ({ reloadOnFailure = false } = {}) => {
    if (checking) return;
    checking = true;
    button?.classList.add('is-checking');
    try {
      const response = await fetch(`/?maintenance_probe=${Date.now()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
      });
      if (response.ok) {
        location.reload();
        return;
      }
      if (reloadOnFailure) location.reload();
    } catch {
      if (reloadOnFailure) location.reload();
    } finally {
      checking = false;
      button?.classList.remove('is-checking');
    }
  };

  button?.addEventListener('click', () => checkRelease({ reloadOnFailure: true }));
  window.setInterval(() => checkRelease(), 15000);
})();
