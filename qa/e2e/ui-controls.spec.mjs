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

  test('theme changes immediately without page refresh or preference persistence', async ({ page }) => {
    let preferencePosts = 0;
    await page.route('**/api/preferences.php', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      preferencePosts += 1;
      // Persistence failure must never roll back or delay the local repaint.
      return route.abort('failed');
    });

    await page.evaluate(() => {
      window.__qaThemeEvents = [];
      document.addEventListener('meteonexa:theme-changed', event => {
        window.__qaThemeEvents.push({
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
        runtimeTheme: window.MeteoNexaI18n?.state?.theme || '',
        runtimeResolvedTheme: window.MeteoNexaI18n?.state?.resolvedTheme || '',
      })),
      { timeout: 1000 },
    ).toEqual({
      html: target,
      body: target,
      runtimeTheme: target,
      runtimeResolvedTheme: target,
    });

    await expect(trigger).toHaveAttribute('value', target);
    await expect.poll(() => preferencePosts, { timeout: 1000 }).toBeGreaterThan(0);
  });

  test('custom time listbox and range work with keyboard/pointer semantics', async ({ page }) => {
    await page.evaluate(() => {
      const dialog = document.querySelector('#notification-dialog');
      dialog?.showModal?.();

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

      const loader = window.MeteoNexaServices.require('loader');
      button.addEventListener('click', () => loader.run(
        'QA',
        'Loading',
        () => new Promise(resolve => setTimeout(resolve, 800)),
        700,
      ));
    });

    const button = page.locator('#qa-loader-button');

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
