import { defineConfig, devices } from '@playwright/test'
import dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

dotenv.config({ path: path.join(__dirname, '.env.local') })

const n = process.env.E2E_WORKERS ? parseInt(process.env.E2E_WORKERS, 10) : 4
export const WORKER_COUNT = Number.isInteger(n) && n >= 1 ? n : 4

export default defineConfig({
  globalSetup: './global-setup.ts',
  testDir: 'tests',
  testMatch: '**/*.spec.ts',
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,

  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:5173',
    headless: process.env.CI ? true : false,
    ignoreHTTPSErrors: true, // Keycloak uses self-signed cert in dev/test
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
  workers: WORKER_COUNT,

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [
            '--disable-features=LocalNetworkAccessChecks',
            ...(process.env.CI ? [] : ['--start-maximized']),
          ],
        },
      },
      grepInvert: /@oidc-redirect|@noci|@visual/,
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        ...(process.env.CI ? {} : { launchOptions: { args: ['--start-maximized'] } }),
      },
      // Layout guard runs in chromium (desktop) + chromium-mobile only —
      // geometry contracts are browser-agnostic, firefox stays functional.
      grepInvert: /@oidc-redirect|@noci|@visual|@layout/,
    },
    {
      name: 'chromium-oidc-redirect',
      use: { ...devices['Desktop Chrome'] },
      grep: /@oidc-redirect/,
    },
    {
      // Mobile viewport for the layout UI guard only — functional specs are
      // desktop-designed and would produce noise, not signal, on a phone.
      name: 'chromium-mobile',
      use: {
        ...devices['iPhone 14'],
        browserName: 'chromium',
        launchOptions: {
          args: ['--disable-features=LocalNetworkAccessChecks'],
        },
      },
      grep: /@layout/,
    },
    {
      // Visual snapshots (hard-capped, see layout guard docs). CI-only:
      // baselines are generated on the ubuntu runner via workflow dispatch —
      // local font rendering differs and would be permanently red.
      name: 'chromium-visual',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: ['--disable-features=LocalNetworkAccessChecks'],
        },
      },
      grep: /@visual/,
    },
  ],
})
