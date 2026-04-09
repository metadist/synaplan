/**
 * Playwright config for AI model health checks (API + chat UI).
 *
 * Differences from the default config:
 * - No globalSetup (keeps your real AI model defaults instead of overwriting with TestProvider)
 * - Headless (browser-driven tests still run headless Chromium)
 * - Single worker (sequential — avoids parallel API rate limits)
 * - Longer timeout (10 minutes per test)
 *
 * Usage:
 *   npm run test:e2e:model-api-check   (API spec only)
 *   npm run test:e2e:model-check       (API + chat UI — both specs in this config)
 *   INCLUDE_LOCAL=1 npm run test:e2e:model-api-check
 *   INCLUDE_VIDEO=1 npm run test:e2e:model-api-check
 *
 * Chat UI only: run model-ui-health-check.spec.ts via npx playwright (see that file’s header).
 */
import { defineConfig } from '@playwright/test'
import { URLS } from './config/config'

export default defineConfig({
  testDir: 'tests',
  testMatch: '**/model-*health-check.spec.ts',
  retries: 0,
  timeout: 600_000,

  use: {
    baseURL: URLS.BASE_URL,
    headless: true,
  },

  reporter: [['list'], ['html', { outputFolder: 'reports/html-health-check', open: 'always' }]],

  outputDir: 'test-results',
  workers: 1,
})
