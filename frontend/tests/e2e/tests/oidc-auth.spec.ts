import { test, expect } from '@playwright/test'
import { loginViaOidcButton } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@oidc @oidc-button button login', () => {
  test('@ci @oidc @oidc-button @auth should login via OIDC button click', async ({ page }) => {
    await loginViaOidcButton(page)
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
  })

  test('@ci @oidc @oidc-button @auth should logout from OIDC session', async ({ page }) => {
    await loginViaOidcButton(page)

    await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' })
    await page.locator(selectors.userMenu.button).click()
    await page.locator(selectors.userMenu.logoutBtn).click()

    // OIDC logout redirects through Keycloak and lands on /logged-out
    await expect(page.locator(selectors.loggedOut.page)).toBeVisible({ timeout: 15_000 })
    await expect(page).toHaveURL(/logged-out/)
  })
})
