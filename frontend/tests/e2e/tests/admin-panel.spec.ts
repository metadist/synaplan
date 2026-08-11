import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Admin dashboard coverage beyond impersonation (covered separately in
 * `admin-impersonation-chat.spec.ts`): user search, the registration analytics
 * chart controls, and the subscriptions panel edit wiring.
 *
 * All three are deterministic and provider-free (@ci). None mutate persistent
 * state — the subscriptions test opens and cancels edit mode without saving —
 * so no teardown is required.
 *
 * Auth: the worker `storageState` is a non-admin user, so these specs log in as
 * the seeded admin (`admin@synaplan.com`) via the UI, same as the impersonation
 * spec. UI paging (prev/next) is intentionally not exercised: it only renders
 * with >50 users (`totalPages > 1`), which cannot be forced deterministically.
 */
test.describe('@ci Admin panel', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())

    await page.locator(selectors.nav.sidebarV2Admin).click()
    const dropdown = page.locator(selectors.nav.navDropdown)
    await expect(dropdown).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await dropdown.locator(selectors.nav.flyoutLinkAdminDashboard).click()

    await page.locator(selectors.pages.admin).waitFor({
      state: 'visible',
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('overview: registration chart controls re-query on period/group-by change', async ({
    page,
  }) => {
    // Overview is the default tab; the chart renders once the initial
    // /analytics/registrations load resolves (onMounted).
    await expect(page.locator(selectors.admin.sectionOverview)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.chartTypeLine)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.chartPeriod)).toBeVisible()
    await expect(page.locator(selectors.admin.chartGroupBy)).toBeVisible()

    await test.step('Changing the period issues a new analytics query', async () => {
      const analyticsResponse = page.waitForResponse(
        (res) =>
          res.url().includes('/api/v1/admin/analytics/registrations') &&
          res.url().includes('period=90d') &&
          res.request().method() === 'GET',
        { timeout: TIMEOUTS.STANDARD }
      )
      await page.locator(selectors.admin.chartPeriod).selectOption('90d')
      const res = await analyticsResponse
      expect(res.ok()).toBeTruthy()
    })

    await test.step('Changing the grouping issues a new analytics query', async () => {
      const analyticsResponse = page.waitForResponse(
        (res) =>
          res.url().includes('/api/v1/admin/analytics/registrations') &&
          res.url().includes('groupBy=month') &&
          res.request().method() === 'GET',
        { timeout: TIMEOUTS.STANDARD }
      )
      await page.locator(selectors.admin.chartGroupBy).selectOption('month')
      const res = await analyticsResponse
      expect(res.ok()).toBeTruthy()
    })

    await test.step('Switching to the bar chart keeps the chart rendered', async () => {
      await page.locator(selectors.admin.chartTypeBar).click()
      await expect(page.locator(selectors.admin.chartTypeBar)).toBeVisible()
    })

    await expect(page.locator(selectors.notification.error)).toHaveCount(0)
  })

  test('users: server-side search narrows and clears the list', async ({ page, request }) => {
    const adminCreds = CREDENTIALS.getAdminCredentials()

    // Resolve the admin user id via the admin API — a stable row that is always
    // present in the full list and whose email is a unique search needle.
    const adminCookie = await loginViaApi(request, adminCreds)
    const usersRes = await request.get(
      `${getApiUrl()}/api/v1/admin/users?search=${encodeURIComponent(adminCreds.user)}`,
      { headers: { Cookie: adminCookie } }
    )
    expect(usersRes.ok()).toBeTruthy()
    const body = (await usersRes.json()) as { users?: { id: number; email: string }[] }
    const admin = body.users?.find((u) => u.email === adminCreds.user)
    expect(admin, `Admin ${adminCreds.user} must exist in the admin user list`).toBeTruthy()
    const adminId = admin!.id

    await page.locator(selectors.admin.tabUsers).click()
    await expect(page.locator(selectors.admin.sectionUsers)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    // Wait for the initial (unfiltered) load to render at least the admin row.
    await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    await test.step('A full-email search returns exactly the matching user', async () => {
      await page.locator(selectors.admin.userSearch).fill(adminCreds.user)
      // Debounced (300ms) → server-side filter. toHaveCount auto-retries.
      await expect(page.locator(selectors.admin.userLevelSelectAny)).toHaveCount(1)
      await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible()
    })

    await test.step('A non-matching search empties the list', async () => {
      await page.locator(selectors.admin.userSearch).fill('zzz-no-such-user-zzz@nowhere.invalid')
      await expect(page.locator(selectors.admin.userLevelSelectAny)).toHaveCount(0)
    })

    await test.step('Clearing the search restores the full list', async () => {
      await page.locator(selectors.admin.userSearch).fill('')
      await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('subscriptions: plan row edit mode opens and cancels without saving', async ({ page }) => {
    await page.locator(selectors.admin.tabSubscriptions).click()

    const panel = page.locator(selectors.admin.subscriptionsPanel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(panel.locator(selectors.admin.subscriptionsTable)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    // Seeded plans (PRO/TEAM/BUSINESS) → at least one editable row exists.
    await panel.locator(selectors.admin.subscriptionEditAny).first().click()

    const priceInput = panel.locator(selectors.admin.subscriptionPriceMonthly)
    await expect(priceInput).toBeVisible()

    // Cancel leaves edit mode without persisting anything (idempotent).
    await panel.locator(selectors.admin.subscriptionCancel).click()
    await expect(priceInput).toBeHidden()
    await expect(page.locator(selectors.notification.error)).toHaveCount(0)
  })
})
