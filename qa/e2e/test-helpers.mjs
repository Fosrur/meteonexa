export async function prepareStableApp(page, { clearStorage = false } = {}) {
  await page.addInitScript(({ shouldClearStorage }) => {
    try {
      if (shouldClearStorage) {
        localStorage.clear();
        sessionStorage.clear();
      }
      localStorage.setItem('meteonexa_privacy_notice_v2', JSON.stringify({
        version: '20.1',
        acknowledgedAt: Date.now(),
      }));
    } catch {}

    window.__meteonexaQaReady = false;
    document.addEventListener('meteonexa:ready', () => {
      window.__meteonexaQaReady = true;
    }, { once: true });
  }, { shouldClearStorage: clearStorage });
}

export async function waitForMeteoNexaReady(page, timeout = 15000) {
  await page.waitForFunction(() => window.__meteonexaQaReady === true, null, { timeout });
}
