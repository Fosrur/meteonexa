'use strict';
(() => {
  const SERVICES = window.MeteoNexaServices;
  if (!SERVICES) throw new Error('METEONEXA_SERVICES_NOT_LOADED');
  const allowed = new Set(['page_home','page_radar','page_intelligence','search_location','open_alert','open_explainability','use_route','use_ai','enable_notifications','bug_report','install_pwa']);
  const pending = new Map();
  const once = new Set();
  let timer = 0;
  let flushing = false;

  function schedule() {
    if (timer) return;
    timer = window.setTimeout(() => { timer = 0; flush(); }, 8000);
  }

  function track(name, count = 1) {
    name = String(name || '');
    count = Number(count);
    if (!allowed.has(name) || !Number.isInteger(count) || count < 1) return false;
    pending.set(name, Math.min(50, (pending.get(name) || 0) + count));
    schedule();
    return true;
  }

  function trackOnce(name) {
    name = String(name || '');
    if (once.has(name)) return false;
    if (!track(name, 1)) return false;
    once.add(name);
    return true;
  }

  async function flush() {
    if (flushing || pending.size === 0) return;
    flushing = true;
    const batch = Object.fromEntries(pending.entries());
    pending.clear();
    try {
      const response = await fetch('api/metrics/product.php', {
        method: 'POST',
        credentials: 'omit',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'X-Requested-With':'MeteoNexaProductMetrics' },
        body: JSON.stringify({ events: batch })
      });
      if (!response.ok) throw new Error(`HTTP_${response.status}`);
    } catch {
      for (const [name,count] of Object.entries(batch)) pending.set(name, Math.min(50, (pending.get(name) || 0) + count));
      schedule();
    } finally {
      flushing = false;
    }
  }

  document.addEventListener('click', event => {
    if (event.target.closest?.('.home-severe-alert-action,.home-official-alert-action')) track('open_alert');
  });
  document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('#intelq-explain-panel');
    if (!panel || !('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting && entry.intersectionRatio >= 0.35)) {
        trackOnce('open_explainability');
        observer.disconnect();
      }
    }, { threshold:[0.35] });
    observer.observe(panel);
  });

  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
  window.addEventListener('pagehide', () => flush());
  SERVICES.publish('metrics', Object.freeze({ track, trackOnce, flush }));
})();
