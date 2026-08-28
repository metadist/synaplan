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
    ['json', { outputFile: 'reports/results.json' }],
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
      grepInvert: /@oidc-redirect|@noci|@visual|@ollama/,
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        ...(process.env.CI ? {} : { launchOptions: { args: ['--start-maximized'] } }),
      },
      // Firefox is a focused cross-browser SMOKE, not a second full suite.
      // Only tests tagged @crossbrowser run here — the engine-divergent flows
      // (SSE streaming, widget Shadow DOM, upload/clipboard APIs). Everything
      // else is browser-agnostic app logic already covered by chromium, so
      // running it twice adds flake + gate coupling without real signal.
      // In CI this is further AND-ed with --grep @ci, so only stable
      // @crossbrowser tests execute. See docs/E2E_TESTING.md §0.
      grep: /@crossbrowser/,
    },
    {
      name: 'chromium-oidc-redirect',
      use: { ...devices['Desktop Chrome'] },
      grep: /@oidc-redirect/,
    },
    {
      // ollama-integration.spec.ts repoints the CHAT default model at the
      // Ollama stub for the WHOLE installation (`global: true`) and restores it
      // afterwards. Any chat test running in that window talks to the stub
      // instead of its expected model and fails. It therefore gets its own
      // project so CI can give it its own job — and with it its own test stack,
      // which is the only thing that actually makes the mutation safe.
      //
      // It is excluded from the `chromium` project above; keeping it there was
      // safe only by accident of how Playwright happened to distribute spec
      // files across shards, and that broke the moment the shard count changed.
      name: 'chromium-ollama',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: ['--disable-features=LocalNetworkAccessChecks'],
        },
      },
      grep: /@ollama/,
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
