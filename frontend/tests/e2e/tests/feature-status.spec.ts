import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * Operate → Feature status: the declared feature modules of the installation.
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
test.describe('@ci Feature status', () => {
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
})
