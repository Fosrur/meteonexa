import { test, expect } from '@playwright/test';

test.describe('MeteoNexa custom controls and loaders', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('?preview');
    await expect(page.locator('#weather-app')).toBeVisible({ timeout: 15000 });
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
      await new Promise(resolve => setTimeout(resolve, 900));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, language: body.language || 'it', theme: body.theme || 'system', preferenceUpdatedAt: new Date().toISOString() }) });
    });
    await page.evaluate(() => document.querySelector('#settings-dialog')?.showModal?.());
    const before = await page.locator('body').getAttribute('data-theme');
    const target = before === 'light' ? 'dark' : 'light';
    const trigger = page.locator('#theme-setting');
    await trigger.click();
    await page.locator(`#theme-setting-menu [data-meteo-option="${target}"]`).click();
    await expect(page.locator('body')).toHaveAttribute('data-theme', target, { timeout: 350 });
    await expect(page.locator('html')).toHaveAttribute('data-theme', target, { timeout: 350 });
    await expect(trigger).toHaveAttribute('value', target);
  });

  test('custom time listbox and range work with keyboard/pointer semantics', async ({ page }) => {
    await page.evaluate(() => document.querySelector('#notification-dialog')?.showModal?.());
    const start = page.locator('#smart-quiet-start');
    await start.click();
    await page.locator('#smart-quiet-start-menu [data-meteo-option="22:30"]').click();
    expect(await start.evaluate(node => node.value)).toBe('22:30');

    const rain = page.locator('#threshold-rain');
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
      button.addEventListener('click', () => window.MeteoNexaLoader.run('QA', 'Loading', () => new Promise(resolve => setTimeout(resolve, 800)), 700));
    });
    const button = page.locator('#qa-loader-button');
    await button.click();
    await expect(button).toHaveClass(/button-loading/);
    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeDisabled();
    await expect(page.locator('#global-loader')).toBeVisible();
    await expect(button).not.toHaveClass(/button-loading/, { timeout: 2500 });
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
  });
});
