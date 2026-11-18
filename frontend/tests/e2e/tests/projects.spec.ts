import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp, getAuthHeaders } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * J-PJ-1: create a project, fill it with a note, open a chat in it, take it
 * apart again. Skipped where the module is off (the dev stack seeds it off).
 */
/** Projects ship off; the test environment turns them on (backend/.env.test). */
async function projectsEnabled(
  request: import('@playwright/test').APIRequestContext
): Promise<boolean> {
  const headers = await getAuthHeaders(request, CREDENTIALS.getAdminCredentials())
  const res = await request.get(`${getApiUrl()}/api/v1/projects`, { headers })
  return res.ok()
}

test.describe('@ci Projects', () => {
  test.beforeEach(async ({ request }) => {
    test.skip(!(await projectsEnabled(request)), 'PROJECTS_ENABLED is off on this stack')
  })

  test('a project takes a note as a source, hosts a chat and can be deleted', async ({ page }) => {
    const name = `Kitchen ${Date.now()}`
    const noteTitle = 'Zorblax warranty'

    await test.step('Arrange: open the app and create a project from the panel', async () => {
      await openApp(page)
      await page.locator('[data-testid="btn-sidebar-project-new"]').click()
      const input = page.locator(selectors.dialog.promptInput)
      await input.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await input.fill(name)
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(page.locator('[data-testid="text-project-name"]')).toHaveText(name, {
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: add a note on the Sources tab', async () => {
      await page.locator('[data-testid="tab-project-sources"]:visible').first().click()
      await page.locator('[data-testid="btn-project-add-source"]').click()
      await page.locator('[data-testid="tab-project-add-source-text"]').click()
      await page.locator('[data-testid="input-project-source-text-title"]').fill(noteTitle)
      await page
        .locator('[data-testid="input-project-source-text"]')
        .fill('The Zorblax warranty lasts 17 months.')
      await page.locator('[data-testid="btn-project-add-source-submit"]').click()
    })

    await test.step('Assert: the note settles on "Ready" and survives a reload', async () => {
      const row = page
        .locator('[data-testid^="item-project-source-"]')
        .filter({ hasText: noteTitle })
      await expect(row).toHaveAttribute('data-state', 'ready', { timeout: TIMEOUTS.LONG })
      await page.reload()
      await page.locator('[data-testid="tab-project-sources"]:visible').first().click()
      await expect(
        page.locator('[data-testid^="item-project-source-"]').filter({ hasText: noteTitle })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: start a chat in the project', async () => {
      await page.locator('[data-testid="tab-project-chats"]:visible').first().click()
      await page.locator('[data-testid="btn-project-new-chat"]').click()
    })

    await test.step('Assert: the composer names the project', async () => {
      await expect(page.locator('[data-testid="badge-chat-project"]')).toContainText(name, {
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: delete the project and keep its chat', async () => {
      await page.locator('[data-testid="badge-chat-project"]').click()
      await page.locator('[data-testid="btn-project-delete"]').click()
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: the project is gone from the panel', async () => {
      await expect(
        page.locator('[data-testid="list-sidebar-projects"]').getByText(name)
      ).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })
    })
  })
})
