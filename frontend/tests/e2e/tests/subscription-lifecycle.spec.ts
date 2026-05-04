/**
 * Subscription lifecycle E2E — user-observable UI transitions only:
 * immediate cancellation, scheduled cancellation (with period-end deletion),
 * and plan upgrade. Each test arranges its own active PRO baseline via
 * webhooks, so test order does not matter.
 *
 * Backend edge cases (idempotency, signatures, pause/resume, payment_failed,
 * outbound Stripe calls, rate limiting) live in PHPUnit, not here.
 *
 * Requires the test stack (backend/.env.test) for matching webhook secret and
 * test price IDs.
 */
import { randomUUID } from 'crypto'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { TIMEOUTS } from '../config/config'
import {
  TEST_PRICE_IDS,
  sendSubscriptionDeletedWebhook,
  sendSubscriptionUpdatedWebhook,
} from '../helpers/webhook'
import {
  type AuthBundle,
  activateProSubscription,
  authBundle,
  expectWebhookSuccess,
  navigateToSubscriptionViaUI,
  pollSubscriptionStatus,
} from '../helpers/billing'

interface BaselineContext {
  bundle: AuthBundle
  customerId: string
  subscriptionId: string
}

async function loginAndActivatePro(
  page: import('@playwright/test').Page,
  request: import('@playwright/test').APIRequestContext,
  credentials: { user: string; pass: string }
): Promise<BaselineContext> {
  await login(page, credentials)
  const bundle = await authBundle(request, credentials)
  const customerId = `cus_${randomUUID()}`
  const subscriptionId = `sub_${randomUUID()}`
  await activateProSubscription(request, bundle.headers, {
    userId: bundle.userId,
    customerId,
    subscriptionId,
  })
  return { bundle, customerId, subscriptionId }
}

test.describe('@ci @subscription Subscription Lifecycle', () => {
  test('immediate cancellation hides current-plan section and re-shows plan picker', async ({
    page,
    request,
    credentials,
  }) => {
    let ctx: BaselineContext

    await test.step('Arrange: login + activate PRO baseline', async () => {
      ctx = await loginAndActivatePro(page, request, credentials)
    })

    await test.step('Act: send customer.subscription.deleted webhook', async () => {
      const result = await sendSubscriptionDeletedWebhook(request, {
        customerId: ctx.customerId,
        subscriptionId: ctx.subscriptionId,
        userId: ctx.bundle.userId,
      })
      expectWebhookSuccess(result, 'customer.subscription.deleted')

      await pollSubscriptionStatus(
        request,
        ctx.bundle.headers,
        (s) => s.plan === 'NEW' && s.status === 'canceled',
        'plan=NEW, status=canceled'
      )
    })

    await test.step('Assert: subscription page shows plan picker, no current-plan section', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      await page.waitForSelector(selectors.subscription.cardPlan, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()
      await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible()
    })
  })

  test('scheduled cancellation shows cancel-date warning and hides next-billing line', async ({
    page,
    request,
    credentials,
  }) => {
    let ctx: BaselineContext
    const cancelAt = Math.floor(Date.now() / 1000) + 30 * 24 * 3600

    await test.step('Arrange: login + activate PRO baseline', async () => {
      ctx = await loginAndActivatePro(page, request, credentials)
    })

    await test.step('Act: send subscription.updated with cancel_at_period_end=true', async () => {
      const result = await sendSubscriptionUpdatedWebhook(request, {
        customerId: ctx.customerId,
        subscriptionId: ctx.subscriptionId,
        userId: ctx.bundle.userId,
        cancelAtPeriodEnd: true,
        cancelAt,
        status: 'active',
      })
      expectWebhookSuccess(result, 'customer.subscription.updated')

      await pollSubscriptionStatus(
        request,
        ctx.bundle.headers,
        (s) => s.cancelAt === cancelAt,
        'cancelAt persisted'
      )
    })

    await test.step('Assert: UI shows the cancel-date warning instead of next-billing', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      const currentPlanSection = page.locator(selectors.subscription.sectionCurrentPlan)
      await expect(currentPlanSection).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      // The user-observable difference: amber warning paragraph appears, regular
      // next-billing paragraph disappears. We don't assert the formatted date text
      // (locale-dependent) but we do require the paragraph to be non-empty.
      const cancelDate = page.locator(selectors.subscription.textCancelDate)
      await expect(cancelDate).toBeVisible()
      await expect(cancelDate).not.toBeEmpty()
      await expect(page.locator(selectors.subscription.textNextBilling)).not.toBeVisible()

      // Plan badge still shows PRO — access continues until period end.
      await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText('PRO')
    })

    await test.step('Act: subscription.deleted fires at period end', async () => {
      const result = await sendSubscriptionDeletedWebhook(request, {
        customerId: ctx.customerId,
        subscriptionId: ctx.subscriptionId,
        userId: ctx.bundle.userId,
      })
      expectWebhookSuccess(result, 'customer.subscription.deleted')

      await pollSubscriptionStatus(
        request,
        ctx.bundle.headers,
        (s) => s.plan === 'NEW',
        'plan=NEW after period-end deletion'
      )
    })

    await test.step('Assert: after deletion, current-plan section disappears', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()
      await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible()
    })
  })

  test('plan upgrade PRO → BUSINESS updates level badge', async ({
    page,
    request,
    credentials,
  }) => {
    let ctx: BaselineContext

    await test.step('Arrange: login + activate PRO baseline', async () => {
      ctx = await loginAndActivatePro(page, request, credentials)
    })

    await test.step('Act: send subscription.updated with BUSINESS price ID', async () => {
      const result = await sendSubscriptionUpdatedWebhook(request, {
        customerId: ctx.customerId,
        subscriptionId: ctx.subscriptionId,
        userId: ctx.bundle.userId,
        priceId: TEST_PRICE_IDS.BUSINESS,
      })
      expectWebhookSuccess(result, 'customer.subscription.updated')

      await pollSubscriptionStatus(
        request,
        ctx.bundle.headers,
        (s) => s.plan === 'BUSINESS' && s.status === 'active',
        'plan=BUSINESS'
      )
    })

    await test.step('Assert: subscription page shows BUSINESS level badge', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText('BUSINESS', {
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
