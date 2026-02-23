import './load-env'
import { defineConfig, devices } from '@playwright/test'
import { URLS } from './config/config'

/** E2E + integration smoke config. testDir '..' = tests/; testMatch restricts to e2e and integration (excludes unit). */
export default defineConfig({
  testDir: '..',
  testMatch: ['e2e/tests/**/*.spec.ts', 'integration/**/*.spec.ts'],
  testIgnore: ['**/unit/**'],
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,

  use: {
    baseURL: URLS.BASE_URL,
    headless: process.env.CI ? true : false,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },

  reporter: [
    ['list'],
    ['junit', { outputFile: 'reports/junit.xml' }],
    ['html', { outputFolder: 'reports/html', open: 'never' }],
  ],

  outputDir: 'test-results',
  workers: 1,

  projects: [
    {
      name: 'chromium',
      use: {
        ...Object.fromEntries(
          Object.entries(devices['Desktop Chrome']).filter(([key]) => key !== 'deviceScaleFactor')
        ),
        viewport: process.env.CI ? { width: 1920, height: 1080 } : null,
        ...(process.env.CI
          ? {}
          : {
              launchOptions: {
                args: ['--start-maximized'],
              },
            }),
      },
    },
    {
      name: 'firefox',
      use: {
        ...Object.fromEntries(
          Object.entries(devices['Desktop Firefox']).filter(([key]) => key !== 'deviceScaleFactor')
        ),
        viewport: process.env.CI ? { width: 1920, height: 1080 } : null,
        ...(process.env.CI
          ? {}
          : {
              launchOptions: {
                args: ['--start-maximized'],
              },
            }),
      },
    },
  ],
})
