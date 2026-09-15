import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Admin dashboard coverage beyond impersonation (covered separately in
 * `admin-impersonation-chat.spec.ts`): the server-side user search, which spans
 * the debounced frontend input, the admin API and the rendered result list —
 * genuine end-to-end territory.
 *
 * Two other admin surfaces are covered by cheaper component tests instead of
 * E2E (they are pure client-side wiring with no server round-trip worth an
 * end-to-end run): the registration chart controls (period/group-by emit +
 * line/bar toggle) in `tests/unit/components/admin/RegistrationChart.spec.ts`,
 * and the subscriptions panel edit/cancel/save wiring in
 * `tests/unit/components/AdminSubscriptionsPanel.spec.ts`.
 *
 * Deterministic and provider-free (@ci); no persistent state is mutated, so no
 * teardown is required. Auth: the worker `storageState` is a non-admin user, so
 * this spec logs in as the seeded admin (`admin@synaplan.com`) via the UI, same
 * as the impersonation spec. UI paging (prev/next) is intentionally not
 * exercised: it only renders with >50 users (`totalPages > 1`), which cannot be
 * forced deterministically.
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

    await page.goto('/admin/people')
    await expect(page.locator(selectors.pages.people)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.sectionUsers)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    // Wait for the initial (unfiltered) load to render at least the admin row.
    await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    await test.step('A full-email search returns exactly the matching user', async () => {
      await page.locator(selectors.admin.userSearch).fill(adminCreds.user)
      // Debounced (300ms) → server-side filter. Positive count waits through
      // the loading spinner (which unmounts the rows).
      await expect(page.locator(selectors.admin.userLevelSelectAny)).toHaveCount(1, {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible()
    })

    await test.step('A non-matching search empties the list', async () => {
      await page.locator(selectors.admin.userSearch).fill('zzz-no-such-user-zzz@nowhere.invalid')
      // Wait for the empty-state marker, NOT toHaveCount(0): the spinner
      // unmounts every row while the zzz request is still in flight, so a
      // zero count matches loading and lets the next fill race a stale empty
      // response over the restored full list.
      await expect(page.locator(selectors.admin.usersEmpty)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Clearing the search restores the full list', async () => {
      await page.locator(selectors.admin.userSearch).fill('')
      await expect(page.locator(selectors.admin.userLevelSelect(adminId))).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })

  test('People is the only users home; Groups stay flag-gated', async ({ page, request }) => {
    const adminCookie = await loginViaApi(request, CREDENTIALS.getAdminCredentials())
    const runtimeRes = await request.get(`${getApiUrl()}/api/v1/config/runtime`, {
      headers: { Cookie: adminCookie },
    })
    expect(runtimeRes.ok()).toBeTruthy()
    const runtime = (await runtimeRes.json()) as { features?: { iamGroups?: boolean } }
    const iamGroups = runtime.features?.iamGroups === true

    await page.goto('/admin/people')
    await expect(page.locator(selectors.pages.people)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.sectionUsers)).toBeVisible()
    await expect(page.locator('[data-testid="page-not-found"]')).toHaveCount(0)
    if (iamGroups) {
      await expect(page.locator('[data-testid="tab-groups"]')).toBeVisible()
    } else {
      await expect(page.locator('[data-testid="tab-groups"]')).toHaveCount(0)
    }
    await expect(page.locator(selectors.people.backToOperate)).toBeVisible()
    await page.locator(selectors.people.backToOperate).click()
    await expect(page.locator(selectors.pages.admin)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.tabUsers)).toHaveCount(0)

    await page.goto('/groups')
    if (iamGroups) {
      await expect(page.locator('[data-testid="view-my-groups"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    } else {
      // Its only action links into People, so the page itself stays unroutable.
      await expect(page.locator('[data-testid="page-not-found"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="view-my-groups"]')).toHaveCount(0)
    }

    await page.goto('/admin?tab=users')
    await expect(page).toHaveURL(/\/admin\/people/)
    await expect(page.locator(selectors.pages.people)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.admin.sectionUsers)).toBeVisible()
    await expect(page.locator(selectors.pages.admin)).toHaveCount(0)
  })

  test('Policies tab loads group settings without an error toast', async ({ page, request }) => {
    const adminCookie = await loginViaApi(request, CREDENTIALS.getAdminCredentials())
    const runtimeRes = await request.get(`${getApiUrl()}/api/v1/config/runtime`, {
      headers: { Cookie: adminCookie },
    })
    expect(runtimeRes.ok()).toBeTruthy()
    const runtime = (await runtimeRes.json()) as { features?: { iamPolicies?: boolean } }
    if (runtime.features?.iamPolicies !== true) {
      test.skip(true, 'IAM group policies are off')
    }

    let createdGroupId: number | undefined
    try {
      const groupsRes = await request.get(`${getApiUrl()}/api/v1/admin/groups`, {
        headers: { Cookie: adminCookie },
      })
      expect(groupsRes.ok()).toBeTruthy()
      const groupsBody = (await groupsRes.json()) as { groups?: { id: number }[] }
      if ((groupsBody.groups ?? []).length === 0) {
        const created = await request.post(`${getApiUrl()}/api/v1/admin/groups`, {
          headers: { Cookie: adminCookie, 'Content-Type': 'application/json' },
          data: { name: 'E2E Policies', description: '' },
        })
        expect(created.ok()).toBeTruthy()
        const createdBody = (await created.json()) as { group?: { id: number } }
        createdGroupId = createdBody.group?.id
        expect(createdGroupId, 'created group must return an id').toBeTruthy()
      }

      await page.goto('/admin/people?tab=policies')
      await expect(page.locator(selectors.pages.people)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.people.sectionPolicies)).toBeVisible()
      // Wrapper mounts before getGroupConfig finishes; wait for the form so a
      // parse-error toast cannot race past a zero-count assertion.
      await expect(page.locator(selectors.people.sectionPolicyDefaults)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.notification.error)).toHaveCount(0)
    } finally {
      if (createdGroupId !== undefined) {
        const deleted = await request.delete(`${getApiUrl()}/api/v1/admin/groups/${createdGroupId}`, {
          headers: { Cookie: adminCookie },
        })
        expect(deleted.ok()).toBeTruthy()
      }
    }
  })
})
