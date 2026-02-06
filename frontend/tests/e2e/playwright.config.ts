import { defineConfig, devices } from '@playwright/test'
import dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'
import { URLS } from './config/config'

// Get __dirname equivalent in ES modules
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

// Load .env file from e2e directory (loads before config.ts imports)
dotenv.config({ path: path.join(__dirname, '.env.local') })

/**
 * Playwright E2E Test Configuration
 * BaseURL: http://localhost:5173 (overridable via BASE_URL ENV)
 */
export default defineConfig({
  // Test directory (relative to this config file)
  testDir: './tests',

  // Retries, timeout, and pacing
  retries: 0,
  timeout: 60_000,

  // BaseURL from centralized config
  use: {
    baseURL: URLS.BASE_URL,
    headless: process.env.CI ? true : false,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
    // launchOptions: {
    //   slowMo: 500,
    // },
  },

  // Reporters
  reporter: [
    ['list'],
    ['junit', { outputFile: 'reports/junit.xml' }],
    ['html', { outputFolder: 'reports/html', open: 'never' }],
  ],

  // Output directory for traces, screenshots, etc.
  outputDir: 'test-results',

  // Worker configuration
  workers: 1,

  // Browser projects
  // Both browsers defined for CI, but local dev uses --project=chromium for speed
  // Local: fullscreen (viewport: null + --start-maximized). CI: fixed viewport for stability.
  projects: [
    {
      name: 'chromium',
      use: {
        // Extract device config but remove deviceScaleFactor to allow large viewport
        ...Object.fromEntries(
          Object.entries(devices['Desktop Chrome']).filter(([key]) => key !== 'deviceScaleFactor')
        ),
        // Local: full window (no pixel viewport). CI: fixed size for headless stability
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
        // Extract device config but remove deviceScaleFactor to allow large viewport
        ...Object.fromEntries(
          Object.entries(devices['Desktop Firefox']).filter(([key]) => key !== 'deviceScaleFactor')
        ),
        // Local: full window (no pixel viewport). CI: fixed size for headless stability
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

  // grep: /@smoke/,
})
