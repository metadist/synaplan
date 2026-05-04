/**
 * Subscription happy-path E2E: UI plan selection → mocked checkout → mocked
 * webhooks → UI shows PRO active. Uses a freshly-registered user because this
 * scenario asserts "no active plan" as its baseline. Lifecycle scenarios live
 * in `subscription-lifecycle.spec.ts`; backend edge cases live in PHPUnit.
 *
 * Requires the test stack (backend/.env.test) for matching webhook secret and
 * test price IDs.
 */
import { randomUUID } from 'crypto'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { TIMEOUTS } from '../config/config'
import { sendCheckoutCompletedWebhook, sendSubscriptionCreatedWebhook } from '../helpers/webhook'
import {
  authBundle,
  expectWebhookSuccess,
  navigateToSubscriptionViaUI,
  pollSubscriptionStatus,
  registerFreshUser,
} from '../helpers/billing'

test.describe('@ci @subscription Subscription', () => {
  test('happy path: checkout PRO via mock webhook', async ({ page, request }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    let userId: string
    let headers: { Cookie: string }

    // Single-test user: this scenario asserts "no active plan" as its baseline,
    // and the worker-scoped fixture would carry state from earlier lifecycle tests.
    const fresh = await registerFreshUser()
    try {
      await test.step('Arrange: login as fresh user (no prior subscription state)', async () => {
        await login(page, fresh.credentials)
        const bundle = await authBundle(request, fresh.credentials)
        headers = bundle.headers
        userId = bundle.userId
      })

      await test.step('Arrange: navigate to subscription and verify no active plan', async () => {
        await navigateToSubscriptionViaUI(page)
        await page.waitForSelector(selectors.subscription.cardPlan, {
          timeout: TIMEOUTS.STANDARD,
        })

        await expect(page.locator(selectors.subscription.sectionCurrentPlan)).not.toBeVisible()
        await expect(page.locator(selectors.subscription.btnSelectPro)).toBeVisible()
      })

      let checkoutIntercepted = false
      await test.step('Act: intercept checkout and click PRO plan', async () => {
        try {
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

          await page.locator(selectors.subscription.btnSelectPro).click()

          await page.waitForSelector(selectors.subscription.cardPlan, {
            timeout: TIMEOUTS.STANDARD,
          })
          expect(checkoutIntercepted).toBe(true)
        } finally {
          await page.unroute('**/api/v1/subscription/checkout')
        }
      })

      await test.step('Act: send mock webhooks (checkout completed + subscription created)', async () => {
        const checkoutResult = await sendCheckoutCompletedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
        })
        expectWebhookSuccess(checkoutResult, 'checkout.session.completed')

        const subResult = await sendSubscriptionCreatedWebhook(request, {
          customerId,
          subscriptionId,
          userId: userId!,
        })
        expectWebhookSuccess(subResult, 'customer.subscription.created')
      })

      await test.step('Wait: poll until backend has processed webhooks', async () => {
        const status = await pollSubscriptionStatus(
          request,
          headers!,
          (s) => s.hasSubscription === true && s.plan === 'PRO' && s.status === 'active',
          'hasSubscription=true, plan=PRO, status=active'
        )
        expect(status.nextBilling).not.toBeNull()
        expect(typeof status.nextBilling).toBe('number')
      })

      await test.step('Assert: subscription page shows PRO active with next-billing line', async () => {
        await page.reload({ waitUntil: 'domcontentloaded' })
        await navigateToSubscriptionViaUI(page)

        const currentPlanSection = page.locator(selectors.subscription.sectionCurrentPlan)
        await expect(currentPlanSection).toBeVisible({ timeout: TIMEOUTS.STANDARD })

        await expect(page.locator(selectors.subscription.badgeCurrentLevel)).toHaveText('PRO')
        await expect(page.locator(selectors.subscription.badgeStatus)).toBeVisible()
        await expect(page.locator(selectors.subscription.textNextBilling)).toBeVisible()
        await expect(page.locator(selectors.subscription.textCancelDate)).not.toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })
})
