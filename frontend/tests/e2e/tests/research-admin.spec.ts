import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

/**
 * J-AR-7 — the administrator's switch. The admin research endpoint is
 * stubbed so the walk never changes the dev stack's settings: the switch is
 * read, turned off (consequence sentence, PUT body), turned on again, and
 * shown disabled while research itself is off.
 */

const STORED = {
  enabled: true,
  envLocked: false,
  autoEnabled: true,
  webSearchAvailable: true,
  readingModelId: null,
  checkingModelId: null,
  models: [],
  limits: { maxActiveRuns: 5, maxActiveThorough: 1 },
  budgets: {
    quick: { searches: 2, sources: 4, minutes: 1, costCap: 0.1 },
    standard: { searches: 10, sources: 15, minutes: 6, costCap: 1 },
    thorough: { searches: 30, sources: 40, minutes: 20, costCap: 5 },
  },
  sample: null,
}

/** Serves the admin research view and records every PUT body. */
async function stubAdminResearch(page: Page, initial: Partial<typeof STORED> = {}) {
  const state = { ...STORED, ...initial }
  const puts: Array<Record<string, unknown>> = []
  await page.route('**/api/v1/admin/research', async (route) => {
    if (route.request().method() === 'PUT') {
      const body = route.request().postDataJSON() as Record<string, unknown>
      puts.push(body)
      if (typeof body.autoEnabled === 'boolean') state.autoEnabled = body.autoEnabled
      if (typeof body.enabled === 'boolean') state.enabled = body.enabled
      if (body.budgets && typeof body.budgets === 'object') {
        const incoming = body.budgets as Record<string, Partial<(typeof state.budgets)['quick']>>
        for (const [depth, row] of Object.entries(incoming)) {
          const current = state.budgets[depth as keyof typeof state.budgets]
          if (!row || !current) continue
          state.budgets[depth as keyof typeof state.budgets] = { ...current, ...row }
        }
      }
      if (body.limits && typeof body.limits === 'object') {
        state.limits = { ...state.limits, ...(body.limits as typeof state.limits) }
      }
    }
    await route.fulfill({ status: 200, json: state })
  })
  return puts
}

test.describe('@ci @research The administrator turns automatic research off and on', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
  })

  test('the switch sits under Research, says what off means, and round-trips', async ({ page }) => {
    const puts = await stubAdminResearch(page)
    await page.goto('/admin/setup?tab=research')

    const row = page.locator(selectors.research.adminAuto)
    await expect(row).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(row).toContainText('Automatic research')
    await expect(row).toContainText('People can always click Answer now')
    await expect(page.locator(selectors.research.adminAutoConsequence)).toHaveCount(0)

    await page.locator(selectors.research.adminAutoTurnOff).click()
    await expect(page.locator(selectors.research.adminAutoConsequence)).toContainText(
      'Off: the chat answers every question directly',
      { timeout: TIMEOUTS.STANDARD }
    )
    expect(puts).toEqual([{ autoEnabled: false }])

    await page.locator(selectors.research.adminAutoTurnOn).click()
    await expect(page.locator(selectors.research.adminAutoConsequence)).toHaveCount(0, {
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.research.adminAutoTurnOff)).toBeVisible()
    expect(puts).toEqual([{ autoEnabled: false }, { autoEnabled: true }])
  })

  test('while research is off the switch is disabled with one sentence', async ({ page }) => {
    await stubAdminResearch(page, { enabled: false })
    await page.goto('/admin/setup?tab=research')

    await expect(page.locator(selectors.research.adminAutoNeedsResearch)).toHaveText(
      'Turn on Research first.',
      { timeout: TIMEOUTS.STANDARD }
    )
    await expect(page.locator(selectors.research.adminAutoTurnOff)).toBeDisabled()
  })

  test('a limit saves when the field is left and the save row stays put', async ({ page }) => {
    const puts = await stubAdminResearch(page)
    await page.goto('/admin/setup?tab=research')

    await expect(page.locator(selectors.research.adminSave)).toHaveCount(0)
    await expect(page.locator(selectors.research.adminUnsaved)).toHaveCount(0)

    const cap = page.locator('[data-testid="research-admin-standard-costCap"]')
    await cap.fill('2.5')
    await expect(page.locator(selectors.research.adminUnsaved)).toHaveCount(0)
    await expect(page.locator(selectors.research.adminSaveStatus)).toContainText('Leave the field')

    await cap.blur()
    await expect(page.locator(selectors.research.adminSaveStatus)).toHaveText('Saved', {
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.notification.success)).toContainText(
      'Changes saved successfully'
    )
    expect(puts.at(-1)?.budgets).toMatchObject({ standard: { costCap: 2.5 } })
    await expect(page.locator(selectors.research.adminUnsaved)).toHaveCount(0)

    const runs = page.locator('[data-testid="research-admin-maxActiveRuns"]')
    await runs.fill('8')
    await expect(page.locator(selectors.research.adminUnsaved)).toHaveCount(0)
    await runs.blur()
    await expect(page.locator(selectors.research.adminSaveStatus)).toHaveText('Saved')
    expect(puts.at(-1)?.limits).toMatchObject({ maxActiveRuns: 8 })
  })
})
