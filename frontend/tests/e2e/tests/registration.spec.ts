import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'
import {
  clearMailHog,
  getDecodedEmailBody,
  extractVerificationLink,
  normalizeVerificationUrl,
  waitForVerificationEmail,
} from '../helpers/email'
import { TIMEOUTS, INTERVALS } from '../config/config'

test.beforeEach(async ({ request }) => {
  await clearMailHog(request)
})

/** Dismiss cookie banner if visible (waitFor, no fixed sleep). */
async function acceptCookiesIfShown(page: import('@playwright/test').Page) {
  const button = page.locator('[data-testid="btn-cookie-accept"]')
  try {
    await button.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await button.click()
  } catch {
    // Cookie banner not shown in this run
  }
}

test('@auth @smoke registration flow with email verification id=006', async ({ page, request }) => {
  const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const testEmail = `test+${uniqueSuffix}@test.com`
  const testPassword = 'Test1234'

  try {
    await test.step('Open registration page', async () => {
      await page.goto('/login')
      await page.locator(selectors.login.email).waitFor({ state: 'visible', timeout: 10_000 })
      await acceptCookiesIfShown(page)

      await page.locator(selectors.login.signUpLink).click()
      await expect(page).toHaveURL(/\/register/)
      await page.locator(selectors.register.fullName).waitFor({ state: 'visible', timeout: 10_000 })
    })

    await test.step('Submit registration form', async () => {
      await page.locator(selectors.register.fullName).fill('test')
      await page.locator(selectors.register.email).fill(testEmail)
      await page.locator(selectors.register.password).fill(testPassword)
      await page.locator(selectors.register.confirmPassword).fill(testPassword)
      await page.locator(selectors.register.submit).click()
    })

    await test.step('See registration success', async () => {
      await page
        .locator(selectors.register.successSection)
        .waitFor({ state: 'visible', timeout: 10_000 })
      const successText = (await page.locator(selectors.register.successSection).innerText())
        .trim()
        .toLowerCase()
      await expect(successText).toContain('registration')
      await expect(successText).toContain('success')
    })

    await test.step('Return to login screen', async () => {
      await page.locator(selectors.register.backToLoginBtn).click()
      await expect(page).toHaveURL(/\/login/)
    })

    let verificationEmail: import('../helpers/email').MailHogMessage
    await test.step('Wait for verification email', async () => {
      verificationEmail = await waitForVerificationEmail(request, testEmail, {
        timeout: 60_000,
        intervals: INTERVALS.FAST(),
      })
    })

    await test.step('Open verification link', async () => {
      const decodedBody = getDecodedEmailBody(verificationEmail)
      const verificationLink = extractVerificationLink(decodedBody)
      if (!verificationLink) {
        throw new Error('Could not extract verification link from email')
      }

      const normalizedVerificationLink = normalizeVerificationUrl(verificationLink)
      await page.goto(normalizedVerificationLink)
      await page
        .locator(selectors.verifyEmail.successState)
        .waitFor({ state: 'visible', timeout: 10_000 })
      const verifiedText = (await page.locator(selectors.verifyEmail.successState).innerText())
        .trim()
        .toLowerCase()
      await expect(verifiedText).toContain('email')
      await expect(verifiedText).toContain('verified')
    })

    await test.step('Login with verified user', async () => {
      await page.locator(selectors.verifyEmail.goToLoginLink).click()
      await expect(page).toHaveURL(/\/login/)

      await page.locator(selectors.login.email).fill(testEmail)
      await page.locator(selectors.login.password).fill(testPassword)
      await page.locator(selectors.login.submit).click()

      await page.locator(selectors.nav.sidebar).waitFor({ state: 'visible', timeout: 10_000 })
      await page.locator(selectors.userMenu.button).waitFor({ state: 'visible', timeout: 10_000 })
      await page.locator(selectors.chat.textInput).waitFor({ state: 'visible', timeout: 10_000 })
      await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
    })
  } finally {
    await deleteUser(request, testEmail)
  }
})
