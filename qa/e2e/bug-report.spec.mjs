import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaReady, waitForMeteoNexaInteractiveReady } from './test-helpers.mjs';

test.describe('bug report recording regression', () => {
  test('Ferma e allega flushes the recorder and adds the video attachment', async ({ page }) => {
    await prepareStableApp(page, { clearStorage: true });
    await page.addInitScript(() => {
      window.__bugRecorderRequestData = 0;
      class FakeTrack extends EventTarget { stop() {} }
      const track = new FakeTrack();
      const stream = {
        getTracks: () => [track],
        getVideoTracks: () => [track],
      };
      Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getDisplayMedia: async () => stream },
      });
      class FakeMediaRecorder extends EventTarget {
        static isTypeSupported(type) { return String(type).startsWith('video/webm'); }
        constructor() { super(); this.state = 'inactive'; this.mimeType = 'video/webm;codecs=vp8'; }
        start() { this.state = 'recording'; }
        requestData() {
          window.__bugRecorderRequestData += 1;
          setTimeout(() => this.dispatchEvent(new MessageEvent('dataavailable', {
            data: new Blob(['meteonexa-recording'], { type: 'video/webm' }),
          })), 0);
        }
        stop() {
          this.state = 'inactive';
          // Deliberately do not emit data here: the app must flush via requestData().
          setTimeout(() => this.dispatchEvent(new Event('stop')), 10);
        }
      }
      Object.defineProperty(window, 'MediaRecorder', { configurable: true, value: FakeMediaRecorder });
    });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaReady(page);
    await waitForMeteoNexaInteractiveReady(page);
    await page.evaluate(() => {
      const welcome = document.querySelector('#welcome');
      const auth = document.querySelector('#auth-view');
      const location = document.querySelector('#location-view');
      const app = document.querySelector('#weather-app');
      if (welcome) welcome.hidden = true;
      if (auth) auth.hidden = true;
      location?.classList.remove('active');
      if (app) app.hidden = false;
      // getDisplayMedia is intentionally mocked above with a lightweight stream.
      // Native HTMLMediaElement.srcObject rejects that plain test double in both
      // Chromium and Firefox before the recording panel can be shown, so isolate
      // the recorder regression from browser MediaStream brand checks.
      const preview = document.querySelector('#bug-recording-video');
      if (preview) {
        Object.defineProperty(preview, 'srcObject', { configurable: true, writable: true, value: null });
      }
      document.querySelectorAll('.page').forEach(node => node.classList.remove('active-page'));
      document.querySelector('#page-bug-report')?.classList.add('active-page');
      document.body.dataset.page = 'bug-report';
    });

    await page.locator('#bug-record-video').click();
    await expect(page.locator('#bug-recording-panel')).toBeVisible();
    await page.locator('#bug-recording-stop').click();
    await expect.poll(() => page.evaluate(() => window.__bugRecorderRequestData)).toBeGreaterThan(0);
    await expect(page.locator('#bug-attachment-list .bug-attachment-item')).toHaveCount(1, { timeout: 3000 });
    await expect(page.locator('#bug-attachment-list video')).toHaveCount(1);
    await expect(page.locator('#bug-recording-panel')).toBeHidden();
  });
});
