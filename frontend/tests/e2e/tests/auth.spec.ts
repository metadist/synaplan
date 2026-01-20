import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '@playwright/test'
import { deleteUser, login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

const MAILHOG_URL = process.env.MAILHOG_URL ?? 'http://localhost:8025'

async function fetchVerificationToken(request: APIRequestContext, email: string): Promise<string> {
  const searchUrl = `${MAILHOG_URL}/api/v2/search?kind=to&query=${encodeURIComponent(email)}&limit=20`

  for (let attempt = 0; attempt < 20; attempt += 1) {
    const response = await request.get(searchUrl)
    if (!response.ok()) {
      throw new Error(`MailHog search failed with ${response.status()}`)
    }

    const items = (await response.json()).items ?? []
    for (const item of items) {
      const body = item.Content?.Body || item.Raw?.Data || ''
      const match = body.match(/verify-email\?token=([A-Za-z0-9-_]+)/)
      if (match) {
        return match[1]
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 500))
  }

  throw new Error(`Verification token for ${email} not found`)
}

test('@smoke @auth should successfully login id=002', async ({ page }) => {
  await login(page)
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
})

test('@smoke @auth logout should clear session id=005', async ({ page }) => {
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

test('@auth deleted user cannot login id=011', async ({ page, request }) => {
  const email = `deleted-user-${Date.now()}@example.test`
  const password = 'DeleteMe123!'

  const register = await request.post('/api/v1/auth/register', {
    data: { email, password, recaptchaToken: '' },
  })
  expect(register.ok()).toBeTruthy()

  const token = await fetchVerificationToken(request, email)
  const verify = await request.post('/api/v1/auth/verify-email', { data: { token } })
  expect(verify.ok()).toBeTruthy()

  await login(page, { user: email, pass: password })
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })

  await page.locator(selectors.userMenu.button).click()
  await page.locator(selectors.userMenu.logoutBtn).click()
  await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 })

  await deleteUser(request, email)

  await page.goto('/login')
  await page.fill(selectors.login.email, email)
  await page.fill(selectors.login.password, password)
  await page.locator(selectors.login.submit).click()

  await expect(page.locator('text=Invalid credentials')).toBeVisible({ timeout: 10_000 })
  await expect(page).toHaveURL(/login/)
})
