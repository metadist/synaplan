import { test, expect, LOGGED_OUT } from '../test-setup'
import { TIMEOUTS } from '../config/config'
import { selectors } from '../helpers/selectors'

test.use(LOGGED_OUT)

/**
 * The closed page is a view of runtime config. The spec rewrites that
 * response in this browser only. REGISTRATION_ENABLED stays on for the
 * whole installation: other workers are signing up at the same time, and
 * flipping the switch would hide their register link. The API refusal
 * (SIGNUP_CLOSED, nothing created) is covered by the PHPUnit gate tests.
 */
test.describe('@ci @password @auth self-sign-up closed', () => {
  test('registration off closes the page and still shows sign-in', async ({ page }) => {
    test.skip(
      process.env.AUTH_METHOD === 'oidc',
      'Password sign-in is the door this test keeps open'
    )

    await page.route('**/api/v1/config/runtime**', async (route) => {
      const response = await route.fetch()
      const json = (await response.json()) as {
        auth?: { registrationEnabled?: boolean; signupPaused?: boolean }
      }
      if (json.auth) {
        json.auth.registrationEnabled = false
        json.auth.signupPaused = false
      }
      await route.fulfill({ response, json })
    })

    await page.goto('/register?redirect=%2Ffiles')
    await expect(page.getByTestId('register-closed')).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.getByTestId('form-register')).toHaveCount(0)
    await page.getByTestId('register-closed').locator('button').click()
    await expect(page).toHaveURL(/\/login\?redirect=\/files/)
    await expect(page.locator(selectors.login.password)).toBeVisible()
    await expect(page.locator(selectors.login.signUpLink)).toHaveCount(0)
  })
})
