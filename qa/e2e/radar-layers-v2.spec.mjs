import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

test.describe('Radar Layer V2', () => {
  test.beforeEach(async ({ page }) => {
    await prepareStableApp(page);
    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#weather-app')).toBeVisible({ timeout: 5000 });
    await page.evaluate(() => {
      const state = window.MeteoNexaServices.require('runtimeApi').get().getState();
      state.location = { ...state.location, name: 'Lavagna', admin1: 'Liguria', latitude: 44.3096, longitude: 9.343 };
    });
    await page.locator('.nav-link[data-page="radar"]').click();
    await expect(page.locator('#page-radar')).toHaveClass(/active-page/, { timeout: 5000 });
  });

  for (const layer of ['cloud', 'temperature', 'wind', 'pressure', 'snow', 'air', 'marine']) {
    test(`${layer} gives visible feedback`, async ({ page }) => {
      await page.locator('.radar-more-layers').evaluate(node => { node.open = true; });
      const button = page.locator(`[data-suite-map-layer="${layer}"]`);
      await expect(button).toBeVisible();
      await button.click();
      await expect(button).toHaveClass(/active/, { timeout: 5000 });
      await expect(page.locator('.radar-layer-v2-legend')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('.radar-layer-v2-legend')).toHaveAttribute('data-radar-layer-status', /ready|empty|error/);
    });
  }

  test('snow zero remains explicit instead of looking broken', async ({ page }) => {
    await page.evaluate(() => {
      const originalFetch = window.fetch.bind(window);
      window.fetch = async (input, init) => {
        const url = String(typeof input === 'string' ? input : input?.url || '');
        if (url.includes('snowfall')) {
          const parsed = new URL(url, location.href);
          const count = String(parsed.searchParams.get('latitude') || '').split(',').filter(Boolean).length;
          return new Response(JSON.stringify(Array.from({ length: count }, () => ({ current: { snowfall: 0 } }))), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        return originalFetch(input, init);
      };
    });
    await page.locator('.radar-more-layers').evaluate(node => { node.open = true; });
    await page.locator('[data-suite-map-layer="snow"]').click();
    const legend = page.locator('.radar-layer-v2-legend');
    await expect(legend).toBeVisible({ timeout: 20000 });
    await expect(legend).toContainText('0 cm');
  });
});
