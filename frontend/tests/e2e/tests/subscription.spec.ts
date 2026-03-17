import { randomUUID } from 'crypto'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, getAuthHeaders } from '../helpers/auth'
import { getApiUrl, TIMEOUTS, INTERVALS } from '../config/config'
import {
  sendCheckoutCompletedWebhook,
  sendSubscriptionCreatedWebhook,
  sendPaymentFailedWebhook,
  type WebhookResult,
} from '../helpers/webhook'

const API_URL = getApiUrl()

async function navigateToSubscriptionViaUI(page: import('@playwright/test').Page): Promise<void> {
  await page.locator(selectors.userMenu.button).click()
  await page.locator(selectors.userMenu.subscriptionBtn).click()
  await page.waitForSelector(selectors.subscription.page, { timeout: TIMEOUTS.STANDARD })
}

function expectWebhookSuccess(result: WebhookResult, label: string): void {
  if (result.status < 200 || result.status >= 300) {
    throw new Error(
      `Webhook ${label} failed: HTTP ${result.status}, body: ${JSON.stringify(result.body)}`
    )
  }
  if (result.body.success !== true) {
    throw new Error(`Webhook ${label} did not return success: body: ${JSON.stringify(result.body)}`)
  }
}

async function pollSubscriptionStatus(
  request: import('@playwright/test').APIRequestContext,
  headers: { Cookie: string },
  predicate: (status: Record<string, unknown>) => boolean,
  label: string
): Promise<Record<string, unknown>> {
  let lastStatus: Record<string, unknown> = {}
  await expect
    .poll(
      async () => {
        const res = await request.get(`${API_URL}/api/v1/subscription/status`, { headers })
        lastStatus = await res.json()
        return predicate(lastStatus)
      },
      {
        message: `Polling subscription status for: ${label}. Last status: ${JSON.stringify(lastStatus)}`,
        intervals: INTERVALS.FAST(),
        timeout: TIMEOUTS.STANDARD,
      }
    )
    .toBe(true)
  return lastStatus
}

test.describe('@ci @subscription Subscription', () => {
  test('happy path: checkout PRO via mock webhook', async ({ page, request, credentials }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    let userId: string
    let headers: { Cookie: string }

    await test.step('Arrange: login and get user ID', async () => {
      await login(page, credentials)
      headers = await getAuthHeaders(request, credentials)
      const meRes = await request.get(`${API_URL}/api/v1/auth/me`, { headers })
      const meData = await meRes.json()
      userId = String(meData.user.id)
    })

    await test.step('Arrange: navigate to subscription and verify no active plan', async () => {
      await navigateToSubscriptionViaUI(page)
      await page.waitForSelector(selectors.subscription.cardPlan, { timeout: TIMEOUTS.STANDARD })

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
      await pollSubscriptionStatus(
        request,
        headers,
        (s) => s.hasSubscription === true && s.plan === 'PRO' && s.status === 'active',
        'hasSubscription=true, plan=PRO, status=active'
      )
    })

    await test.step('Assert: subscription page shows PRO active', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      const currentPlanSection = page.locator(selectors.subscription.sectionCurrentPlan)
      await expect(currentPlanSection).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      const levelBadge = page.locator(selectors.subscription.badgeCurrentLevel)
      await expect(levelBadge).toHaveText('PRO')

      const statusBadge = page.locator(selectors.subscription.badgeStatus)
      await expect(statusBadge).toBeVisible()
    })
  })

  test('negative path: payment failed after active subscription', async ({
    page,
    request,
    credentials,
  }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`
    let userId: string
    let headers: { Cookie: string }

    await test.step('Arrange: login and get user ID', async () => {
      await login(page, credentials)
      headers = await getAuthHeaders(request, credentials)
      const meRes = await request.get(`${API_URL}/api/v1/auth/me`, { headers })
      const meData = await meRes.json()
      userId = String(meData.user.id)
    })

    await test.step('Arrange: activate PRO subscription via webhooks', async () => {
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

      await pollSubscriptionStatus(
        request,
        headers,
        (s) => s.hasSubscription === true && s.plan === 'PRO',
        'hasSubscription=true, plan=PRO'
      )
    })

    await test.step('Arrange: verify PRO is shown in UI', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      const levelBadge = page.locator(selectors.subscription.badgeCurrentLevel)
      await expect(levelBadge).toHaveText('PRO', { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: send payment failed webhook', async () => {
      const result = await sendPaymentFailedWebhook(request, { customerId })
      expectWebhookSuccess(result, 'invoice.payment_failed')
    })

    await test.step('Wait: poll until backend has processed payment_failed', async () => {
      await pollSubscriptionStatus(
        request,
        headers,
        (s) => s.paymentFailed === true,
        'paymentFailed=true'
      )
    })

    await test.step('Assert: subscription page still shows PRO (paymentFailed not yet visible in UI)', async () => {
      await page.reload({ waitUntil: 'domcontentloaded' })
      await navigateToSubscriptionViaUI(page)

      const currentPlanSection = page.locator(selectors.subscription.sectionCurrentPlan)
      await expect(currentPlanSection).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      const levelBadge = page.locator(selectors.subscription.badgeCurrentLevel)
      await expect(levelBadge).toHaveText('PRO')
    })
  })
})
