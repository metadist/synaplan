import { test, expect } from '../test-setup'
import { deleteUser, login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { clearMailHog, waitForVerificationHref, normalizeVerificationUrl } from '../helpers/email'
import { URLS, TIMEOUTS, INTERVALS } from '../config/config'

test('@002 @smoke @auth should successfully login', async ({ page }) => {
  await login(page)
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
})

test('@005 @smoke @auth logout should clear session', async ({ page }) => {
  await login(page)

  await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' })
  await page.locator(selectors.userMenu.button).click()
  await page.locator(selectors.userMenu.logoutBtn).click()

  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })
  await expect(page).toHaveURL(/login/)

  await page.goBack()
  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })
  await expect(page).toHaveURL(/login/)

  await page.goto('/profile')
  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })
  await expect(page).toHaveURL(/login/)
})

// TODO: Use a pre-verified DB fixture + delete before test, then assert login fails (avoids MailHog/verify flow).
test('@011 @auth @smoke deleted user cannot login', async ({ page, request }) => {
  const email = `deleted-user-${Date.now()}@example.test`
  const password = 'DeleteMe123!'

  await clearMailHog(request)

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

  await login(page, { user: email, pass: password })
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  await page.locator(selectors.userMenu.button).click()
  await page.locator(selectors.userMenu.logoutBtn).click()
  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  const deleted = await deleteUser(request, email)
  expect(deleted, 'Admin API must delete the user so login can be asserted').toBe(true)

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
