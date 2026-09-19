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
    await expect(unit).toBeVisible();
    await unit.evaluate(node => node.click());

    const fahrenheit = page.locator('#temperature-unit-menu [data-meteo-option="fahrenheit"]');
    await expect(fahrenheit).toBeVisible();
    await fahrenheit.evaluate(node => node.click());

    await expect(unit).toHaveAttribute('value', 'fahrenheit');
    expect(await unit.evaluate(node => node.value)).toBe('fahrenheit');

    const motion = page.locator('#reduce-motion-setting');
    const before = await motion.getAttribute('aria-checked');
    await motion.evaluate(node => node.click());
    await expect(motion).toHaveAttribute('aria-checked', before === 'true' ? 'false' : 'true');
    expect(await motion.evaluate(node => node.checked)).toBe(before !== 'true');
  });

  test('theme changes immediately while remote preference persistence is still pending', async ({ page }) => {
    await page.evaluate(() => {
      const originalFetch = window.fetch.bind(window);
      window.__qaPreferencePostStarted = 0;

      window.fetch = (input, init = {}) => {
        const url = typeof input === 'string' ? input : String(input?.url || '');
        const method = String(init?.method || 'GET').toUpperCase();

        if (url.includes('api/preferences.php') && method === 'POST') {
          window.__qaPreferencePostStarted += 1;
          return new Promise(() => {});
        }

        return originalFetch(input, init);
      };

      document.querySelector('#settings-dialog')?.showModal?.();
    });

    const before = await page.locator('body').getAttribute('data-theme');
    const target = before === 'light' ? 'dark' : 'light';
    const trigger = page.locator('#theme-setting');

    await trigger.click();
    await page.locator(`#theme-setting-menu [data-meteo-option="${target}"]`).click();

    await expect(trigger).toHaveAttribute('value', target, { timeout: 2000 });
    await expect(page.locator('html')).toHaveAttribute('data-theme', target, { timeout: 2000 });
    await expect(page.locator('body')).toHaveAttribute('data-theme', target, { timeout: 2000 });

    const runtime = await page.evaluate(() => ({
      theme: window.MeteoNexaI18n?.state?.theme || '',
      resolvedTheme: window.MeteoNexaI18n?.state?.resolvedTheme || '',
      preferencePostStarted: Number(window.__qaPreferencePostStarted || 0),
    }));

    expect(runtime.theme).toBe(target);
    expect(runtime.resolvedTheme).toBe(target);
    expect(runtime.preferencePostStarted).toBeGreaterThan(0);
  });

  test('custom time listbox and range work with keyboard/pointer semantics', async ({ page }) => {
    await page.evaluate(() => {
      const sourceSelect = document.querySelector('#smart-quiet-start')?.closest('[data-meteo-select-control]');
      const sourceRange = document.querySelector('#threshold-rain');
      if (!sourceSelect || !sourceRange) throw new Error('QA_CUSTOM_CONTROL_SOURCE_MISSING');

      const fixture = document.createElement('section');
      fixture.id = 'qa-custom-controls-fixture';
      fixture.setAttribute('aria-label', 'QA custom controls');
      Object.assign(fixture.style, {
        position: 'fixed',
        left: '24px',
        top: '24px',
        zIndex: '2147483647',
        width: '360px',
        minHeight: '180px',
        padding: '20px',
        display: 'grid',
        gap: '28px',
        background: 'var(--surface, #fff)',
      });

      const select = sourceSelect.cloneNode(true);
      const trigger = select.querySelector('[data-meteo-select]');
      const menu = select.querySelector('.meteo-select-menu');
      trigger.removeAttribute('data-meteo-enhanced');
      trigger.id = 'qa-time-select';
      trigger.setAttribute('aria-controls', 'qa-time-menu');
      menu.id = 'qa-time-menu';
      menu.hidden = true;

      const range = sourceRange.cloneNode(true);
      range.removeAttribute('data-meteo-enhanced');
      range.id = 'qa-range';
      range.style.width = '320px';

      fixture.append(select, range);
      document.body.appendChild(fixture);

      window.MeteoNexaServices.require('controls').enhance(fixture);
    });

    const start = page.locator('#qa-time-select');
    await expect(start).toBeVisible({ timeout: 2000 });
    await start.click();

    const option = page.locator('#qa-time-menu [data-meteo-option="22:30"]');
    await expect(option).toBeVisible({ timeout: 2000 });
    await option.click();

    await expect(start).toHaveAttribute('value', '22:30');
    expect(await start.evaluate(node => node.value)).toBe('22:30');

    const range = page.locator('#qa-range');
    await expect(range).toBeVisible({ timeout: 2000 });
    const before = Number(await range.getAttribute('aria-valuenow'));

    await range.focus();
    await page.keyboard.press('ArrowRight');

    const after = Number(await range.getAttribute('aria-valuenow'));
    expect(after).toBeGreaterThan(before);
  });

  test('async loader marks the initiating button and prevents double click', async ({ page }) => {
    await page.evaluate(() => {
      const button = document.createElement('button');
      button.id = 'qa-loader-button';
      button.type = 'button';
      button.textContent = 'QA loader';
      document.body.appendChild(button);

      window.__qaLoaderResolve = null;
      window.__qaLoaderPromise = null;

      const loader = window.MeteoNexaServices.require('loader');
      button.addEventListener('click', () => {
        window.__qaLoaderPromise = loader.run(
          'QA',
          'Loading',
          () => new Promise(resolve => {
            window.__qaLoaderResolve = resolve;
          }),
          0,
        );
      });
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

    await expect.poll(
      () => page.evaluate(() => typeof window.__qaLoaderResolve === 'function'),
      { timeout: 2000 },
    ).toBeTruthy();

    await expect(button).toHaveClass(/button-loading/);
    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeDisabled();
    await expect(page.locator('#global-loader')).toBeVisible();

    await page.evaluate(() => window.__qaLoaderResolve?.());

    await expect(button).not.toHaveClass(/button-loading/, { timeout: 2500 });
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeEnabled();
    await expect(page.locator('#global-loader')).toBeHidden({ timeout: 2500 });
  });
});
