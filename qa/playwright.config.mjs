import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.METEONEXA_TEST_BASE_URL || 'http://127.0.0.1:8088/';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  timeout: 15000,
  expect: { timeout: 4000 },
  globalTimeout: process.env.CI ? 8 * 60 * 1000 : undefined,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: process.env.CI ? 2 : undefined,
  maxFailures: process.env.CI ? 5 : 0,
  reporter: process.env.CI
    ? [['line'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
    : 'line',
  use: {
    baseURL,
    actionTimeout: 4000,
    navigationTimeout: 8000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } }
  ]
});
