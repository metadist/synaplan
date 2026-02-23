import { test, expect } from '@playwright/test'
import { loginViaOidcButton } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test.describe('@oidc @oidc-button OIDC admin role promotion', () => {
  test('@ci @oidc @oidc-button @auth OIDC user with administrator role should access admin page', async ({
    page,
  }) => {
    // The Keycloak testuser should have the "administrator" realm role
    // (assigned in _docker/keycloak/setup.sh), which matches the
    // OIDC_ADMIN_ROLES config and should promote the user to ADMIN on login.
    await loginViaOidcButton(page)
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 })

    // Navigate to the admin page — should be accessible if OIDC admin promotion worked
    await page.goto('/admin')

    // If the user was promoted to ADMIN, the admin view renders.
    // If not (bug: role never assigned in Keycloak), the router guard
    // redirects to /chat and this assertion fails.
    await expect(page.locator('[data-testid="view-admin"]')).toBeVisible({ timeout: 10_000 })
    await expect(page).toHaveURL(/\/admin/)
  })
})
