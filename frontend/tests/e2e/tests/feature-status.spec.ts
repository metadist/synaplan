import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Operate → System status: the declared feature modules of the installation.
 *
 * Spans the module registry (`App\Module\ModuleRegistry`), the status API
 * (`/api/v1/features/status` → `modules[]`) and the rendered section, so the
 * end-to-end run earns its keep: the same 12 ids the backend reports must come
 * out as 12 rows with a state badge each. Which modules are configured depends
 * on the stack's environment, so only the id set and the row shape are
 * asserted, never a specific state.
 *
 * Deterministic and provider-free (@ci); read-only. Auth: the worker
 * `storageState` is a non-admin user, so this spec logs in as the seeded admin.
 */
test.describe('@ci System status', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/admin/features')
    await page.locator(selectors.pages.featureStatus).waitFor({
      state: 'visible',
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('lists every declared feature module with a state badge', async ({ page, request }) => {
    // The runtime config is public and carries the same module ids the admin
    // page renders: use it as the expected set instead of hardcoding 12 names.
    const runtimeRes = await request.get(`${getApiUrl()}/api/v1/config/runtime`)
    expect(runtimeRes.ok()).toBeTruthy()
    const runtime = (await runtimeRes.json()) as {
      modules?: Record<string, { configured: boolean; gated: boolean }>
    }
    const expectedIds = Object.keys(runtime.modules ?? {}).sort()
    expect(expectedIds.length, 'runtime config must declare the feature modules').toBeGreaterThan(0)

    // The status endpoint probes every configured sidecar with a 2 s budget
    // each; on a stack whose sidecars are unreachable (the CI test stack has
    // no Tika or Docling) that adds up to ~20 s before the page can render.
    await expect(page.locator(selectors.featureStatus.summary)).toBeVisible({
      timeout: TIMEOUTS.EXTREME,
    })
    const section = page.locator(selectors.featureStatus.modulesSection)
    await expect(section).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    const rows = section.locator(selectors.featureStatus.moduleItem)
    await expect(rows).toHaveCount(expectedIds.length)

    const renderedIds = (await rows.evaluateAll((els) =>
      els.map((el) => el.getAttribute('data-module'))
    )) as string[]
    expect([...renderedIds].sort()).toEqual(expectedIds)

    // Every row: a non-empty state badge and a docs link to the enable guide.
    for (let i = 0; i < expectedIds.length; i++) {
      const row = rows.nth(i)
      await expect(row.locator(selectors.featureStatus.moduleStateBadge)).not.toHaveText('')
      await expect(row.locator(selectors.featureStatus.moduleDocsLink)).toHaveAttribute(
        'href',
        /^https:\/\/docs\.synaplan\.com\/modules\//
      )
    }

    // The runtime config and the admin page must agree on what is configured:
    // `absent` is the only state of an unconfigured module.
    for (const id of expectedIds) {
      const state = await section
        .locator(`${selectors.featureStatus.moduleItem}[data-module="${id}"]`)
        .getAttribute('data-state')
      const configured = runtime.modules?.[id]?.configured === true
      expect(
        state !== 'absent',
        `module ${id}: runtime config says configured=${configured}, page says ${state}`
      ).toBe(configured)
    }
  })

  test('shows the compute sidecar card with one plain state sentence', async ({ page }) => {
    // The backend always sends the compute entry (healthy or degraded), so
    // the card renders on every stack. Reachability itself depends on the
    // environment and is asserted only as internal consistency.
    await expect(page.locator(selectors.featureStatus.summary)).toBeVisible({
      timeout: TIMEOUTS.EXTREME,
    })
    const card = page.locator(selectors.featureStatus.computeSection)
    await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(card.locator(selectors.featureStatus.computeStateLine)).not.toHaveText('')
    await expect(card.locator(selectors.featureStatus.computeStatusPill)).not.toHaveText('')
    await expect(card.locator(selectors.featureStatus.computeConfigLink)).toHaveAttribute(
      'href',
      /\/admin\/config\?tab=tools&section=compute/
    )
  })

  test('shows stopped jobs on the card and a sidebar hint that opens system status', async ({
    page,
  }) => {
    await mockSchedulerStatus(page, 'stale')
    await page.goto('/')

    const hint = page
      .locator(selectors.featureStatus.schedulerSidebarHint)
      .filter({ visible: true })
    await expect(hint).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    await hint.click()

    await expect(page).toHaveURL(/\/admin\/features/)
    await expect(page.locator(selectors.featureStatus.schedulerStateLine)).toContainText(
      'Background jobs have stopped',
      { timeout: TIMEOUTS.STANDARD }
    )
    await expect(page.locator(selectors.featureStatus.schedulerStateLine)).toContainText(
      'are not running'
    )
  })

  test('shows a running sentence and no sidebar hint', async ({ page }) => {
    await mockSchedulerStatus(page, 'running')
    await page.goto('/admin/features')

    await expect(page.locator(selectors.featureStatus.schedulerStateLine)).toContainText(
      'Background jobs are running',
      { timeout: TIMEOUTS.STANDARD }
    )
    await expect(
      page.locator(selectors.featureStatus.schedulerSidebarHint).filter({ visible: true })
    ).toHaveCount(0)
  })
})

async function mockSchedulerStatus(page: Page, state: 'stale' | 'running'): Promise<void> {
  const now = Math.floor(Date.now() / 1000)
  const finished = state === 'running' ? now - 300 : now - 7_200
  await page.route('**/api/v1/admin/scheduler/status', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        state,
        maxAgeSeconds: 180,
        checkedAt: now,
        lastRunAt: finished,
        lanes: (['tick', 'tasks', 'hourly', 'daily', 'health'] as const).map((lane) => ({
          lane,
          lastStartedAt: finished - 5,
          lastFinishedAt: finished,
          failedJobs: [],
          unfinishedJobs: [],
        })),
      }),
    })
  })
}
