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

test.describe('deployment maintenance active session', () => {
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
});
