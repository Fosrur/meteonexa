import { test, expect } from '@playwright/test';
import { prepareStableApp, waitForMeteoNexaInteractiveReady } from './test-helpers.mjs';

const values = Object.freeze({
  cloud_cover: 48,
  temperature_2m: 18,
  wind_speed_10m: 14,
  wind_direction_10m: 125,
  surface_pressure: 1014,
  snowfall: 0,
  european_aqi: 37,
  pm2_5: 8,
  wave_height: 1.3,
  sea_surface_temperature: 19,
});

async function installForecastFixtures(page) {
  await page.route(/https:\/\/(?:api|air-quality-api|marine-api)\.open-meteo\.com\/v1\//, async route => {
    const url = new URL(route.request().url());
    const latitude = url.searchParams.get('latitude') || '';
    if (!latitude.includes(',')) return route.continue();
    const count = latitude.split(',').filter(Boolean).length;
    const current = (url.searchParams.get('current') || '').split(',').filter(Boolean);
    const hourly = (url.searchParams.get('hourly') || '').split(',').filter(Boolean);
    const rows = Array.from({ length: count }, () => ({
      current: Object.fromEntries(current.map(key => [key, values[key] ?? 0])),
      hourly: Object.fromEntries(hourly.map(key => [key, [values[key] ?? 0]])),
    }));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(rows) });
  });
}

async function installVectorMapFixture(page) {
  await page.evaluate(() => {
    const state = window.MeteoNexaServices.require('runtimeApi').get().getState();
    const sources = new Map();
    const layers = new Map();
    state.radar.vectorMap = {
      addSource: (id, value) => sources.set(id, value),
      getSource: id => sources.get(id),
      removeSource: id => sources.delete(id),
      addLayer: value => layers.set(value.id, value),
      getLayer: id => layers.get(id),
      removeLayer: id => layers.delete(id),
      getStyle: () => ({ layers: [] }),
      isStyleLoaded: () => true,
      isMoving: () => false,
      jumpTo: () => {},
      resize: () => {},
      getCanvas: () => ({ width: 800, height: 600 }),
      triggerRepaint: () => {},
    };
    state.radar.vectorMapReady = true;
    state.radar.vectorMapFailed = false;
  });
}

async function setMapUnavailable(page) {
  await page.evaluate(() => {
    const state = window.MeteoNexaServices.require('runtimeApi').get().getState();
    state.radar.vectorMap = null;
    state.radar.vectorMapReady = false;
    state.radar.vectorMapFailed = true;
  });
}

test.describe('Radar Layer V2', () => {
  test.beforeEach(async ({ page }) => {
    await prepareStableApp(page);
    await installForecastFixtures(page);
    await page.goto('?preview', { waitUntil: 'domcontentloaded' });
    await waitForMeteoNexaInteractiveReady(page);
    await expect(page.locator('#weather-app')).toBeVisible({ timeout: 5000 });
    await page.evaluate(() => {
      const state = window.MeteoNexaServices.require('runtimeApi').get().getState();
      state.location = { ...state.location, name: 'Lavagna', admin1: 'Liguria', latitude: 44.3096, longitude: 9.343 };
    });
    await page.locator('.nav-link[data-page="radar"]').click();
    await expect(page.locator('#page-radar')).toHaveClass(/active-page/, { timeout: 5000 });
  });

  for (const layer of ['cloud', 'temperature', 'wind', 'pressure', 'snow', 'air', 'marine']) {
    test(`${layer} gives visible feedback`, async ({ page }) => {
      await installVectorMapFixture(page);
      await page.locator('.radar-more-layers').evaluate(node => { node.open = true; });
      const button = page.locator(`[data-suite-map-layer="${layer}"]`);
      await expect(button).toBeVisible();
      await button.click();
      await expect(button).toHaveClass(/active/, { timeout: 5000 });
      const legend = page.locator('.radar-layer-v2-legend');
      await expect(legend).toBeVisible({ timeout: 15000 });
      await expect(legend).toHaveAttribute('data-radar-layer-status', 'ready', { timeout: 15000 });
    });
  }

  test('snow zero remains explicit even when map tiles are unavailable', async ({ page }) => {
    await setMapUnavailable(page);
    await page.locator('.radar-more-layers').evaluate(node => { node.open = true; });
    await page.locator('[data-suite-map-layer="snow"]').click();
    const legend = page.locator('.radar-layer-v2-legend');
    await expect(legend).toBeVisible({ timeout: 15000 });
    await expect(legend).toHaveAttribute('data-radar-layer-status', 'ready', { timeout: 15000 });
    await expect(legend).toContainText('0 cm');
  });

  test('unavailable vector map ends loading with an explicit error', async ({ page }) => {
    await setMapUnavailable(page);
    await page.locator('.radar-more-layers').evaluate(node => { node.open = true; });
    await page.locator('[data-suite-map-layer="temperature"]').click();
    await expect(page.locator('.radar-layer-v2-legend')).toHaveAttribute('data-radar-layer-status', 'error', { timeout: 12000 });
  });
});
