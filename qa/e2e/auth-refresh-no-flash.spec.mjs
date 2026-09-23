import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

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
    sessionStorage.removeItem('meteonexa_force_auth_v1');
  });
}

test('authenticated refresh keeps the boot splash until server session reconciliation finishes', async ({ page }) => {
  test.setTimeout(30000);
  await seedRestoredEmailSession(page);
  await prepareStableApp(page);

  let statusCalls = 0;
  await page.route('**/api/auth/status.php', async route => {
    statusCalls += 1;
    // Keep the authoritative session check in flight long enough to verify the
    // intermediate paint. The login surface must never be exposed here.
    await new Promise(resolve => setTimeout(resolve, 1400));
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

  await page.goto('./', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.documentElement.classList.contains('i18n-ready'), null, { timeout: 8000 });

  await expect.poll(() => statusCalls, { timeout: 8000 }).toBeGreaterThan(0);
  await expect(page.locator('html')).toHaveClass(/app-boot-pending/);
  await expect(page.locator('#i18n-boot-splash')).toBeVisible();
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('#weather-app')).toBeHidden();

  await waitForMeteoNexaReady(page, 15000);
  await expect(page.locator('html')).not.toHaveClass(/app-boot-pending/);
  await expect(page.locator('#i18n-boot-splash')).toBeHidden();
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('#weather-app')).toBeVisible();
});
