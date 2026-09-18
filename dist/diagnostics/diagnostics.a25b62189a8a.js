'use strict';
(() => {
  const q = selector => document.querySelector(selector);
  const API = '../api/diagnostics/check.php';
  const state = { authenticated:false, busy:false };
  const results = q('#diag-results-list');
  const loader = q('#diag-loader');
  const app = q('#diag-app');
  const text = (key, params = {}) => window.MeteoNexaI18n?.tr?.(key, params) || window.meteonexaText?.(key, params) || (/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/i.test(String(key || '')) ? '' : String(key || ''));
  const securityHeaders = () => ({
    ...(window.MeteoNexaSecurity?.headers?.() || {}),
    'X-MeteoNexa-Language': document.documentElement.lang || window.MeteoNexaI18n?.state?.language || navigator.language || 'it'
  });

  function safe(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }

  function redirectError(status) {
    const code = [401,403,404,429,500,502,503,504].includes(Number(status)) ? Number(status) : 500;
    location.replace(`../error.php?code=${code}`);
  }

  function setBusy(button, active) {
    state.busy = active;
    loader?.classList.toggle('active', active);
    loader?.setAttribute('aria-hidden', String(!active));
    document.querySelectorAll('[data-diag-action]').forEach(node => {
      node.disabled = active;
      node.classList.toggle('busy', active && node === button);
      node.setAttribute('aria-busy', active && node === button ? 'true' : 'false');
    });
  }

  function addResult(title, detail = '', status = 'ok', data = null) {
    q('.diag-empty')?.remove();
    const article = document.createElement('article');
    article.className = `diag-result ${status}`;
    const icon = status === 'ok' ? '✓' : status === 'warn' ? '!' : '×';
    let extra = '';
    if (data !== null) {
      try { extra = `<pre>${safe(JSON.stringify(data, null, 2))}</pre>`; } catch {}
    }
    article.innerHTML = `<span class="diag-result-icon" aria-hidden="true">${icon}</span><div><strong>${safe(title)}</strong>${detail ? `<p>${safe(detail)}</p>` : ''}${extra}</div>`;
    results?.prepend(article);
  }

  function addTranslatedResult(titleKey, detailKey = '', status = 'ok', params = {}, data = null) {
    addResult(text(titleKey, params), detailKey ? text(detailKey, params) : '', status, data);
  }

  async function jsonFetch(url, options = {}) {
    const response = await fetch(url, {
      cache:'no-store', credentials:'same-origin', redirect:'follow', ...options,
      headers:{ Accept:'application/json', 'X-Requested-With':'MeteoNexaDiagnostics', ...securityHeaders(), ...(options.headers || {}) }
    });
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    const raw = (await response.text()).replace(/^\uFEFF/, '').trim();
    let data = null;
    if (raw !== '') {
      const looksJson = contentType.includes('application/json') || raw.startsWith('{') || raw.startsWith('[');
      if (looksJson) {
        try { data = JSON.parse(raw); }
        catch {
          const error = new Error(text('diag.result.invalid_json'));
          error.code = 'INVALID_JSON_RESPONSE';
          error.status = response.status || 502;
          error.data = { status:response.status, contentType:contentType || 'unknown' };
          throw error;
        }
      } else {
        const error = new Error(text('diag.result.non_json'));
        error.code = 'NON_JSON_RESPONSE';
        error.status = response.status || 502;
        error.data = { status:response.status, contentType:contentType || 'unknown' };
        throw error;
      }
    } else data = {};
    if (!response.ok || data?.ok === false) {
      const error = new Error(String(data?.message ? text(data.message) : (data?.code || `HTTP ${response.status}`)));
      error.data = data; error.status = response.status; error.code = data?.code || ''; throw error;
    }
    return data;
  }

  async function checkAuth() {
    try {
      const data = await jsonFetch('../api/auth/status.php');
      state.authenticated = Boolean(data.authenticated);
      if (!state.authenticated) return redirectError(401);
      if (data.diagnosticsAllowed !== true) return redirectError(403);
      if (app) app.hidden = false;
      document.body.classList.remove('diag-booting');
      document.body.classList.add('diag-ready');
      document.title = text('diag.browser_title');
      return true;
    } catch (error) {
      redirectError(error.status || 503);
      return false;
    }
  }

  const postAction = action => jsonFetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ action }) });
  const actionAi = () => jsonFetch('../api/ai/chat.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ message:text('diag.ai.prompt'), messages:[], context:{generatedAt:new Date().toISOString()}, language:document.documentElement.lang || 'it' })
  });
  const actionPush = () => jsonFetch('../api/push/test.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ deviceId:window.MeteoNexaSecurity?.deviceId || '' })
  });

  async function run(button) {
    if (state.busy || !state.authenticated) return;
    const name = button.dataset.diagAction;
    setBusy(button, true);
    try {
      let data;
      if (name === 'ai') data = await actionAi();
      else if (name === 'push') data = await actionPush();
      else data = await postAction(name);

      if (name === 'summary') {
        (data.checks || []).forEach(row => addTranslatedResult(
          row.labelKey || 'diag.result.generic.title',
          row.detailKey || '',
          row.ok === true ? 'ok' : row.ok === false ? 'bad' : 'warn',
          row.params || {},
          row.meta || null
        ));
      } else if (name === 'ai') {
        addTranslatedResult('diag.result.ai.real.title','diag.result.ai.real.ok','ok',{}, { provider:data.provider, model:data.model, answer:data.answer });
      } else if (name === 'smtp') {
        addTranslatedResult('diag.result.smtp.test.title', data.transport === 'smtp' ? 'diag.result.smtp.test.smtp' : 'diag.result.smtp.test.local', 'ok');
      } else if (name === 'push') {
        addTranslatedResult('diag.result.push.test.title','diag.result.push.test.ok','ok',{ status:data.status || 'OK' });
      } else if (name === 'weather') {
        addTranslatedResult('diag.result.weather.test.title','diag.result.weather.test.ok','ok',{ elapsed:data.elapsedMs || 0 }, { source:data.source || 'Open-Meteo' });
      } else if (name === 'engine') {
        addTranslatedResult('diag.result.engine.test.title','diag.result.engine.test.ok','ok',{}, data.event || data.analysis || data);
      } else if (name === 'quality') {
        addTranslatedResult('diag.result.quality.test.title','diag.result.quality.test.ok','ok',{}, data.checks || data);
      } else if (name === 'metrics') {
        addTranslatedResult('diag.result.metrics.test.title','diag.result.metrics.test.ok','ok',{ days:data.windowDays || 30 }, { totals:data.totals || [], daily:data.daily || [], privacy:data.privacy || {} });
      } else if (name === 'analytics') {
        addTranslatedResult('diag.result.analytics.test.title', data.enabled ? 'diag.result.analytics.test.ok' : 'diag.result.analytics.disabled', data.enabled ? 'ok' : 'warn', {}, data);
      } else if (name === 'calibration') {
        addTranslatedResult('diag.result.calibration.test.title','diag.result.calibration.test.ok','ok',{}, data);
      } else if (name === 'authdevices') {
        addTranslatedResult('diag.result.authdevices.test.title','diag.result.authdevices.test.ok','ok',{}, data);
      }
    } catch (error) {
      if (error.status === 401 || error.status === 403) return redirectError(error.status);
      const optional = name === 'push' && error.code === 'NOT_SUBSCRIBED';
      addResult(text('diag.result.failed',{ action:text(`diag.action.${name}`) }), error.message, optional ? 'warn' : 'bad', error.data || null);
    } finally {
      setBusy(button, false);
    }
  }

  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-diag-action]');
    if (button) run(button);
  });
  q('#diag-clear')?.addEventListener('click', () => {
    if (results) results.innerHTML = `<p class="diag-empty">${safe(text('diag.results.empty'))}</p>`;
  });

  (async () => {
    try { await window.MeteoNexaI18n?.ready; } catch {}
    await checkAuth();
  })();
})();
