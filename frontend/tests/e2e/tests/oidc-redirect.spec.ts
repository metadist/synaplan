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

  test('@auth should auto-redirect once on session_expired', async ({ page }) => {
    await test.step('Act: navigate to login with session_expired reason', async () => {
      await page.goto('/login?reason=session_expired')
    })

    await test.step('Assert: redirected to Keycloak (live SSO sessions re-login silently)', async () => {
      await page.waitForURL(/realms\//, { timeout: 10_000 })
    })
  })

  test('@auth should fall back to manual sign-in when session_expired bounces within the guard window', async ({
    page,
  }) => {
    await test.step('Arrange: mark a just-happened expired auto-redirect', async () => {
      // The rate-limit guard lives in sessionStorage; seed it as if the
      // browser just came back from an auto-redirect that did not stick.
      await page.addInitScript(() => {
        sessionStorage.setItem('synaplan_expired_autoredirect_at', String(Date.now()))
      })
    })

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
