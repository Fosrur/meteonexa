import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady } from './test-helpers.mjs';

const viewports = [
  [360,800],[390,844],[430,932],[768,1024],[820,1180],
  [800,360],[844,390],[932,430],[1024,768],[1180,820],
  [1280,720],[1366,768],[1440,900],[1920,1080],[2560,1440],[3840,2160]
];

const pages = ['home','radar','favorites','details','history','intelligence','advanced','route'];
const guestRestrictedPages = new Set(['history', 'route']);
const dialogs = ['search-dialog','settings-dialog','notification-dialog','assistant-dialog','profile-dialog','devices-access-dialog'];

async function noHorizontalOverflow(page, selector) {
  return page.locator(selector).evaluate(el => {
    const r = el.getBoundingClientRect();
    return el.scrollWidth <= Math.ceil(el.clientWidth) + 2
      && r.left >= -2
      && r.right <= innerWidth + 2;
  });
}

async function activatePageWithoutActionabilityWait(page, name) {
  return page.evaluate(pageName => {
    const trigger = document.querySelector(`[data-page="${pageName}"]`);
    if (!trigger) return false;
    trigger.click();
    return true;
  }, name);
}

async function revealRestrictedPageForLayout(page, name) {
  return page.evaluate(pageName => {
    const target = document.getElementById(`page-${pageName}`);
    if (!target) return false;

    document.querySelectorAll('section.page').forEach(section => {
      const active = section === target;
      section.hidden = !active;
      // MeteoNexa's real page visibility contract is `.page.active-page`.
      // `active` belongs to onboarding views and does not make an app page
      // visible, so layout-only QA must mirror the actual app-page class.
      section.classList.toggle('active-page', active);
    });
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

        if (guestRestrictedPages.has(name)) {
          // Preview intentionally runs as guest. History and route are protected
          // surfaces, so navigation must not grant access. This suite is about
          // responsive layout, therefore reveal only the existing section DOM
          // while leaving the real access-control contract untouched.
          expect(
            await revealRestrictedPageForLayout(page, name),
            `missing #page-${name}`,
          ).toBeTruthy();
        } else {
          // DOM click avoids Playwright actionability waits on mobile nav items
          // while still exercising MeteoNexa's real navigation handler.
          expect(
            await activatePageWithoutActionabilityWait(page, name),
            `missing [data-page="${name}"] trigger`,
          ).toBeTruthy();
        }

        await expect(current).toBeVisible({ timeout: 3000 });
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
