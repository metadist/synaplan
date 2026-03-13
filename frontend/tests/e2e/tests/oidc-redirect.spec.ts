import { test, expect } from '@playwright/test'
import { loginViaOidcRedirect } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@ci @oidc @oidc-redirect OIDC Auto-Redirect', () => {
  test('@auth should auto-redirect to Keycloak on login page', async ({ page }) => {
    await test.step('Act: trigger OIDC auto-redirect login', async () => {
      await loginViaOidcRedirect(page)
    })

    await test.step('Assert: chat input is visible after redirect', async () => {
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
    })
  })

  test('@auth should not redirect on session_expired', async ({ page }) => {
    await test.step('Act: navigate to login with session_expired reason', async () => {
      await page.goto('/login?reason=session_expired')
    })

    await test.step('Assert: session expired section shown with manual SSO button', async () => {
      const sessionExpiredSection = page.locator(selectors.oidc.sessionExpiredSection)
      await expect(sessionExpiredSection).toBeVisible({ timeout: 10_000 })

      await expect(page.locator(selectors.login.email)).not.toBeVisible()
      await expect(page.locator(selectors.oidc.keycloakButton)).toBeVisible()
    })
  })
})
