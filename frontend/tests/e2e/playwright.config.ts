import { defineConfig, devices } from '@playwright/test'
import dotenv from 'dotenv'

// Lade .env Datei (automatisch im Projektverzeichnis)
dotenv.config({ path: '.env.local' })

/**
 * Minimal-robustes Playwright-Smoke-Setup
 * BaseURL: http://localhost:5137 (überschreibbar via BASE_URL ENV)
 */
export default defineConfig({
  // Tests liegen im tests/-Verzeichnis (relative to this config file)
  testDir: './tests',

  // Retries und Timeout auf Config-Ebene
  retries: 0,
  timeout: 60_000,

  // BaseURL aus ENV oder Default
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:5173',
    headless: process.env.CI ? true : false,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },

  // Reporter mit Pfaden unter tests/
  reporter: [
    ['list'],
    ['junit', { outputFile: 'reports/junit.xml' }],
    ['html', { outputFolder: 'reports/html', open: 'never' }],
  ],

  // Ausgabeordner für Traces, Screenshots etc.
  outputDir: 'test-results',

  // Worker-Konfiguration
  workers: 1,

  // Projekte: All major browsers
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

  // Standard-Grep für @smoke
  grepInvert: /^(?!.*login).*$/,
})
