import { defineConfig } from '@playwright/test'
import * as dotenv from 'dotenv'

dotenv.config()

const BASE_URL = process.env.SYNAPLAN_URL || 'https://demo.synaplan.com'
const STEP_DELAY = parseInt(process.env.STEP_DELAY || '1500', 10)

export default defineConfig({
  testDir: './scenarios',
  timeout: 5 * 60_000,
  expect: { timeout: 30_000 },

  use: {
    baseURL: BASE_URL,
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
    launchOptions: {
      slowMo: STEP_DELAY,
    },
    video: {
      mode: 'on',
      size: { width: 1280, height: 720 },
    },
    locale: 'en-US',
    colorScheme: 'light',
  },

  outputDir: './videos',

  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: './videos/report' }],
  ],

  projects: [
    {
      name: 'demo-recordings',
      use: {
        browserName: 'chromium',
        channel: 'chromium',
      },
    },
  ],
})
