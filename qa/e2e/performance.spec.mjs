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

  test('welcome language menu opens downward and is independently scrollable', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 760 });
    await prepareStableApp(page, { clearStorage: true });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#welcome')).toBeVisible();
    const button = page.locator('#welcome-language-button');
    await expect(button).not.toHaveAttribute('data-tooltip', /.+/);
    await button.click();
    const menu = page.locator('#welcome-language-menu');
    await expect(menu).toBeVisible();
    const layout = await page.evaluate(() => {
      const button = document.querySelector('#welcome-language-button')?.getBoundingClientRect();
      const menu = document.querySelector('#welcome-language-menu');
      const rect = menu?.getBoundingClientRect();
      const style = menu ? getComputedStyle(menu) : null;
      return button && menu && rect && style ? {
        buttonBottom: button.bottom,
        menuTop: rect.top,
        menuClientHeight: menu.clientHeight,
        menuScrollHeight: menu.scrollHeight,
        overflowY: style.overflowY,
      } : null;
    });
    expect(layout).not.toBeNull();
    expect(layout.menuTop).toBeGreaterThanOrEqual(layout.buttonBottom - 1);
    expect(['auto', 'scroll']).toContain(layout.overflowY);
    expect(layout.menuClientHeight).toBeLessThan(layout.menuScrollHeight);
    await menu.evaluate(node => { node.scrollTop = node.scrollHeight; });
    await expect(page.locator('[data-language-option="de"]')).toBeVisible();
  });
});
