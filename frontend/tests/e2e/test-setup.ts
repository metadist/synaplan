import { test as base, request as playwrightRequest } from '@playwright/test'
import { deleteUser } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'
import { getApiUrl, URLS, TIMEOUTS, INTERVALS } from './config/config'
import { waitForVerificationHref } from './helpers/email'
import { WORKER_COUNT } from './playwright.config'

const WORKER_PASSWORD = 'E2eTest1234!'

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

export const test = base.extend<
  { credentials: WorkerCredentials },
  { workerUser: WorkerCredentials }
>({
  workerUser: [
    // eslint-disable-next-line no-empty-pattern
    async ({}, use, workerInfo) => {
      const email = workerEmail(workerInfo.parallelIndex)
      const password = WORKER_PASSWORD
      const apiUrl = getApiUrl()

      const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
      try {
        const regRes = await ctx.post(`${apiUrl}/api/v1/auth/register`, {
          data: { email, password, recaptchaToken: '' },
        })
        if (!regRes.ok()) {
          throw new Error(`Registration failed for ${email}: ${regRes.status()}`)
        }

        const href = await waitForVerificationHref(ctx, email, {
          timeout: TIMEOUTS.LONG,
          intervals: INTERVALS.FAST(),
        })
        const token = new URL(href, URLS.BASE_URL).searchParams.get('token')
        if (!token) throw new Error(`No token in verification URL: ${href}`)

        const verifyRes = await ctx.post(`${apiUrl}/api/v1/auth/verify-email`, {
          data: { token },
        })
        if (!verifyRes.ok()) {
          throw new Error(`Email verification failed for ${email}: ${verifyRes.status()}`)
        }

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

  credentials: async ({ workerUser }, use) => {
    await use(workerUser)
  },
})

export { expect, type Locator, type Page } from '@playwright/test'
