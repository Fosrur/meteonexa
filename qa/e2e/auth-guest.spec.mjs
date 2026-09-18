import { test, expect } from '@playwright/test';

async function dismissPrivacyNotice(page) {
  const notice = page.locator('#privacy-notice');
  if (await notice.isVisible().catch(() => false)) {
    await page.locator('#privacy-notice-ok').click();
    await expect(notice).toBeHidden();
  }
}

test.describe('guest access regression', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      try { localStorage.clear(); sessionStorage.clear(); } catch {}
    });
    await page.goto('/');
    await expect(page.locator('#auth-view')).toBeVisible({ timeout: 15000 });
    await dismissPrivacyNotice(page);
  });

  test('the whole Continua come ospite CTA is clickable, not only its text', async ({ page }) => {
    const guest = page.locator('#guest-login');
    await expect(guest).toBeVisible();
    await expect(guest).toBeEnabled();

    const box = await guest.boundingBox();
    expect(box && box.width >= 120 && box.height >= 36).toBeTruthy();

    // Hit a safe point near the left edge, deliberately away from the label and chevron.
    await guest.click({ position: { x: 8, y: Math.max(8, Math.floor(box.height / 2)) } });
    await expect(page.locator('#location-view')).toHaveClass(/active/, { timeout: 10000 });
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
        probes: probes.map(([x, y]) => document.elementFromPoint(x, y)?.id || document.elementFromPoint(x, y)?.closest?.('#guest-login')?.id || ''),
      };
    });
    expect(result.pointerEvents).not.toBe('none');
    expect(result.children.every(child => child.pointerEvents === 'none')).toBeTruthy();
    expect(result.probes.every(id => id === 'guest-login')).toBeTruthy();
  });
});
