import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';


async function installWelcomePaintProbe(page) {
  await page.addInitScript(() => {
    window.__meteonexaWelcomeEverVisible = false;
    const sample = () => {
      const welcome = document.getElementById('welcome');
      if (welcome) {
        const style = getComputedStyle(welcome);
        const rect = welcome.getBoundingClientRect();
        const visible = !welcome.hidden
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && Number(style.opacity || '1') > 0
          && rect.width > 0
          && rect.height > 0;
        if (visible) window.__meteonexaWelcomeEverVisible = true;
      }
      requestAnimationFrame(sample);
    };
    document.addEventListener('DOMContentLoaded', () => requestAnimationFrame(sample), { once: true });
  });
}

async function seedRestoredEmailSession(page) {
  await page.addInitScript(() => {
    localStorage.setItem('meteonexa_suite_device_id', 'device-regression-1234567890');
    localStorage.setItem('meteonexa_suite_device_key', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    localStorage.setItem('meteonexa_v3_session', JSON.stringify({
      type: 'email',
      name: 'Regression User',
      verified: true,
      at: Date.now(),
    }));
    // Make the i18n bootstrap deterministic on CI regardless of the runner's
    // browser locale and guarantee that this test really exercises a cold catalog.
    const settings = JSON.parse(localStorage.getItem('meteonexa_v3_settings') || '{}');
    localStorage.setItem('meteonexa_v3_settings', JSON.stringify({ ...settings, language: 'it' }));
    localStorage.removeItem('meteonexa_i18n_catalog_v4');
    sessionStorage.removeItem('meteonexa_force_auth_v1');
  });
}

test('authenticated refresh never paints login while auth and initial weather hydration settle', async ({ page }) => {
  test.setTimeout(45000);
  await seedRestoredEmailSession(page);
  await prepareStableApp(page);

  // Reproduce the real bootstrap ordering deterministically: server auth settles
  // first, then the initial weather hydration keeps the boot pending beyond the
  // former 12-second watchdog boundary. At no point may login be painted.
  await installWelcomePaintProbe(page);

  let statusCalls = 0;
  let statusResolved = false;
  let weatherCalls = 0;

  await page.route('**/api/auth/status.php', async route => {
    statusCalls += 1;
    await new Promise(resolve => setTimeout(resolve, 6000));
    statusResolved = true;
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

  await page.route('https://api.open-meteo.com/v1/forecast**', async route => {
    weatherCalls += 1;
    await new Promise(resolve => setTimeout(resolve, 7000));
    await route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ ok: false, error: 'qa-delayed-weather' }),
    });
  });

  // Keep air quality deterministic and fast: the delayed base weather request
  // above is the only request intentionally holding the initial hydration open.
  await page.route('https://air-quality-api.open-meteo.com/v1/air-quality**', route => route.fulfill({
    status: 503,
    contentType: 'application/json',
    body: JSON.stringify({ ok: false, error: 'qa-air-disabled' }),
  }));

  await page.goto('./', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.documentElement.classList.contains('i18n-ready'), null, { timeout: 8000 });

  await expect.poll(() => statusCalls, { timeout: 10000 }).toBeGreaterThan(0);

  // While server auth is still pending, neither login nor app may be revealed.
  await page.waitForTimeout(500);
  expect(statusResolved).toBe(false);
  await expect(page.locator('html')).toHaveClass(/app-boot-pending/);
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('#weather-app')).toBeHidden();
  expect(await page.evaluate(() => window.__meteonexaWelcomeEverVisible)).toBe(false);

  // Auth resolves within its real 12s timeout. Weather hydration then starts and
  // deliberately spans the former 12s watchdog boundary.
  await expect.poll(() => statusResolved, { timeout: 10000 }).toBe(true);
  await expect.poll(() => weatherCalls, { timeout: 10000 }).toBeGreaterThan(0);

  // Cross the former 12s watchdog boundary while initial hydration is pending.
  // Login and app must both remain atomically hidden behind the boot splash.
  await page.waitForFunction(() => performance.now() >= 12200, null, { timeout: 15000 });
  await expect(page.locator('html')).toHaveClass(/app-boot-pending/);
  await expect(page.locator('#i18n-boot-splash')).toBeVisible();
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('#weather-app')).toBeHidden();
  expect(await page.evaluate(() => window.__meteonexaWelcomeEverVisible)).toBe(false);

  await waitForMeteoNexaReady(page, 25000);
  await expect(page.locator('html')).not.toHaveClass(/app-boot-pending/);
  await expect(page.locator('#i18n-boot-splash')).toBeHidden();
  await page.waitForTimeout(350);
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('#weather-app')).toBeVisible();
  expect(await page.evaluate(() => window.__meteonexaWelcomeEverVisible)).toBe(false);
});
