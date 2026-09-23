import { test, expect } from '@playwright/test';
import { rm, writeFile } from 'node:fs/promises';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

const flag = process.env.METEONEXA_TEST_MAINTENANCE_FLAG || '';

async function setMaintenance(enabled) {
  if (!flag) throw new Error('METEONEXA_TEST_MAINTENANCE_FLAG missing');
  if (enabled) {
    await writeFile(flag, 'qa\n', 'utf8');
  } else {
    await rm(flag, { force: true });
  }
}

test.describe('@maintenance-exclusive deployment maintenance active session', () => {
  test.afterEach(async () => {
    if (flag) await setMaintenance(false);
  });

  test('an already-open PWA session enters maintenance and returns automatically', async ({ page }) => {
    test.setTimeout(40000);
    await setMaintenance(false);
    await prepareStableApp(page);

    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#weather-app')).toBeVisible();

    await page.evaluate(async () => {
      if (!('serviceWorker' in navigator)) throw new Error('SERVICE_WORKER_UNAVAILABLE');
      await Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => setTimeout(() => reject(new Error('SERVICE_WORKER_READY_TIMEOUT')), 10000)),
      ]);
      localStorage.setItem('qa-maintenance-session-marker', 'preserve');
    });

    try {
      await page.waitForFunction(() => Boolean(navigator.serviceWorker?.controller), null, { timeout: 6000 });
    } catch {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await waitForMeteoNexaReady(page);
      await page.waitForFunction(() => Boolean(navigator.serviceWorker?.controller), null, { timeout: 6000 });
    }

    expect(await page.evaluate(() => Boolean(navigator.serviceWorker?.controller))).toBe(true);

    await setMaintenance(true);
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));

    await page.waitForURL(/maintenance_enter=\d+/, { timeout: 7000 });
    await expect(page.locator('.maintenance-card')).toBeVisible();
    await expect(page.locator('[data-i18n="maintenance.title"]')).toBeVisible();

    await setMaintenance(false);
    await page.locator('#maintenance-retry').click();

    await page.waitForURL(/maintenance_release=\d+/, { timeout: 7000 });
    await waitForMeteoNexaReady(page, 12000);
    await expect(page.locator('#weather-app')).toBeVisible();

    const releasedUrl = new URL(page.url());
    expect(releasedUrl.searchParams.has('preview')).toBe(true);
    expect(releasedUrl.searchParams.has('maintenance_enter')).toBe(false);
    expect(releasedUrl.searchParams.has('maintenance_release')).toBe(true);
    expect(await page.evaluate(() => sessionStorage.getItem('meteonexa_maintenance_return_v1'))).toBeNull();
    expect(await page.evaluate(() => localStorage.getItem('qa-maintenance-session-marker'))).toBe('preserve');
  });

  test('an already-open authenticated session is blocked by maintenance too', async ({ browser }) => {
    test.setTimeout(40000);
    await setMaintenance(false);

    // page.route() cannot reliably intercept Service Worker installation/update
    // requests. Use a dedicated context with Service Workers truly disabled for
    // this auth-continuity test. The preceding guest test deliberately keeps the
    // real PWA/Service Worker path enabled and covered end-to-end.
    const context = await browser.newContext({
      baseURL: process.env.METEONEXA_TEST_BASE_URL || 'http://127.0.0.1:8088/',
      serviceWorkers: 'block',
    });
    const page = await context.newPage();

    try {
      await context.addInitScript(() => {
        localStorage.setItem('meteonexa_suite_device_id', 'device-maintenance-1234567890');
        localStorage.setItem('meteonexa_suite_device_key', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB');
        localStorage.setItem('meteonexa_v3_session', JSON.stringify({
          type: 'email',
          name: 'Maintenance User',
          verified: true,
          at: Date.now(),
        }));
        sessionStorage.removeItem('meteonexa_force_auth_v1');
      });
      await prepareStableApp(page);
      await context.route('**/api/auth/status.php', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            ok: true,
            authenticated: true,
            displayName: 'Maintenance User',
            email: 'maintenance@example.test',
            diagnosticsAllowed: false,
            smtpConfigured: true,
          }),
        });
      });

      await page.goto('./', { waitUntil: 'domcontentloaded' });
      await waitForMeteoNexaReady(page);
      await expect(page.locator('#weather-app')).toBeVisible();
      await expect(page.locator('#welcome')).toBeHidden();
      expect(await page.evaluate(() => Boolean(navigator.serviceWorker?.controller))).toBe(false);

      await setMaintenance(true);
      await page.evaluate(() => window.dispatchEvent(new Event('focus')));

      await page.waitForURL(/maintenance_enter=\d+/, { timeout: 7000 });
      await expect(page.locator('.maintenance-card')).toBeVisible();

      await setMaintenance(false);
      await page.locator('#maintenance-retry').click();
      await page.waitForURL(/maintenance_release=\d+/, { timeout: 7000 });
      await waitForMeteoNexaReady(page, 12000);
      await expect(page.locator('#weather-app')).toBeVisible();
      await expect(page.locator('#welcome')).toBeHidden();
    } finally {
      await context.close();
    }
  });

});
