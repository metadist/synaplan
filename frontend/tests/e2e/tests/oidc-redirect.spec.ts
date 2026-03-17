import { test, expect } from '@playwright/test'
import { loginViaOidcRedirect } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@oidc @oidc-redirect auto-redirect', () => {
  test('@ci @oidc @oidc-redirect @auth should auto-redirect to Keycloak on login page', async ({
    page,
  }) => {
    await loginViaOidcRedirect(page)
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
  })

  test('@ci @oidc @oidc-redirect @auth should not redirect on session_expired', async ({
    page,
  }) => {
    await page.goto('/login?reason=session_expired')

    // Should show session expired section with manual SSO button, not auto-redirect
    const sessionExpiredSection = page.locator(selectors.oidc.sessionExpiredSection)
    await expect(sessionExpiredSection).toBeVisible({ timeout: 10_000 })

    // Password form should still be hidden
    await expect(page.locator(selectors.login.email)).not.toBeVisible()

    // Manual SSO button should be available
    await expect(page.locator(selectors.oidc.keycloakButton)).toBeVisible()
  })
})
