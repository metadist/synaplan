import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test('@ci @smoke @auth should successfully login id=002', async ({ page }) => {
  await login(page)
  await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
})

test('@ci @smoke @auth logout should clear session id=005', async ({ page }) => {
  await login(page)

  await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' })
  await page.locator(selectors.userMenu.button).click()
  await page.locator(selectors.userMenu.logoutBtn).click()

  // After logout the user lands on /logged-out (OIDC) or /login (standard).
  // Accept either destination as proof that the session was cleared.
  await expect(
    page.locator(`${selectors.login.email}, ${selectors.loggedOut.page}`).first(),
  ).toBeVisible({ timeout: 15_000 })
  await expect(page).toHaveURL(/login|logged-out/)

  // Navigating to a protected route must not restore the session
  await page.goto('/profile')
  await expect(
    page.locator(`${selectors.login.email}, ${selectors.loggedOut.page}`).first(),
  ).toBeVisible({ timeout: 15_000 })
  await expect(page).toHaveURL(/login|logged-out/)
})
