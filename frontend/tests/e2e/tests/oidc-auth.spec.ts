import { test, expect } from '@playwright/test'
import { loginViaOidcButton } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@ci @oidc @oidc-button OIDC Button Login', () => {
  test('@auth should login via OIDC button click', async ({ page }) => {
    await test.step('Act: login via OIDC button', async () => {
      await loginViaOidcButton(page)
    })

    await test.step('Assert: chat input is visible', async () => {
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
    })
  })

  test('@auth should logout from OIDC session', async ({ page }) => {
    await test.step('Arrange: login via OIDC button', async () => {
      await loginViaOidcButton(page)
    })

    await test.step('Act: logout via user menu', async () => {
      await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' })
      await page.locator(selectors.userMenu.button).click()
      await page.locator(selectors.userMenu.logoutBtn).click()
    })

    await test.step('Assert: redirected to logged-out page', async () => {
      // OIDC logout redirects through Keycloak and lands on /logged-out
      await expect(page.locator(selectors.loggedOut.page)).toBeVisible({ timeout: 15_000 })
      await expect(page).toHaveURL(/logged-out/)
    })
  })
})
