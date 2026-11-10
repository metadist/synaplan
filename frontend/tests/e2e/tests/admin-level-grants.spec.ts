import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * People → Users shows where a level comes from, and removing the
 * administrator grant is a separate action on the same row.
 */
test.describe('@ci Admin level grants', () => {
  test('an administrator grant can be set and removed from the row', async ({ page, request }) => {
    const admin = CREDENTIALS.getAdminCredentials()
    const cookie = await loginViaApi(request, admin)
    const email = `grants-e2e-${Date.now()}@synaplan.test`
    const created = await request.post(`${getApiUrl()}/api/v1/admin/users/accounts`, {
      headers: { Cookie: cookie },
      data: {
        email,
        display_name: 'Grant walk',
        level: 'NEW',
        password: 'GrantWalk1',
      },
    })
    expect(created.ok()).toBeTruthy()
    const body = (await created.json()) as { user?: { id?: number } }
    const userId = body.user?.id
    expect(userId).toBeTruthy()

    // The worker session is a non-admin. Take the administrator cookies from
    // the API login instead of signing in through the form: that form is a
    // second full page load, and this check is about the People row.
    const state = await request.storageState()
    await page.context().clearCookies()
    await page.context().addCookies(state.cookies)
    await page.addInitScript(() => {
      localStorage.setItem('sh', '1')
    })

    try {
      await page.goto('/admin/people')
      await expect(page.locator(selectors.pages.people)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(selectors.admin.userSearch).fill(email)

      const select = page.locator(selectors.admin.userLevelSelect(userId!))
      await expect(select).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(select).toHaveValue('NEW')
      await expect(page.locator(`[data-testid="btn-remove-admin-grant-${userId}"]`)).toHaveCount(0)

      await select.selectOption('TEAM')
      await page.locator('[data-testid="btn-dialog-confirm"]').click()
      await expect(select).toHaveValue('TEAM', { timeout: TIMEOUTS.STANDARD })
      const remove = page.locator(`[data-testid="btn-remove-admin-grant-${userId}"]`)
      await expect(remove).toBeVisible()

      await remove.click()
      await page.locator('[data-testid="btn-dialog-confirm"]').click()
      await expect(select).toHaveValue('NEW', { timeout: TIMEOUTS.STANDARD })
      await expect(remove).toHaveCount(0)
    } finally {
      await request.delete(`${getApiUrl()}/api/v1/admin/users/${userId}`, {
        headers: { Cookie: cookie },
      })
    }
  })
})
