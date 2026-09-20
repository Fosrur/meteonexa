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
    window.__meteonexaInteractiveReady = window.__METEONEXA_INTERACTIVE_READY__ === true;

    document.addEventListener('meteonexa:ready', () => {
      window.__meteonexaQaReady = true;
    }, { once: true });

    document.addEventListener('meteonexa:interactive-ready', () => {
      window.__meteonexaInteractiveReady = true;
    }, { once: true });
  }, { shouldClearStorage: clearStorage });
}

export async function waitForMeteoNexaReady(page, timeout = 15000) {
  await page.waitForFunction(() => window.__meteonexaQaReady === true, null, { timeout });
}

export async function waitForMeteoNexaInteractiveReady(page, timeout = 15000) {
  await page.waitForFunction(() =>
    window.__meteonexaQaReady === true &&
    (window.__meteonexaInteractiveReady === true || window.__METEONEXA_INTERACTIVE_READY__ === true),
  null, { timeout });
}
