/**
 * Playwright config for the AI model health check.
 *
 * Differences from the default config:
 * - No globalSetup (keeps your real AI model defaults instead of overwriting with TestProvider)
 * - Headless (API-only, no browser needed)
 * - Single worker (sequential — avoids parallel API rate limits)
 * - Longer timeout (10 minutes per test)
 *
 * Usage:
 *   npm run test:e2e:model-check
 *   INCLUDE_LOCAL=1 npm run test:e2e:model-check          (include Ollama etc.)
 *   INCLUDE_VIDEO=1 npm run test:e2e:model-check          (include TEXT2VID)
 */
import { defineConfig } from '@playwright/test'
import { URLS } from './config/config'

export default defineConfig({
  testDir: 'tests',
  testMatch: '**/model-health-check.spec.ts',
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
