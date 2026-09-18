import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

test.describe('MeteoNexa custom controls and loaders', () => {
  test.beforeEach(async ({ page }) => {
    await prepareStableApp(page);
    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#weather-app')).toBeVisible({ timeout: 5000 });
  });

  test('main application contains no native select/checkbox/range/time widgets', async ({ page }) => {
    await expect(page.locator('select')).toHaveCount(0);
    await expect(page.locator('input[type="checkbox"], input[type="range"], input[type="time"]')).toHaveCount(0);
    await expect(page.locator('[data-meteo-select]')).not.toHaveCount(0);
    await expect(page.locator('[data-meteo-switch]')).not.toHaveCount(0);
    await expect(page.locator('[data-meteo-range]')).not.toHaveCount(0);
  });

  test('custom listbox and switch expose compatible value/checked state', async ({ page }) => {
    await page.evaluate(() => document.querySelector('#settings-dialog')?.showModal?.());

    const unit = page.locator('#temperature-unit');
    await unit.click();
    await page.locator('#temperature-unit-menu [data-meteo-option="fahrenheit"]').click();
    await expect(unit).toHaveAttribute('value', 'fahrenheit');
    expect(await unit.evaluate(node => node.value)).toBe('fahrenheit');

    const motion = page.locator('#reduce-motion-setting');
    const before = await motion.getAttribute('aria-checked');
    await motion.click();
    await expect(motion).toHaveAttribute('aria-checked', before === 'true' ? 'false' : 'true');
    expect(await motion.evaluate(node => node.checked)).toBe(before !== 'true');
  });

  test('theme changes immediately without page refresh or waiting for preference API', async ({ page }) => {
    await page.route('**/api/preferences.php', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const body = route.request().postDataJSON() || {};
      // Deliberately slower than the local-theme assertions below. The test
      // proves that the repaint is local-first and does not wait for persistence.
      await new Promise(resolve => setTimeout(resolve, 2500));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          language: body.language || 'it',
          theme: body.theme || 'system',
          preferenceUpdatedAt: new Date().toISOString(),
        }),
      });
    });

    await page.evaluate(() => {
      window.__qaThemeEvents = [];
      document.addEventListener('meteonexa:theme-changed', event => {
        window.__qaThemeEvents.push({
          at: performance.now(),
          preference: event.detail?.preference || '',
          resolved: event.detail?.resolved || '',
        });
      });
      document.querySelector('#settings-dialog')?.showModal?.();
    });

    const before = await page.locator('body').getAttribute('data-theme');
    const target = before === 'light' ? 'dark' : 'light';
    const trigger = page.locator('#theme-setting');

    await trigger.click();
    await page.locator(`#theme-setting-menu [data-meteo-option="${target}"]`).click();

    await expect.poll(
      () => page.evaluate(() => window.__qaThemeEvents?.at(-1)?.resolved || ''),
      { timeout: 1000 },
    ).toBe(target);

    await expect.poll(
      () => page.evaluate(() => ({
        html: document.documentElement.dataset.theme || '',
        body: document.body?.dataset.theme || '',
      })),
      { timeout: 1000 },
    ).toEqual({ html: target, body: target });

    await expect(trigger).toHaveAttribute('value', target);
  });

  test('custom time listbox and range work with keyboard/pointer semantics', async ({ page }) => {
    await page.evaluate(() => {
      const dialog = document.querySelector('#notification-dialog');
      dialog?.showModal?.();

      // Smart alerts are intentionally feature-gated for a guest preview.
      // This is a component-semantics test, so expose only that existing
      // section without changing the application's feature policy.
      const smart = document.querySelector('.smart-alert-preferences');
      if (smart) {
        smart.hidden = false;
        smart.removeAttribute('hidden');
      }
    });

    const start = page.locator('#smart-quiet-start');
    await expect(start).toBeVisible({ timeout: 2000 });
    await start.click();
    const option = page.locator('#smart-quiet-start-menu [data-meteo-option="22:30"]');
    await expect(option).toBeVisible({ timeout: 2000 });
    await option.click();
    expect(await start.evaluate(node => node.value)).toBe('22:30');

    const rain = page.locator('#threshold-rain');
    await rain.scrollIntoViewIfNeeded();
    await expect(rain).toBeVisible({ timeout: 2000 });
    const before = Number(await rain.getAttribute('aria-valuenow'));
    await rain.focus();
    await page.keyboard.press('ArrowRight');
    const after = Number(await rain.getAttribute('aria-valuenow'));
    expect(after).toBeGreaterThan(before);
  });

  test('async loader marks the initiating button and prevents double click', async ({ page }) => {
    await page.evaluate(() => {
      const button = document.createElement('button');
      button.id = 'qa-loader-button';
      button.type = 'button';
      button.textContent = 'QA loader';
      document.body.appendChild(button);
      button.addEventListener('click', () => window.MeteoNexaLoader.run(
        'QA',
        'Loading',
        () => new Promise(resolve => setTimeout(resolve, 800)),
        700,
      ));
    });

    const button = page.locator('#qa-loader-button');

    // Dispatch the real bubbling click event directly. This exercises
    // MeteoNexa's document-level initiating-button capture and the button's
    // own handler, without allowing the fixed sidebar geometry to intercept a
    // synthetic pointer action created only for QA.
    await button.evaluate(node => {
      node.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        view: window,
        button: 0,
      }));
    });

    await expect(button).toHaveClass(/button-loading/);
    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeDisabled();
    await expect(page.locator('#global-loader')).toBeVisible();

    await expect(button).not.toHaveClass(/button-loading/, { timeout: 2500 });
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeEnabled();
  });
});
