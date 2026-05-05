/**
 * Subscription lifecycle E2E — user-observable UI transitions only:
 * immediate cancellation, scheduled cancellation (with period-end deletion),
 * and plan upgrade. Each test arranges its own active PRO baseline by
 * driving the actual UI plan-picker (intercepted checkout) and then sending
 * the webhooks Stripe would have fired. Test order does not matter because
 * each test runs against a freshly-registered user.
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
  activateProViaUi,
  authBundle,
  expectWebhookSuccess,
  navigateToSubscriptionViaUI,
  registerFreshUser,
} from '../helpers/billing'

test.describe('@ci @subscription Subscription Lifecycle', () => {
  test('immediate cancellation hides current-plan section and re-shows plan picker', async ({
    page,
    request,
  }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    const fresh = await registerFreshUser()

    try {
      let userId: string
      await test.step('Arrange: login fresh user, then buy PRO via UI', async () => {
        await login(page, fresh.credentials)
        const bundle = await authBundle(request, fresh.credentials)
        userId = bundle.userId
        await activateProViaUi(page, request, bundle, { customerId, subscriptionId })
      })

      await test.step('Act: send customer.subscription.deleted webhook', async () => {
        const result = await sendSubscriptionDeletedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
        })
        expectWebhookSuccess(result, 'customer.subscription.deleted')
      })

      await test.step('Assert: subscription page shows plan picker, no current-plan section', async () => {
        // Webhook handler flushes synchronously → 200 response IS the sync point.
        // Reload re-fetches /status; the plan-picker becoming visible is the
        // user-observable proof that the deletion was processed end-to-end.
        await page.reload({ waitUntil: 'domcontentloaded' })
        await navigateToSubscriptionViaUI(page)

        await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })

  test('scheduled cancellation shows cancel-date warning and hides next-billing line', async ({
    page,
    request,
  }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    const cancelAt = Math.floor(Date.now() / 1000) + 30 * 24 * 3600
    const fresh = await registerFreshUser()

    try {
      let userId: string
      await test.step('Arrange: login fresh user, then buy PRO via UI', async () => {
        await login(page, fresh.credentials)
        const bundle = await authBundle(request, fresh.credentials)
        userId = bundle.userId
        await activateProViaUi(page, request, bundle, { customerId, subscriptionId })
      })

      await test.step('Act: send subscription.updated with cancel_at_period_end=true', async () => {
        const result = await sendSubscriptionUpdatedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
          cancelAtPeriodEnd: true,
          cancelAt,
          status: 'active',
        })
        expectWebhookSuccess(result, 'customer.subscription.updated')
      })

      await test.step('Assert: UI shows the cancel-date warning instead of next-billing', async () => {
        await page.reload({ waitUntil: 'domcontentloaded' })
        await navigateToSubscriptionViaUI(page)

        const currentPlanSection = page.locator(selectors.subscription.sectionCurrentPlan)
        await expect(currentPlanSection).toBeVisible({ timeout: TIMEOUTS.STANDARD })

        // The user-observable difference: amber warning paragraph appears, regular
        // next-billing paragraph disappears. We don't assert the formatted date text
        // (locale-dependent) but we do require the paragraph to be non-empty. The
        // cancel-date locator only renders when subscriptionStatus.cancelAt is truthy
        // (see SubscriptionView.vue), so its visibility transitively asserts the
        // backend persisted cancelAt.
        const cancelDate = page.locator(selectors.subscription.textCancelDate)
        await expect(cancelDate).toBeVisible({ timeout: TIMEOUTS.STANDARD })
        await expect(cancelDate).not.toBeEmpty()
        await expect(page.locator(selectors.subscription.textNextBilling)).not.toBeVisible()

        // Plan badge still shows PRO — access continues until period end.
        await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText('PRO')
      })

      await test.step('Act: subscription.deleted fires at period end', async () => {
        const result = await sendSubscriptionDeletedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
        })
        expectWebhookSuccess(result, 'customer.subscription.deleted')
      })

      await test.step('Assert: after deletion, current-plan section disappears', async () => {
        await page.reload({ waitUntil: 'domcontentloaded' })
        await navigateToSubscriptionViaUI(page)

        await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })

  test('plan upgrade PRO → BUSINESS updates level badge', async ({ page, request }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    const fresh = await registerFreshUser()

    try {
      let userId: string
      await test.step('Arrange: login fresh user, then buy PRO via UI', async () => {
        await login(page, fresh.credentials)
        const bundle = await authBundle(request, fresh.credentials)
        userId = bundle.userId
        await activateProViaUi(page, request, bundle, { customerId, subscriptionId })
      })

      await test.step('Act: send subscription.updated with BUSINESS price ID', async () => {
        const result = await sendSubscriptionUpdatedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
          priceId: TEST_PRICE_IDS.BUSINESS,
        })
        expectWebhookSuccess(result, 'customer.subscription.updated')
      })

      await test.step('Assert: subscription page shows BUSINESS level badge', async () => {
        // toHaveText auto-retries until the badge text matches, covering the
        // small Vue render delay after the post-reload /status fetch.
        await page.reload({ waitUntil: 'domcontentloaded' })
        await navigateToSubscriptionViaUI(page)

        await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText(
          'BUSINESS',
          {
            timeout: TIMEOUTS.STANDARD,
          }
        )
      })
    } finally {
      await fresh.dispose()
    }
  })
})
