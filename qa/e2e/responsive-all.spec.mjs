import { test, expect } from '@playwright/test';

const viewports = [
  [360,800],[390,844],[430,932],[768,1024],[820,1180],
  [800,360],[844,390],[932,430],[1024,768],[1180,820],
  [1280,720],[1366,768],[1440,900],[1920,1080],[2560,1440],[3840,2160]
];
const pages = ['home','radar','favorites','details','history','intelligence','advanced','route'];
const dialogs = ['search-dialog','settings-dialog','notification-dialog','assistant-dialog','profile-dialog','devices-access-dialog'];

async function noHorizontalOverflow(page, selector) {
  return page.locator(selector).evaluate(el => {
    const r=el.getBoundingClientRect();
    return el.scrollWidth <= Math.ceil(el.clientWidth)+2 && r.left >= -2 && r.right <= innerWidth+2;
  });
}

test.describe('responsive regression matrix', () => {
  for (const [width,height] of viewports) {
    test(`pages and dialogs fit ${width}x${height}`, async ({ page }) => {
      await page.setViewportSize({width,height});
      await page.goto('?preview');
      await expect(page.locator('#weather-app')).toBeVisible({timeout:15000});
      for (const name of pages) {
        await page.locator(`[data-page="${name}"]`).first().click().catch(()=>{});
        const current=page.locator(`#page-${name}`);
        if (await current.count()) {
          await expect(current).toBeVisible();
          expect(await noHorizontalOverflow(page, `#page-${name}`)).toBeTruthy();
        }
      }
      for (const id of dialogs) {
        const dlg=page.locator(`#${id}`);
        if (!(await dlg.count())) continue;
        await page.evaluate(id => { const el=document.getElementById(id); if(el && !el.open) el.showModal?.(); }, id);
        if (await dlg.isVisible()) expect(await noHorizontalOverflow(page, `#${id}`)).toBeTruthy();
        await page.evaluate(id => { const el=document.getElementById(id); if(el?.open) el.close?.(); }, id);
      }
    });
  }
});
