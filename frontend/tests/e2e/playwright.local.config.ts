/**
 * Playwright config for local real-AI tests.
 *
 * Differences from the default config:
 * - No globalSetup (keeps your real AI model defaults instead of overwriting with TestProvider)
 * - Only chromium (headed)
 * - Single worker (sequential execution — avoids parallel API rate limits)
 * - Longer timeout (10 minutes per test)
 * - Only runs tests tagged @local
 *
 * Usage:
 *   npx playwright test --config tests/e2e/playwright.local.config.ts
 */
import { defineConfig, devices } from '@playwright/test'
import { URLS } from './config/config'

export default defineConfig({
  testDir: 'tests',
  testMatch: '**/real-ai*.spec.ts',
  retries: 0,
  timeout: 600_000,

  use: {
    baseURL: URLS.BASE_URL,
    headless: false,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },

  reporter: [['list'], ['html', { outputFolder: 'reports/html-local', open: 'never' }]],

  outputDir: 'test-results-local',
  workers: 1,

  projects: [
    {
      name: 'chromium-local',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: ['--disable-features=LocalNetworkAccessChecks', '--start-maximized'],
        },
      },
    },
  ],
})
