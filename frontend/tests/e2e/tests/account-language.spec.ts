import { test, expect, LOGGED_OUT } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { deleteUser } from '../helpers/auth'
import { waitForVerificationHref, normalizeVerificationUrl } from '../helpers/email'
import { TIMEOUTS, INTERVALS } from '../config/config'

test.use(LOGGED_OUT)

test.describe('@ci @auth Account language', () => {
  test('German signup is stored, Profile has no language field, and Settings French survives a second login', async ({
    page,
    request,
    browser,
  }) => {
    test.skip(process.env.AUTH_METHOD === 'oidc', 'Registration tests only run with password auth')
    const uniqueSuffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const testEmail = `lang+${uniqueSuffix}@test.com`
    const testPassword = 'Test1234'

    await page.addInitScript(() => {
      localStorage.setItem('language', 'de')
    })

    try {
      await page.goto('/register')
      await page
        .locator(selectors.register.fullName)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.authPageToggles.languageToggle)).toHaveText('DE')

      await page.locator(selectors.register.fullName).fill('Sprache Test')
      await page.locator(selectors.register.email).fill(testEmail)
      await page.locator(selectors.register.password).fill(testPassword)
      await page.locator(selectors.register.confirmPassword).fill(testPassword)
      await page.locator(selectors.register.submit).click()
      await page
        .locator(selectors.register.successSection)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const href = await waitForVerificationHref(request, testEmail, {
        timeout: TIMEOUTS.STANDARD,
        intervals: INTERVALS.FAST(),
      })
      await page.goto(normalizeVerificationUrl(href))
      await page
        .locator(selectors.verifyEmail.successState)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(selectors.verifyEmail.goToLoginLink).click()

      await page.locator(selectors.login.email).fill(testEmail)
      await page.locator(selectors.login.password).fill(testPassword)
      await page.locator(selectors.login.submit).click()
      await page
        .locator(selectors.nav.sidebar)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const language = await page.evaluate(async () => {
        const response = await fetch('/api/v1/auth/me', { credentials: 'include' })
        const body = (await response.json()) as { user?: { language?: string | null } }
        return body.user?.language ?? null
      })
      expect(language).toBe('de')
      await expect(page.locator('html')).toHaveAttribute('lang', 'de')

      await page.goto('/profile')
      await page.locator('[data-testid="page-profile"]').waitFor({ state: 'visible' })
      await expect(page.locator('[data-testid="field-language"]')).toHaveCount(0)
      await expect(page.locator('[data-testid="select-language"]')).toHaveCount(0)

      await page.goto('/settings')
      await page.locator('[data-testid="btn-language-fr"]').click()
      await expect(page.locator('html')).toHaveAttribute('lang', 'fr')

      await page.reload()
      await page
        .locator('[data-testid="page-settings"]')
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('html')).toHaveAttribute('lang', 'fr')

      const second = await browser.newContext()
      const page2 = await second.newPage()
      try {
        await page2.goto('/login')
        await page2.locator(selectors.login.email).fill(testEmail)
        await page2.locator(selectors.login.password).fill(testPassword)
        await page2.locator(selectors.login.submit).click()
        await page2
          .locator(selectors.nav.sidebar)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await expect(page2.locator('html')).toHaveAttribute('lang', 'fr')
      } finally {
        await second.close()
      }
    } finally {
      try {
        await deleteUser(request, testEmail)
      } catch {
        // Cleanup failure must not mask the real test failure
      }
    }
  })
})
