import { test, expect } from '@playwright/test'
import { loginViaOidcButton } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@ci @oidc @oidc-button OIDC Admin Role Promotion', () => {
  test('@auth user with administrator role should access admin page', async ({ page }) => {
    await test.step('Arrange: login via OIDC button', async () => {
      // The Keycloak testuser should have the "administrator" realm role
      // (assigned in _docker/keycloak/setup.sh), which matches the
      // OIDC_ADMIN_ROLES config and should promote the user to ADMIN on login.
      await loginViaOidcButton(page)
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })
    })

    await test.step('Act: navigate to admin page', async () => {
      await page.goto('/admin')
    })

    await test.step('Assert: admin view is accessible', async () => {
      // If the user was promoted to ADMIN, the admin view renders.
      // If not (bug: role never assigned in Keycloak), the router guard
      // redirects to /chat and this assertion fails.
      await expect(page.locator('[data-testid="view-admin"]')).toBeVisible({ timeout: 10_000 })
      await expect(page).toHaveURL(/\/admin/)
    })
  })
})
