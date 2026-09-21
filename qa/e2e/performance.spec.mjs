import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

test.describe('production browser performance guardrails', () => {
  test('login shell keeps a bounded real-browser critical path', async ({ page }) => {
    await prepareStableApp(page, { clearStorage: true });
    const started = Date.now();
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    const readyMs = Date.now() - started;
    const metrics = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const resources = performance.getEntriesByType('resource');
      const blockingCss = resources.filter(entry => /\.css(?:\?|$)/.test(entry.name));
      const scripts = resources.filter(entry => /\.(?:js|mjs)(?:\?|$)/.test(entry.name));
      return {
        domContentLoadedMs: Math.round(nav?.domContentLoadedEventEnd || 0),
        resourceCount: resources.length,
        cssCount: blockingCss.length,
        scriptCount: scripts.length,
        transferredBytes: Math.round(resources.reduce((sum, entry) => sum + Number(entry.encodedBodySize || 0), 0)),
      };
    });
    expect(readyMs).toBeLessThan(12000);
    expect(metrics.domContentLoadedMs).toBeLessThan(6000);
    expect(metrics.resourceCount).toBeLessThan(180);
    expect(metrics.cssCount).toBeLessThan(16);
    expect(metrics.scriptCount).toBeLessThan(100);
    // Local CI may report zero transfer sizes when resources are memory/disk cached.
    if (metrics.transferredBytes > 0) expect(metrics.transferredBytes).toBeLessThan(5_000_000);
  });

  test('welcome language menu never covers the guest CTA', async ({ page }) => {
    await prepareStableApp(page, { clearStorage: true });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#welcome')).toBeVisible();
    await page.locator('#welcome-language-button').click();
    await expect(page.locator('#welcome-language-menu')).toBeVisible();
    const boxes = await page.evaluate(() => {
      const guest = document.querySelector('#guest-login')?.getBoundingClientRect();
      const menu = document.querySelector('#welcome-language-menu')?.getBoundingClientRect();
      const security = document.querySelector('#auth-view .auth-security')?.getBoundingClientRect();
      return guest && menu && security ? {
        guestBottom: guest.bottom,
        menuTop: menu.top,
        menuBottom: menu.bottom,
        securityTop: security.top,
      } : null;
    });
    expect(boxes).not.toBeNull();
    expect(boxes.menuTop).toBeGreaterThanOrEqual(boxes.guestBottom - 1);
    expect(boxes.menuBottom).toBeLessThanOrEqual(boxes.securityTop + 1);
  });
});
