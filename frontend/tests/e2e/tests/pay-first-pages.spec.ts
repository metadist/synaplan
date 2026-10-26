import { test, expect } from '../test-setup'

/**
 * Pay first (PF4): the sales pages. The switch is off on the test stack, so
 * /start is absent and sends the visitor to the register page, and a payment
 * the installation does not know says so.
 *
 * Deterministic and provider-free (@ci); read-only.
 */
test.describe('@ci Pay first pages', () => {
  test('the start page is absent while pay first is off', async ({ page }) => {
    await page.goto('/start?plan=PRO')
    // A signed-in visitor is sent on from /register to the chat; a signed-out
    // one stays on /register. Either way the start page itself is gone.
    await expect(page).not.toHaveURL(/\/start/)
    await expect(page.getByTestId('start-email')).toHaveCount(0)
  })

  test('an unknown payment says it could not be found', async ({ page }) => {
    await page.goto('/welcome?session_id=cs_does_not_exist')
    await expect(page.getByTestId('welcome-status')).toContainText('could not find this payment')
  })
})
