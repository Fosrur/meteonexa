import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

const viewports = [
  [360,800],[390,844],[430,932],[768,1024],[820,1180],
  [800,360],[844,390],[932,430],[1024,768],[1180,820],
  [1280,720],[1366,768],[1440,900],[1920,1080],[2560,1440],[3840,2160]
];

const pages = ['home','radar','favorites','details','history','intelligence','advanced','route'];
const dialogs = ['search-dialog','settings-dialog','notification-dialog','assistant-dialog','profile-dialog','devices-access-dialog'];

async function noHorizontalOverflow(page, selector) {
  return page.locator(selector).evaluate(el => {
    const r = el.getBoundingClientRect();
    return el.scrollWidth <= Math.ceil(el.clientWidth) + 2
      && r.left >= -2
      && r.right <= innerWidth + 2;
  });
}

async function revealPageForLayout(page, name) {
  return page.evaluate(pageName => {
    const target = document.getElementById(`page-${pageName}`);
    if (!target) return false;

    document.querySelectorAll('section.page').forEach(section => {
      const active = section === target;
      section.hidden = !active;
      section.classList.toggle('active-page', active);
    });

    // Feature visibility may intentionally hide a page for the current guest or
    // deployment policy. This suite validates geometry only, so the selected
    // page must be visible independently from access/feature flags.
    target.hidden = false;
    target.removeAttribute('hidden');
    target.classList.add('active-page');
    return true;
  }, name);
}

test.describe('responsive regression matrix', () => {
  for (const [width,height] of viewports) {
    test(`pages and dialogs fit ${width}x${height}`, async ({ page }) => {
      await prepareStableApp(page);
      await page.setViewportSize({ width, height });
      await page.goto('?preview', { waitUntil: 'domcontentloaded' });
      await waitForMeteoNexaReady(page);

      await expect(page.locator('#weather-app')).toBeVisible({ timeout: 5000 });

      for (const name of pages) {
        const current = page.locator(`#page-${name}`);
        if (!(await current.count())) continue;

        expect(
          await revealPageForLayout(page, name),
          `missing #page-${name}`,
        ).toBeTruthy();

        // Measure immediately after the deterministic layout reveal. Navigation
        // permissions and remote feature visibility are tested elsewhere and
        // must not make this geometry matrix flaky.
        const visible = await current.evaluate(el => {
          const style = getComputedStyle(el);
          const rect = el.getBoundingClientRect();
          return !el.hidden
            && style.display !== 'none'
            && style.visibility !== 'hidden'
            && rect.width > 0
            && rect.height > 0;
        });
        expect(visible, `#page-${name} should be measurable for layout QA`).toBeTruthy();
        expect(await noHorizontalOverflow(page, `#page-${name}`)).toBeTruthy();
      }

      for (const id of dialogs) {
        const dlg = page.locator(`#${id}`);
        if (!(await dlg.count())) continue;

        await page.evaluate(dialogId => {
          const el = document.getElementById(dialogId);
          if (el && !el.open) el.showModal?.();
        }, id);

        if (await dlg.isVisible()) {
          expect(await noHorizontalOverflow(page, `#${id}`)).toBeTruthy();
        }

        await page.evaluate(dialogId => {
          const el = document.getElementById(dialogId);
          if (el?.open) el.close?.();
        }, id);
      }
    });
  }
});
