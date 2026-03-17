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
        // Chrome 142+ Local Network Access: disable prompt so dev-stack widget tests run without user interaction
        launchOptions: {
          args: [
            '--disable-features=LocalNetworkAccessChecks',
            ...(process.env.CI ? [] : ['--start-maximized']),
          ],
        },
      },
      grepInvert: /@oidc-redirect|@noci/,
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        ...(process.env.CI ? {} : { launchOptions: { args: ['--start-maximized'] } }),
      },
      grepInvert: /@oidc-redirect|@noci/,
    },
    {
      name: 'chromium-oidc-redirect',
      use: { ...devices['Desktop Chrome'] },
      grep: /@oidc-redirect/,
    },
  ],
})
