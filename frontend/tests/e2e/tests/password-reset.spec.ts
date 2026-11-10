import { test, expect, LOGGED_OUT } from '../test-setup'
import { deleteUser, login, provisionUser } from '../helpers/auth'
import { normalizeVerificationUrl, waitForPasswordResetHref } from '../helpers/email'
import { selectors } from '../helpers/selectors'
import { getApiUrl } from '../config/config'

// The journey starts signed out. The account is created through the admin
// API, so this file never flips the installation-wide registration switch.
test.use(LOGGED_OUT)

const PREVIOUS_PASSWORD = 'OldLane9!'
const NEXT_PASSWORD = 'NewLane9!'

test.describe('@ci @auth password reset', () => {
  test.beforeEach(() => {
    test.skip(process.env.AUTH_METHOD === 'oidc', 'Password reset is the local sign-in door')
  })

  test('an unknown address gets the same answer as a real one', async ({ page }) => {
    const unknown = `nobody-${Date.now()}@test.synaplan.com`

    await page.goto('/forgot-password')
    await expect(page.getByTestId('text-no-mailer-hint')).toHaveCount(0)
    await page.getByTestId('input-email').fill(unknown)
    await page.getByTestId('btn-send').click()

    const sent = page.getByTestId('section-email-sent')
    await expect(sent).toBeVisible()
    await expect(sent).toContainText('If an account with this email address exists')
    await expect(sent).not.toContainText(unknown)
  })

  test('a reset link from the inbox sets a new password and the old one stops working', async ({
    page,
    request,
  }) => {
    const email = `reset-${Date.now()}@test.synaplan.com`

    try {
      await provisionUser(request, { email, password: PREVIOUS_PASSWORD })

      await page.goto('/forgot-password')
      await expect(page.getByTestId('text-no-mailer-hint')).toHaveCount(0)
      await page.getByTestId('input-email').fill(email)
      await page.getByTestId('btn-send').click()

      const sent = page.getByTestId('section-email-sent')
      await expect(sent).toBeVisible()
      await expect(sent).toContainText('If an account with this email address exists')

      const href = await waitForPasswordResetHref(request, email)
      await page.goto(normalizeVerificationUrl(href))
      await expect(page.getByTestId('page-reset-password')).toBeVisible()
      await page.getByTestId('input-new-password').fill(NEXT_PASSWORD)
      await page.getByTestId('input-confirm-password').fill(NEXT_PASSWORD)
      await page.getByTestId('btn-reset-password').click()
      await expect(page.getByTestId('section-reset-success')).toBeVisible()
      await page.getByTestId('link-reset-login').click()

      await login(page, { user: email, pass: NEXT_PASSWORD })
      await expect(page.locator(selectors.chat.textInput)).toBeVisible()

      const oldLogin = await request.post(`${getApiUrl()}/api/v1/auth/login`, {
        data: { email, password: PREVIOUS_PASSWORD },
      })
      expect(oldLogin.status()).toBe(401)
    } finally {
      await deleteUser(request, email)
    }
  })
})
