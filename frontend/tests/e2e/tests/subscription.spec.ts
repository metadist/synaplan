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
        await expect(page.locator(selectors.subscription.badgeStatus)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.textNextBilling)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator(selectors.subscription.textCancelDate)).not.toBeVisible()
      })
    } finally {
      await fresh.dispose()
    }
  })
})
