import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

test.describe('guest access regression', () => {
  test.beforeEach(async ({ page }) => {
    await prepareStableApp(page, { clearStorage: true });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await expect(page.locator('#auth-view')).toBeVisible({ timeout: 5000 });
  });

  test('the whole Continua come ospite CTA is clickable, not only its text', async ({ page }) => {
    const guest = page.locator('#guest-login');
    await expect(guest).toBeVisible();
    await expect(guest).toBeEnabled();

    const box = await guest.boundingBox();
    expect(box && box.width >= 120 && box.height >= 36).toBeTruthy();

    // Hit a safe point near the left edge, deliberately away from the label and chevron.
    await guest.click({ position: { x: 8, y: Math.max(8, Math.floor(box.height / 2)) } });
    await expect(page.locator('#location-view')).toHaveClass(/active/, { timeout: 5000 });
  });

  test('child label/icon never steal the pointer hit target', async ({ page }) => {
    const result = await page.locator('#guest-login').evaluate(button => {
      const style = getComputedStyle(button);
      const children = [...button.children].map(child => ({
        tag: child.tagName,
        pointerEvents: getComputedStyle(child).pointerEvents,
      }));
      const rect = button.getBoundingClientRect();
      const probes = [
        [rect.left + 4, rect.top + rect.height / 2],
        [rect.right - 4, rect.top + rect.height / 2],
        [rect.left + rect.width / 2, rect.top + 4],
        [rect.left + rect.width / 2, rect.bottom - 4],
      ];
      return {
        pointerEvents: style.pointerEvents,
        children,
        probes: probes.map(([x, y]) => {
          const node = document.elementFromPoint(x, y);
          return node?.id || node?.closest?.('#guest-login')?.id || '';
        }),
      };
    });

    expect(result.pointerEvents).not.toBe('none');
    expect(result.children.every(child => child.pointerEvents === 'none')).toBeTruthy();
    expect(result.probes.every(id => id === 'guest-login')).toBeTruthy();
  });

  test('guest Assistant Center opens in local mode and hides AI mode', async ({ page }) => {
    await page.locator('#guest-login').click();
    await expect(page.locator('#location-view')).toHaveClass(/active/, { timeout: 5000 });
    await page.evaluate(() => {
      const welcome = document.querySelector('#welcome');
      const location = document.querySelector('#location-view');
      const app = document.querySelector('#weather-app');
      const home = document.querySelector('#page-home');
      location?.classList.remove('active');
      if (welcome) welcome.hidden = true;
      if (app) app.hidden = false;
      document.querySelectorAll('.page').forEach(node => node.classList.remove('active-page'));
      home?.classList.add('active-page');
      document.body.dataset.page = 'home';
      window.MeteoNexaServices?.get?.('uiVisibility')?.apply?.();
    });
    const assistant = page.locator('#assistant-center-button');
    await expect(assistant).toBeVisible();
    await assistant.click();
    await expect(page.locator('#assistant-dialog')).toBeVisible();
    await expect(page.locator('[data-assistant-mode-choice="ai"]')).toBeHidden();
    await expect(page.locator('[data-assistant-mode-choice="local"]')).toHaveAttribute('aria-pressed', 'true');
  });

  test('guest Assistant controls stay local and work without suite bind timing', async ({ page }) => {
    await page.locator('#guest-login').click();
    await expect(page.locator('#location-view')).toHaveClass(/active/, { timeout: 5000 });
    await page.evaluate(() => {
      const welcome = document.querySelector('#welcome');
      const location = document.querySelector('#location-view');
      const app = document.querySelector('#weather-app');
      const home = document.querySelector('#page-home');
      location?.classList.remove('active');
      if (welcome) welcome.hidden = true;
      if (app) app.hidden = false;
      document.querySelectorAll('.page').forEach(node => node.classList.remove('active-page'));
      home?.classList.add('active-page');
      document.body.dataset.page = 'home';
      window.MeteoNexaServices?.get?.('uiVisibility')?.apply?.();
    });

    await page.locator('#assistant-center-button').click();
    const dialog = page.locator('#assistant-dialog');
    await expect(dialog).toBeVisible();

    const beforeUrl = page.url();
    const input = page.locator('#assistant-input');
    await input.fill('Che tempo farà domani?');
    await page.locator('#assistant-send').click();
    await expect(page.locator('#assistant-messages .assistant-message.user')).toHaveCount(1, { timeout: 3000 });
    await expect(page).toHaveURL(beforeUrl);
    await expect(page.locator('#auth-view')).toBeHidden();

    const suggestion = page.locator('#assistant-suggestions [data-assistant-question]').first();
    await expect(suggestion).toBeVisible();
    const usersBeforeSuggestion = await page.locator('#assistant-messages .assistant-message.user').count();
    await suggestion.click();
    await expect(page.locator('#assistant-messages .assistant-message.user')).toHaveCount(usersBeforeSuggestion + 1, { timeout: 3000 });

    await page.locator('#assistant-clear').click();
    await expect(page.locator('#assistant-messages .assistant-message.user')).toHaveCount(0);

    await page.locator('#assistant-close').click();
    await expect(dialog).toBeHidden();
  });

});
