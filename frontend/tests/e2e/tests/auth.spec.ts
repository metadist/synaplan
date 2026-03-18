import { test, expect } from '../test-setup'
import { deleteUser, login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { waitForVerificationHref, normalizeVerificationUrl } from '../helpers/email'
import { URLS, TIMEOUTS, INTERVALS } from '../config/config'

test.describe('@ci @auth Authentication', () => {
  test('@smoke should successfully login', async ({ page, credentials }) => {
    await test.step('Act: login with credentials', async () => {
      await login(page, credentials)
    })

    await test.step('Assert: chat input is visible after login', async () => {
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
    })
  })

  test('@smoke logout should clear session', async ({ page, credentials }) => {
    await test.step('Arrange: login', async () => {
      await login(page, credentials)
    })

    await test.step('Act: logout via user menu', async () => {
      await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' })
      await page.locator(selectors.userMenu.button).click()
      await page.locator(selectors.userMenu.logoutBtn).click()
    })

    await test.step('Assert: redirected to login or logged-out page', async () => {
      // After logout the user lands on /logged-out (OIDC) or /login (standard).
      // Accept either destination as proof that the session was cleared.
      await expect(
        page.locator(`${selectors.login.email}, ${selectors.loggedOut.page}`).first()
      ).toBeVisible({ timeout: 15_000 })
      await expect(page).toHaveURL(/login|logged-out/)
    })

    await test.step('Assert: navigating to protected route does not restore session', async () => {
      await page.goto('/profile')
      await expect(
        page.locator(`${selectors.login.email}, ${selectors.loggedOut.page}`).first()
      ).toBeVisible({ timeout: 15_000 })
      await expect(page).toHaveURL(/login|logged-out/)
    })
  })

  // TODO: Use a pre-verified DB fixture + delete before test, then assert login fails (avoids MailHog/verify flow).
  test('@smoke deleted user cannot login', async ({ page, request }) => {
    const email = `deleted-user-${Date.now()}@example.test`
    const password = 'DeleteMe123!'

    await test.step('Arrange: register and verify a new user', async () => {
      const register = await request.post(`${URLS.BASE_URL}/api/v1/auth/register`, {
        data: { email, password, recaptchaToken: '' },
      })
      expect(register.ok()).toBeTruthy()

      const href = await waitForVerificationHref(request, email, {
        timeout: TIMEOUTS.LONG,
        intervals: INTERVALS.FAST(),
      })
      await page.goto(normalizeVerificationUrl(href))
      await page
        .locator(selectors.verifyEmail.successState)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      await page.locator(selectors.verifyEmail.goToLoginLink).click()
      await expect(page).toHaveURL(/\/login/)
    })

    await test.step('Arrange: verify the new user can login', async () => {
      await login(page, { user: email, pass: password })
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })

      await page.locator(selectors.userMenu.button).click()
      await page.locator(selectors.userMenu.logoutBtn).click()
      await expect(page.locator(selectors.login.email)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: delete the user via admin API', async () => {
      const deleted = await deleteUser(request, email)
      expect(deleted, 'Admin API must delete the user so login can be asserted').toBe(true)
    })

    await test.step('Assert: login attempt shows invalid credentials error', async () => {
      await page.goto('/login')
      await page.fill(selectors.login.email, email)
      await page.fill(selectors.login.password, password)
      await page.locator(selectors.login.submit).click()

      const errorEl = page.locator('.alert-error-text')
      await errorEl.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      const text = (await errorEl.innerText()).trim().toLowerCase()
      expect(text).toContain('invalid')
      expect(text).toContain('credentials')
      await expect(page).toHaveURL(/login/)
    })
  })
})
