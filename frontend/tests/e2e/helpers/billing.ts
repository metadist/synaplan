import { randomUUID } from 'crypto'
import type { APIRequestContext, Page } from '@playwright/test'
import { expect, request as playwrightRequest } from '@playwright/test'
import { deleteUser, getAuthHeaders } from './auth'
import { waitForVerificationHref } from './email'
import { selectors } from './selectors'
import { CREDENTIALS } from '../config/credentials'
import { getApiUrl, INTERVALS, TIMEOUTS, URLS } from '../config/config'
import {
  sendCheckoutCompletedWebhook,
  sendSubscriptionCreatedWebhook,
  type WebhookResult,
} from './webhook'

const API_URL = getApiUrl()

export interface AuthBundle {
  headers: { Cookie: string }
  userId: string
}

export function expectWebhookSuccess(result: WebhookResult, label: string): void {
  if (result.status < 200 || result.status >= 300) {
    throw new Error(
      `Webhook ${label} failed: HTTP ${result.status}, body: ${JSON.stringify(result.body)}`
    )
  }
  if (result.body.success !== true) {
    throw new Error(`Webhook ${label} did not return success: body: ${JSON.stringify(result.body)}`)
  }
}

/**
 * Navigate to the subscription page via the sidebar, taking the correct UI path
 * for the current plan state:
 *   - FREE user → dedicated `btn-sidebar-v2-upgrade` (the dropdown does NOT contain
 *     a subscription item per `v-if="...isPro"` in SidebarV2.vue).
 *   - PRO+ user → user-menu dropdown → `btn-sidebar-v2-subscription`.
 *
 * Waits for the user-button (always rendered once the sidebar is hydrated for an
 * authenticated user) before branching, so the plan-conditional upgrade button is
 * either definitely rendered alongside it or definitely not. Without this wait,
 * `Locator.isVisible()` (which has no built-in retry) races with hydration after
 * `page.reload()` and silently picks the wrong branch.
 */
export async function navigateToSubscriptionViaUI(page: Page): Promise<void> {
  const userBtn = page.locator(selectors.userMenu.button)
  await expect(userBtn).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  const upgradeBtn = page.locator(selectors.userMenu.upgradeBtn)
  if (await upgradeBtn.isVisible()) {
    await upgradeBtn.click()
  } else {
    await userBtn.click()
    const subscriptionBtn = page.locator(selectors.userMenu.subscriptionBtn)
    await expect(subscriptionBtn).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await subscriptionBtn.click()
  }
  await page.waitForSelector(selectors.subscription.page, { timeout: TIMEOUTS.STANDARD })
}

/**
 * Login via API and resolve the user's numeric ID.
 * Returns Cookie headers for subsequent authenticated calls and the user ID
 * needed for Stripe webhook payloads (client_reference_id / metadata.user_id).
 */
export async function authBundle(
  request: APIRequestContext,
  credentials: { user: string; pass: string }
): Promise<AuthBundle> {
  const headers = await getAuthHeaders(request, credentials)
  const meRes = await request.get(`${API_URL}/api/v1/auth/me`, { headers })
  if (!meRes.ok()) {
    throw new Error(`Failed to fetch /auth/me: HTTP ${meRes.status()}`)
  }
  const meData = (await meRes.json()) as { user: { id: number } }
  return { headers, userId: String(meData.user.id) }
}

/**
 * Register, verify-email and return credentials for a one-off user that is
 * isolated from the worker-scoped `workerUser` fixture. Use this for tests that
 * REQUIRE a user with no prior subscription state (e.g. the checkout happy path),
 * since the worker user accumulates state from earlier tests in the same worker.
 *
 * Returns a `dispose()` callback that deletes the user via the admin API. Callers
 * should invoke it in a `test.afterAll`/`test.afterEach` or — since this is meant
 * for single-test usage — in a `try/finally` around the test body.
 */
export async function registerFreshUser(): Promise<{
  credentials: { user: string; pass: string }
  dispose: () => Promise<void>
}> {
  const password = 'E2eTest1234!'
  const email = `e2e-fresh-${randomUUID()}@test.synaplan.com`
  const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })

  const regRes = await ctx.post(`${API_URL}/api/v1/auth/register`, {
    data: { email, password, recaptchaToken: '' },
  })
  if (!regRes.ok()) {
    await ctx.dispose()
    throw new Error(`Fresh-user registration failed for ${email}: ${regRes.status()}`)
  }

  const href = await waitForVerificationHref(ctx, email, {
    timeout: TIMEOUTS.LONG,
    intervals: INTERVALS.FAST(),
  })
  const token = new URL(href, URLS.BASE_URL).searchParams.get('token')
  if (!token) {
    await ctx.dispose()
    throw new Error(`No verification token in URL: ${href}`)
  }

  const verifyRes = await ctx.post(`${API_URL}/api/v1/auth/verify-email`, { data: { token } })
  if (!verifyRes.ok()) {
    await ctx.dispose()
    throw new Error(`Email verification failed for ${email}: ${verifyRes.status()}`)
  }

  return {
    credentials: { user: email, pass: password },
    dispose: async () => {
      try {
        await deleteUser(ctx, email, CREDENTIALS.getAdminCredentials())
      } catch {
        // best-effort cleanup; worker shutdown will not retain this context.
      }
      await ctx.dispose()
    },
  }
}

export interface ProSubscription {
  customerId: string
  subscriptionId: string
}

/**
 * Activate a PRO subscription for the test user via mock webhooks. Sends
 * checkout.session.completed → customer.subscription.created and asserts a
 * 2xx body with `success: true` for each. The webhook handlers flush
 * Doctrine synchronously, so a 200 response IS the persistence sync point —
 * no separate /status poll is needed; the calling test's UI assertions
 * cover any remaining Vue render delay via Playwright's auto-retrying
 * matchers.
 *
 * Use this as the "Arrange" step for tests that need a known active baseline.
 */
export async function activateProSubscription(
  request: APIRequestContext,
  opts: { userId: string; customerId: string; subscriptionId: string }
): Promise<ProSubscription> {
  const checkoutResult = await sendCheckoutCompletedWebhook(request, opts)
  expectWebhookSuccess(checkoutResult, 'checkout.session.completed')

  const createdResult = await sendSubscriptionCreatedWebhook(request, opts)
  expectWebhookSuccess(createdResult, 'customer.subscription.created')

  return { customerId: opts.customerId, subscriptionId: opts.subscriptionId }
}
