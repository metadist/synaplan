import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const FILES = selectors.files

/**
 * Library sidebar (§4.6): Browse, Incoming, Generated, Search, Vectors.
 * Workspace is a sibling link when COMPUTE.WORKSPACES_ENABLED is on (local
 * compose pins it on). This is a pure navigation smoke: each link renders its
 * page root without error. Content assertions live in dedicated specs.
 */
test.describe('@ci Files sections', () => {
  test('every library link renders its page', async ({ page }) => {
    await test.step('Arrange: open the Files page', async () => {
      await openApp(page)
      await page.locator(selectors.nav.sidebarV2Files).click()
      await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.linkBrowse)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Incoming link navigates to /files/incoming', async () => {
      await page.locator(FILES.linkIncoming).click()
      await expect(page).toHaveURL(/\/files\/incoming/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageIncoming)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Generated link navigates to /files/generated', async () => {
      await page.locator(FILES.linkGenerated).click()
      await expect(page).toHaveURL(/\/files\/generated/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageGenerated)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Search link navigates to /files/search', async () => {
      await page.locator(FILES.linkSearch).click()
      await expect(page).toHaveURL(/\/files\/search/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.rag.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Vectors link is admin-only (hidden for the worker user)', async () => {
      await expect(page.locator(FILES.linkVectors)).toHaveCount(0)
    })

    await test.step('Browse link navigates back to /files', async () => {
      await page.locator(FILES.linkBrowse).click()
      await expect(page).toHaveURL(/\/files$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })
})
