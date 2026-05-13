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
import { activateProViaUi, authBundle, registerFreshUser } from '../helpers/billing'
import { sendPaymentFailedWebhook } from '../helpers/webhook'

test.describe('@ci @subscription Subscription', () => {
  test('happy path: checkout PRO via mock webhook', async ({ page, request }) => {
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`

    const fresh = await registerFreshUser()
    try {
      await test.step('Arrange: login as fresh user (no prior subscription state)', async () => {
        await login(page, fresh.credentials)
      })

      await test.step('Act: select PRO plan via UI + send post-checkout webhooks', async () => {
        const bundle = await authBundle(request, fresh.credentials)
        await activateProViaUi(page, request, bundle, { customerId, subscriptionId })
      })

      await test.step('Assert: happy-path-specific render (next-billing line, no cancel date)', async () => {
        // activateProViaUi already verified PRO badge + current-plan section.
        // This step covers the assertions that are unique to a freshly-bought
        // PRO subscription (vs. e.g. one that was just downgraded):
        //   - subscription status badge is rendered
        //   - next-billing line is visible (transitively asserts that
        //     subscriptionStatus.nextBilling is truthy on the API response,
        //     since text-next-billing only renders under that v-else-if)
        //   - cancel-date marker is NOT yet visible (no scheduled cancellation)
        //   - payment-failed banner is NOT visible (no failed invoice yet)
        await expect(page.locator(selectors.subscription.badgeStatus)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.textNextBilling)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.textCancelDate)).not.toBeVisible()
        await expect(page.locator(selectors.subscription.sectionPaymentFailed)).not.toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })

  test('negative path: payment failed shows banner and hides next-billing line', async ({
    page,
    request,
  }) => {
    // Issue #856 acceptance criteria, end-to-end: after Stripe declines an
    // invoice, the SubscriptionView must surface a dedicated warning section
    // with a CTA into the customer portal, and the now-misleading
    // "Next billing on …" line must be hidden.
    const customerId = `cus_${randomUUID()}`
    const subscriptionId = `sub_${randomUUID()}`

    const fresh = await registerFreshUser()
    try {
      await test.step('Arrange: fresh user with active PRO subscription', async () => {
        await login(page, fresh.credentials)
        const bundle = await authBundle(request, fresh.credentials)
        await activateProViaUi(page, request, bundle, { customerId, subscriptionId })
        // activateProViaUi navigates to /subscription and asserts PRO state,
        // so we know the page is mounted and the banner is NOT visible yet.
        await expect(page.locator(selectors.subscription.sectionPaymentFailed)).not.toBeVisible()
      })

      await test.step('Act: send invoice.payment_failed for the same subscription', async () => {
        await sendPaymentFailedWebhook(request, { customerId, subscriptionId })
      })

      await test.step('Assert: warning section appears and next-billing line disappears', async () => {
        await page.reload()
        await expect(page.locator(selectors.subscription.sectionPaymentFailed)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.btnFixPayment)).toBeVisible()
        // text-next-billing is hidden behind `!showPaymentFailedWarning` —
        // its disappearance is the user-facing signal that the date the
        // page used to show is no longer reliable.
        await expect(page.locator(selectors.subscription.textNextBilling)).not.toBeVisible()
        // The plan card itself must keep rendering — the user still has
        // PRO access during Stripe's smart-retry window.
        await expect(page.locator(selectors.subscription.sectionCurrentPlan)).toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })
})
