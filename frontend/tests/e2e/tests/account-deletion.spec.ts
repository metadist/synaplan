import { test, expect, LOGGED_OUT } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const DELETE = selectors.accountDeletion

/**
 * Public account-deletion info page (Epic 9.1 / Google Play): a signed-OUT
 * visitor must be able to learn how to delete their account and data without
 * logging in. It is the default target of branding.accountDeletionUrl and is
 * linked from store metadata, so the route must stay reachable and public.
 *
 * Runs logged out on purpose — the value of the page is that it needs no auth.
 */
test.describe('@ci Account deletion (public)', () => {
  test.use(LOGGED_OUT)

  test('unauthenticated visitor can open the page and reach the profile link', async ({ page }) => {
    await test.step('Act: open /account-deletion while signed out', async () => {
      await page.goto('/account-deletion')
    })

    await test.step('Assert: page renders and stays public (no redirect to login)', async () => {
      await expect(page.locator(DELETE.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page).toHaveURL(/\/account-deletion/, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the CTA to the in-app profile deletion is present', async () => {
      await expect(page.locator(DELETE.profileLink)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    })
  })
})
