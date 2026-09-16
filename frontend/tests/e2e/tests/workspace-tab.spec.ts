import { test, expect, type Page } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const FILES = selectors.files

/**
 * J-CP-2 on the Files side with COMPUTE.WORKSPACES_ENABLED on. The CI stack has
 * no compute sidecar, so the runtime flag and the four workspace endpoints are
 * served by route mocks; everything the user sees — tab, list, preview dialog,
 * delete confirmation, empty and error states — is the real app. The flag-off
 * half (no tab, direct route redirects) runs against the real backend.
 */

const REPORT = { path: 'reports/january.csv', size: 12, mime: 'text/csv', modifiedAt: '' }

async function enableWorkspaceFlag(page: Page): Promise<void> {
  await page.route('**/api/v1/config/runtime', async (route) => {
    const response = await route.fetch()
    const body = (await response.json()) as { features?: Record<string, unknown> }
    body.features = { ...body.features, computeEnabled: true, computeWorkspacesEnabled: true }
    await route.fulfill({ response, json: body })
  })
}

async function mockWorkspaceApi(page: Page, state: { exists: boolean; files: (typeof REPORT)[] }) {
  await page.route('**/api/v1/compute/workspace', async (route) => {
    if (route.request().method() === 'DELETE') {
      state.exists = false
      state.files = []
      return route.fulfill({ status: 204, body: '' })
    }
    return route.fulfill({
      json: {
        exists: state.exists,
        quotaMb: 256,
        usedMb: state.exists ? 1 : 0,
        fileCount: state.files.length,
      },
    })
  })
  await page.route('**/api/v1/compute/workspace/files', (route) =>
    route.fulfill({ json: { files: state.files } })
  )
  await page.route('**/api/v1/compute/workspace/files/**', (route) =>
    route.fulfill({ status: 200, contentType: 'text/csv', body: 'month,total\njan,42' })
  )
}

test.describe('@ci Files workspace tab', () => {
  test('flag on: list, preview, close with Escape, delete to the empty state', async ({ page }) => {
    const state = { exists: true, files: [REPORT] }
    await enableWorkspaceFlag(page)
    await mockWorkspaceApi(page, state)

    await test.step('Arrange: Files shows the Workspace tab', async () => {
      await openApp(page)
      await page.locator(selectors.nav.sidebarV2Files).click()
      await expect(page.locator(FILES.tabsBar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.tabWorkspace)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('The tab lists the nested file with its relative path', async () => {
      await page.locator(FILES.tabWorkspace).click()
      await expect(page).toHaveURL(/\/files\/workspace/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageWorkspace)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.workspaceFiles)).toContainText('reports/january.csv')
    })

    await test.step('Preview opens as a dialog and Escape closes it', async () => {
      await page.locator(FILES.btnWorkspacePreview).first().click()
      const dialog = page.locator(FILES.workspacePreview).getByRole('dialog')
      await expect(dialog).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(dialog).toContainText('month,total')
      await expect(page.locator(FILES.btnWorkspacePreviewClose)).toBeFocused()
      await page.keyboard.press('Escape')
      await expect(page.locator(FILES.workspacePreview)).toHaveCount(0)
    })

    await test.step('Delete asks first, then the empty state names the next action', async () => {
      await page.locator(FILES.btnWorkspaceDelete).click()
      await expect(page.locator(selectors.dialog.confirmBtn)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(page.locator(FILES.workspaceEmpty)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.btnWorkspaceEmptyChat)).toBeVisible()
      await expect(page.locator(FILES.btnWorkspaceDelete)).toHaveCount(0)
    })
  })

  test('flag on: a failed load shows the reason and Try again, never the empty state', async ({
    page,
  }) => {
    await enableWorkspaceFlag(page)
    let calls = 0
    await page.route('**/api/v1/compute/workspace', (route) => {
      calls += 1
      return calls === 1
        ? route.fulfill({ status: 503, json: { error: 'compute_unavailable' } })
        : route.fulfill({ json: { exists: false, quotaMb: 256, usedMb: 0, fileCount: 0 } })
    })

    await openApp(page)
    await page.goto('/files/workspace')
    await expect(page.locator(FILES.workspaceError)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(FILES.workspaceEmpty)).toHaveCount(0)

    await page.locator(FILES.btnWorkspaceRetry).click()
    await expect(page.locator(FILES.workspaceEmpty)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(FILES.workspaceError)).toHaveCount(0)
  })

  test('flag off: the direct route redirects to Files and no tab is offered', async ({ page }) => {
    await openApp(page)
    await page.goto('/files/workspace')
    await expect(page).toHaveURL(/\/files$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(FILES.tabWorkspace)).toHaveCount(0)
  })
})
