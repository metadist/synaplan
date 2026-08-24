import { test, expect, LOGGED_OUT } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'
import { waitForVerificationHref, normalizeVerificationUrl } from '../helpers/email'
import { TIMEOUTS, INTERVALS } from '../config/config'

/**
 * Guest → registered conversion. `guest-chat.spec.ts` already covers that a
 * logged-out visitor gets the guest trial (banner) and that the signup link
 * points at /register; this spec closes the gap by driving the FULL conversion
 * funnel: guest banner → register → email verification → login → authenticated
 * app with the guest banner retired.
 *
 * Note: the web guest trial does NOT transfer pre-registration chat history into
 * the new account (the account is a fresh NEW user; the client guest session is
 * reset on login), so history preservation is deliberately not asserted. No
 * guest message is sent either — guest messages are capped at 5/IP across active
 * sessions (GuestSessionService::MAX_MESSAGES_PER_IP), which would make repeated
 * local runs non-idempotent.
 */
test.use(LOGGED_OUT)

test.describe('@ci @guest @auth Guest → registered conversion', () => {
  test('convert a guest into a verified, logged-in account', async ({ page, request }) => {
    test.skip(process.env.AUTH_METHOD === 'oidc', 'Guest conversion only runs with password auth')
    const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const testEmail = `guest+${uniqueSuffix}@test.com`
    const testPassword = 'Test1234'

    try {
      await test.step('Arrange: open the app as a guest', async () => {
        await page.goto('/')
        await page
          .locator(selectors.chat.textInput)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await expect(page.locator(selectors.guest.banner)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
      })

      await test.step('Act: start the signup from the guest banner', async () => {
        await page.locator(selectors.guest.bannerSignup).click()
        await expect(page).toHaveURL(/\/register/)
        await page
          .locator(selectors.register.fullName)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      })

      await test.step('Act: submit the registration form', async () => {
        await page.locator(selectors.register.fullName).fill('guest convert')
        await page.locator(selectors.register.email).fill(testEmail)
        await page.locator(selectors.register.password).fill(testPassword)
        await page.locator(selectors.register.confirmPassword).fill(testPassword)
        await page.locator(selectors.register.submit).click()
      })

      await test.step('Assert: registration succeeded (fail-fast on error)', async () => {
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

      const href = await test.step('Act: wait for the verification email', async () => {
        return waitForVerificationHref(request, testEmail, {
          timeout: TIMEOUTS.STANDARD,
          intervals: INTERVALS.FAST(),
        })
      })

      await test.step('Act: open the verification link', async () => {
        await page.goto(normalizeVerificationUrl(href))
        await page
          .locator(selectors.verifyEmail.successState)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      })

      await test.step('Act: log in as the converted user', async () => {
        await page.locator(selectors.verifyEmail.goToLoginLink).click()
        await expect(page).toHaveURL(/\/login/)
        await page.locator(selectors.login.email).fill(testEmail)
        await page.locator(selectors.login.password).fill(testPassword)
        await page.locator(selectors.login.submit).click()
      })

      await test.step('Assert: converted to an authenticated account, guest banner retired', async () => {
        await page
          .locator(selectors.nav.sidebar)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
        // The guest trial is over: the banner must not render for a real account.
        await expect(page.locator(selectors.guest.banner)).toBeHidden()
      })
    } finally {
      try {
        await deleteUser(request, testEmail)
      } catch {
        // Cleanup failure must not mask the real test failure
      }
    }
  })
})
