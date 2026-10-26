import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Apps → API: API keys are part of a paid plan while the install sells
 * subscriptions (#2762). The worker user is a fresh NEW account, so it sees
 * the plan panel with a way to the plans instead of the create form; the
 * seeded admin keeps the create form. An install without billing keeps API
 * keys for every account, so the free-account expectation follows the
 * runtime config instead of assuming the CI stack's Stripe setup.
 *
 * Deterministic and provider-free (@ci); read-only.
 */
test.describe('@ci API keys plan', () => {
  test('a free account is pointed to the plans instead of a key form', async ({
    page,
    request,
  }) => {
    const runtime = await request.get(`${getApiUrl()}/api/v1/config/runtime`)
    expect(runtime.ok()).toBeTruthy()
    const billingEnabled = ((await runtime.json()) as { billing?: { enabled?: boolean } }).billing
      ?.enabled

    await page.goto('/apps/api')

    if (!billingEnabled) {
      await expect(page.getByTestId('section-create-key')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      return
    }

    await expect(page.getByTestId('section-plan-required')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.getByTestId('section-create-key')).toHaveCount(0)
    await expect(page.getByTestId('section-keys-table')).toHaveCount(0)

    await page.getByTestId('link-see-plans').click()
    await expect(page).toHaveURL(/\/subscription/)
  })

  test('a paid account keeps the key form', async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/apps/api')

    await expect(page.getByTestId('section-create-key')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.getByTestId('section-plan-required')).toHaveCount(0)
  })
})
