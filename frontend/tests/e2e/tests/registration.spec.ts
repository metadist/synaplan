import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'
import { clearMailHog, waitForVerificationHref, normalizeVerificationUrl } from '../helpers/email'
import { TIMEOUTS, INTERVALS } from '../config/config'

test.beforeEach(async ({ request }) => {
  await clearMailHog(request)
})

/** Dismiss cookie banner if visible; idempotent, fast (short timeout). Only tolerates banner not present/hidden; if visible but click fails, test fails. */
async function acceptCookiesIfShown(page: import('@playwright/test').Page) {
  const button = page.locator('[data-testid="btn-cookie-accept"]')
  try {
    await button.waitFor({ state: 'visible', timeout: 2_000 })
  } catch {
    return
  }
  await button.click()
}

test('@auth @smoke User can register and login with email verification id=006', async ({
  page,
  request,
}) => {
  const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const testEmail = `test+${uniqueSuffix}@test.com`
  const testPassword = 'Test1234'

  try {
    await test.step('Open registration page', async () => {
      await page.goto('/login')
      await page
        .locator(selectors.login.email)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await acceptCookiesIfShown(page)

      await page.locator(selectors.login.signUpLink).click()
      await expect(page).toHaveURL(/\/register/)
      await page
        .locator(selectors.register.fullName)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Submit registration form', async () => {
      await page.locator(selectors.register.fullName).fill('test')
      await page.locator(selectors.register.email).fill(testEmail)
      await page.locator(selectors.register.password).fill(testPassword)
      await page.locator(selectors.register.confirmPassword).fill(testPassword)
      await page.locator(selectors.register.submit).click()
    })

      await test.step('See registration success or error (fail-fast)', async () => {
      const successLocator = page.locator(selectors.register.successSection)
      const errorLocator = page.locator(selectors.register.errorAlert)
      const result = await Promise.race([
        successLocator
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
          .then(() => 'success' as const),
        errorLocator
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
          .then(() => 'error' as const),
      ])
      if (result === 'error') {
        const text = await errorLocator.textContent()
        throw new Error(`Registration failed: ${text?.trim() ?? 'error alert visible'}`)
      }
    })

    await test.step('Return to login screen', async () => {
      await page.locator(selectors.register.backToLoginBtn).click()
      await expect(page).toHaveURL(/\/login/)
    })

    const href = await test.step('Wait for verification email', async () => {
      return waitForVerificationHref(request, testEmail, {
        timeout: TIMEOUTS.STANDARD,
        intervals: INTERVALS.FAST(),
      })
    })

    await test.step('Open verification link', async () => {
      await page.goto(normalizeVerificationUrl(href))
      await page
        .locator(selectors.verifyEmail.successState)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Login with verified user', async () => {
      await page.locator(selectors.verifyEmail.goToLoginLink).click()
      await expect(page).toHaveURL(/\/login/)

      await page.locator(selectors.login.email).fill(testEmail)
      await page.locator(selectors.login.password).fill(testPassword)
      await page.locator(selectors.login.submit).click()

      await page
        .locator(selectors.nav.sidebar)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page
        .locator(selectors.chat.textInput)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
    })
  } finally {
    try {
      await deleteUser(request, testEmail)
    } catch {
      // Cleanup failure must not mask the real test failure
    }
  }
})
