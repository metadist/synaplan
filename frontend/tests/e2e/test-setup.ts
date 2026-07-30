import { test as base, request as playwrightRequest } from '@playwright/test'
import { deleteUser, provisionUser, loginViaApi } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'
import { URLS } from './config/config'
import { WORKER_COUNT } from './playwright.config'

const WORKER_PASSWORD = 'E2eTest1234!'

/** Keep in sync with `PAYWALL_LAST_SHOWN_KEY` in `usePaywallPrompt.ts`. */
const PAYWALL_LAST_SHOWN_KEY = 'synaplan.paywallLastShownAt'

function workerEmail(workerIndex: number): string {
  if (workerIndex < 0 || workerIndex >= WORKER_COUNT) {
    throw new Error(
      `Worker index ${workerIndex} out of range (max ${WORKER_COUNT - 1}). ` +
        `Increase WORKER_COUNT or reduce playwright workers.`
    )
  }
  const runId = process.env.E2E_RUN_ID ?? Date.now().toString()
  return `e2e-w${workerIndex}-${runId}@test.synaplan.com`
}

type WorkerCredentials = { user: string; pass: string }

type StorageState = {
  cookies: {
    name: string
    value: string
    domain: string
    path: string
    expires: number
    httpOnly: boolean
    secure: boolean
    sameSite: 'Strict' | 'Lax' | 'None'
  }[]
  origins: { origin: string; localStorage: { name: string; value: string }[] }[]
}

export const test = base.extend<
  { credentials: WorkerCredentials },
  { workerUser: WorkerCredentials; workerStorageState: StorageState }
>({
  // One user per worker, created via the admin provisioning API (instantly
  // verified, no MailHog roundtrip) and deleted on worker teardown.
  workerUser: [
    // eslint-disable-next-line no-empty-pattern
    async ({}, use, workerInfo) => {
      const email = workerEmail(workerInfo.parallelIndex)
      const password = WORKER_PASSWORD

      const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
      try {
        await provisionUser(ctx, { email, password })
        await use({ user: email, pass: password })
      } finally {
        try {
          await deleteUser(ctx, email, CREDENTIALS.getAdminCredentials())
        } catch {
          // best-effort cleanup
        }
        await ctx.dispose()
      }
    },
    { scope: 'worker' },
  ],

  // One API login per worker; every test context starts with these auth
  // cookies (plus the session hint the app uses to enable token refresh)
  // instead of running the UI login flow per test.
  workerStorageState: [
    async ({ workerUser }, use) => {
      const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
      try {
        await loginViaApi(ctx, workerUser)
        const state = (await ctx.storageState()) as StorageState
        state.origins = [
          {
            origin: new URL(URLS.BASE_URL).origin,
            localStorage: [
              // Session hint: tells the app a session exists, so an expired
              // access cookie triggers a token refresh instead of a logout.
              { name: 'sh', value: '1' },
              // Mark the daily upgrade reminder (usePaywallPrompt) as just
              // shown. Its throttle lives in localStorage and every test gets a
              // fresh context, so it would otherwise be due on every mount and
              // the full-screen paywall would cover the chat and swallow the
              // clicks the specs are making.
              { name: PAYWALL_LAST_SHOWN_KEY, value: String(Date.now()) },
            ],
          },
        ]
        await use(state)
      } finally {
        await ctx.dispose()
      }
    },
    { scope: 'worker' },
  ],

  storageState: async ({ workerStorageState }, use) => {
    await use(workerStorageState)
  },

  credentials: async ({ workerUser }, use) => {
    await use(workerUser)
  },
})

/**
 * For specs that must start logged out (login/registration/guest flows).
 * Usage: `test.use(LOGGED_OUT)` at describe/file level.
 */
export const LOGGED_OUT: { storageState: StorageState } = {
  storageState: { cookies: [], origins: [] },
}

export { expect, type Locator, type Page } from '@playwright/test'
