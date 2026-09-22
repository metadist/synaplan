import { randomUUID } from 'crypto'
import type { APIRequestContext, Page } from '@playwright/test'
import { expect, request as playwrightRequest } from '@playwright/test'
import { deleteUser, getAuthHeaders, provisionUser } from './auth'
import { selectors } from './selectors'
import { CREDENTIALS } from '../config/credentials'
import { getApiUrl, TIMEOUTS, URLS } from '../config/config'
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
 * The account button is not that signal: it renders even while billing config
 * is still loading, and a one-shot `isVisible()` on Upgrade then takes the PRO
 * menu path. A FREE user has no subscription item there, so the wait dies.
 * Poll until Upgrade (rail) or Subscription (open account menu) is actually there.
 */
export async function navigateToSubscriptionViaUI(page: Page): Promise<void> {
  const userBtn = page.locator(selectors.userMenu.button)
  await expect(userBtn).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  const upgradeBtn = page.locator(selectors.userMenu.upgradeBtn)
  const subscriptionBtn = page.locator(selectors.userMenu.subscriptionBtn)

  // FREE puts Upgrade on the rail. PRO puts Subscription inside the account
  // menu, so that item does not exist until the menu is open. The account
  // button itself is always rendered and is not a signal that either control
  // exists yet — billing config can still be in flight. A single isVisible()
  // committed to the menu and then waited for a PRO-only item a FREE user
  // never gets.
  let route: 'upgrade' | 'subscription' | '' = ''
  await expect
    .poll(
      async () => {
        if (await upgradeBtn.isVisible()) {
          route = 'upgrade'
          return route
        }
        const menuOpen = await page.locator(selectors.userMenu.dropdown).isVisible()
        if (!menuOpen) {
          await userBtn.click()
        }
        if (await subscriptionBtn.isVisible()) {
          route = 'subscription'
          return route
        }
        // The account button sits under this overlay, so a second click on it
        // never lands. The overlay itself closes the menu.
        if (await page.locator(selectors.userMenu.overlay).isVisible()) {
          await page.locator(selectors.userMenu.overlay).click({ position: { x: 8, y: 8 } })
        }
        route = ''
        return route
      },
      { timeout: TIMEOUTS.STANDARD, intervals: [100, 250, 500] }
    )
    .not.toBe('')

  if (route === 'upgrade') {
    await upgradeBtn.click()
  } else {
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
 * Provision a one-off, already-verified user that is isolated from the
 * worker-scoped `workerUser` fixture. Use this for tests that REQUIRE a user
 * with no prior subscription state (e.g. the checkout happy path), since the
 * worker user accumulates state from earlier tests in the same worker.
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

  try {
    await provisionUser(ctx, { email, password })
  } catch (error) {
    await ctx.dispose()
    throw error
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
 * Activate PRO via the full user-driven UI flow:
 *
 *   1. Navigate to /subscription via the sidebar.
 *   2. Verify the FREE baseline is rendered (plan-picker visible, no
 *      current-plan section). Catches regressions where the picker
 *      disappears for new users.
 *   3. Intercept POST /api/v1/subscription/checkout and click the PRO
 *      plan button. Asserts the intercept fired so a silently-renamed
 *      endpoint or accidentally redirected click is caught.
 *   4. Send the Stripe webhooks Stripe would have fired post-checkout
 *      (checkout.session.completed + customer.subscription.created).
 *      These remain API-driven because Stripe outbound is policy-banned
 *      in CI; everything user-observable is still driven by the UI.
 *   5. Reload + verify the rendered subscription page reflects PRO
 *      active.
 *
 * Use as the Arrange step for any test that needs a freshly-bought PRO
 * user. The user must start FREE — pair this with `registerFreshUser` to
 * guarantee a clean baseline.
 */
export async function activateProViaUi(
  page: Page,
  request: APIRequestContext,
  bundle: AuthBundle,
  opts: { customerId: string; subscriptionId: string }
): Promise<ProSubscription> {
  await navigateToSubscriptionViaUI(page)
  await page.waitForSelector(selectors.subscription.cardPlan, { timeout: TIMEOUTS.STANDARD })

  await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible()
  await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()

  let checkoutIntercepted = false
  await page.route('**/api/v1/subscription/checkout', (route) => {
    checkoutIntercepted = true
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        sessionId: 'cs_test_mock',
        url: '/subscription?checkout_intercepted=true',
      }),
    })
  })

  try {
    await page.locator(selectors.subscription.btnSelectPro).click()
    await page.waitForSelector(selectors.subscription.cardPlan, { timeout: TIMEOUTS.STANDARD })
    expect(checkoutIntercepted).toBe(true)
  } finally {
    await page.unroute('**/api/v1/subscription/checkout')
  }

  const checkoutResult = await sendCheckoutCompletedWebhook(request, {
    customerId: opts.customerId,
    subscriptionId: opts.subscriptionId,
    userId: bundle.userId,
  })
  expectWebhookSuccess(checkoutResult, 'checkout.session.completed')

  const subResult = await sendSubscriptionCreatedWebhook(request, {
    customerId: opts.customerId,
    subscriptionId: opts.subscriptionId,
    userId: bundle.userId,
  })
  expectWebhookSuccess(subResult, 'customer.subscription.created')

  await page.reload({ waitUntil: 'domcontentloaded' })
  await navigateToSubscriptionViaUI(page)

  await expect(page.locator(selectors.subscription.sectionCurrentPlan)).toBeVisible({
    timeout: TIMEOUTS.STANDARD,
  })
  await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText('PRO', {
    timeout: TIMEOUTS.STANDARD,
  })

  return { customerId: opts.customerId, subscriptionId: opts.subscriptionId }
}
