import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.METEONEXA_TEST_BASE_URL || 'http://127.0.0.1:8088/';

export default defineConfig({
  testDir: './e2e',
  timeout: 20000,
  expect: { timeout: 5000 },
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['line'], ['html', { outputFolder: 'playwright-report', open: 'never' }]] : 'line',
  use: {
    baseURL,
    actionTimeout: 5000,
    navigationTimeout: 10000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } }
  ]
});
