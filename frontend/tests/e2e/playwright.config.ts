import { defineConfig, devices } from '@playwright/test'
import dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'

// Get __dirname equivalent in ES modules
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

// Load .env file from e2e directory
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

  // BaseURL from ENV or default
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:5173',
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
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
  ],

  // grep: /@smoke/,
})
