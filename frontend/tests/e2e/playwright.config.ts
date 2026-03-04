import { defineConfig, devices } from '@playwright/test'
import { URLS } from './config/config'

export default defineConfig({
  testDir: 'tests',
  testMatch: '**/*.spec.ts',
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
  workers: 4,

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Chrome 142+ Local Network Access: disable prompt so dev-stack widget tests run without user interaction
        launchOptions: {
          args: [
            '--disable-features=LocalNetworkAccessChecks',
            ...(process.env.CI ? [] : ['--start-maximized']),
          ],
        },
      },
      grepInvert: /@oidc-redirect/,
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        ...(process.env.CI ? {} : { launchOptions: { args: ['--start-maximized'] } }),
      },
      grepInvert: /@oidc-redirect/,
    },
    {
      name: 'chromium-oidc-redirect',
      use: { ...devices['Desktop Chrome'] },
      grep: /@oidc-redirect/,
    },
  ],

  // Default filter: only run @smoke tests
  // grep: /@smoke/,

  // Plugin tests (@plugin) are excluded from standard runs via --grep-invert
  // in the npm scripts. They require external services and must be run
  // explicitly via: npm run test:e2e:plugin:castingdata
})
