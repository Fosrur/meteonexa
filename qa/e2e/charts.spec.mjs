import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

async function loadBuiltSource(page, logicalPath) {
  const manifestResponse = await page.request.get('/asset-manifest.json');
  expect(manifestResponse.ok()).toBeTruthy();
  const manifest = await manifestResponse.json();
  const source = manifest[logicalPath];
  expect(source, `missing ${logicalPath} in asset manifest`).toBeTruthy();

  const response = await page.request.get(String(source).replace(/^\.\//, '/'));
  expect(response.ok()).toBeTruthy();
  return response.text();
}

async function assertInteractiveChart(page, canvasSelector) {
  const canvas = page.locator(canvasSelector);
  await expect(canvas).toBeVisible();

  const parent = canvas.locator('..');
  const hit = parent.locator('.chart-interaction-layer');
  await expect(hit).toBeVisible();

  const box = await hit.boundingBox();
  expect(box && box.width > 80 && box.height > 40).toBeTruthy();

  await hit.hover({
    position: {
      x: Math.round(box.width * 0.45),
      y: Math.round(box.height * 0.5),
    },
  });

  const tooltip = page.locator('#chart-tooltip');
  await expect(tooltip).toBeVisible();
  await expect(tooltip).not.toHaveText('');

  // Exercise the chart layer's real click handler directly. Playwright's
  // locator/page mouse actions can introduce actionability scrolling and
  // pointer/mousedown events; MeteoNexa deliberately dismisses chart tooltips
  // on viewport movement/outside pointer-down. Those side effects are not part
  // of the click-to-pin contract being asserted here.
  const clickPoint = {
    clientX: box.x + Math.round(box.width * 0.65),
    clientY: box.y + Math.round(box.height * 0.45),
  };
  await hit.evaluate((layer, point) => {
    layer.dispatchEvent(new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
      clientX: point.clientX,
      clientY: point.clientY,
      button: 0,
    }));
  }, clickPoint);

  await expect.poll(
    () => canvas.evaluate(node => Boolean(node._chartTooltipPinned)),
    { timeout: 2000 },
  ).toBeTruthy();
  await expect(tooltip).toBeVisible();

  await page.mouse.move(5, 5);
  await expect(tooltip).toBeVisible(); // click pins the tooltip

  await hit.focus();
  await page.keyboard.press('ArrowRight');
  await expect(tooltip).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(tooltip).toBeHidden();
}

test.beforeEach(async ({ page }) => {
  await prepareStableApp(page);
});

test.describe('Firefox-safe charts', () => {
  test('home chart supports hover, click pin and keyboard', async ({ page }) => {
    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#weather-app')).toBeVisible({ timeout: 5000 });
    await assertInteractiveChart(page, '#home-chart');
  });

  test('details chart supports hover and click', async ({ page }) => {
    await page.goto('?preview#details', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#page-details')).toBeVisible({ timeout: 5000 });
    await assertInteractiveChart(page, '#detail-chart');
  });

  test('history chart is wired to the common interaction layer', async ({ page }) => {
    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);

    const visualization = await loadBuiltSource(page, 'modules/esm/domains/visualization.mjs');
    const forecastHistory = await loadBuiltSource(page, 'modules/esm/domains/forecast-history.mjs');

    // Browser CI serves the production esbuild output, where local function
    // names are minified. Assert semantic runtime markers instead of source-only
    // identifier spelling so this test validates the built artifact itself.
    expect(visualization).toContain('chart-interaction-layer');
    expect(visualization).toContain('registerChartInteraction:');
    expect(forecastHistory).toContain('#history-chart');
    expect(forecastHistory).toContain('registerChartInteraction');
  });
});

test.describe('Mobile authentication recovery UX', () => {
  test('guest access does not focus the city search after one tap', async ({ page }) => {
    await page.goto('./', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);

    await expect(page.locator('#guest-login')).toBeVisible({ timeout: 5000 });
    await page.locator('#guest-login').click();
    await expect(page.locator('#location-view')).toHaveClass(/active/, { timeout: 5000 });

    await page.waitForTimeout(250);
    const activeId = await page.evaluate(() => document.activeElement?.id || '');
    expect(activeId).not.toBe('onboarding-city');
  });

  test('cache reset path cannot block on serviceWorker.ready', async ({ page }) => {
    await page.goto('./', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);

    const js = await loadBuiltSource(page, 'js/app.js');
    const start = js.indexOf('const PRESERVED_LOCAL_KEYS_ON_CACHE_RESET');
    const end = js.indexOf('async function requestGuestCacheReceiptProof()', start);

    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);

    const resetPath = js.slice(start, end);
    expect(resetPath).not.toContain('navigator.serviceWorker.ready');
    expect(resetPath).not.toContain('navigator.serviceWorker?.ready');
    expect(resetPath).toContain('settleBrowserOperation');
    expect(resetPath).toContain('clearMeteoNexaLocalRuntimeState({ preservePreferences: true })');
    expect(resetPath).toContain('Promise.allSettled([');
    expect(resetPath).toContain('unregisterMeteoNexaServiceWorkers()');
  });

  test('orphaned server email session does not rebuild login after local auth state is cleared', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('meteonexa_suite_device_id', 'device-regression-1234567890');
      localStorage.setItem('meteonexa_suite_device_key', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
      localStorage.removeItem('meteonexa_v3_session');
      sessionStorage.removeItem('meteonexa_force_auth_v1');
    });

    let logoutCalls = 0;
    await page.route('**/api/auth/status.php', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          authenticated: true,
          displayName: 'Regression User',
          email: 'regression@example.test',
          diagnosticsAllowed: false,
          smtpConfigured: true,
        }),
      });
    });
    await page.route('**/api/auth/logout.php', async route => {
      logoutCalls += 1;
      const body = route.request().postDataJSON();
      expect(body?.revokeTrustedDevice).toBe(true);
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, trustedDeviceRevoked: true }),
      });
    });

    await page.goto('./', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);

    await expect(page.locator('#guest-login')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#weather-app')).toBeHidden();
    await expect.poll(() => logoutCalls, { timeout: 5000 }).toBeGreaterThan(0);

    const localSession = await page.evaluate(() => localStorage.getItem('meteonexa_v3_session'));
    expect(localSession).toBeNull();
  });
});
