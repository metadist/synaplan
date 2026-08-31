import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const FILES = selectors.files

/**
 * Files "world" tab bar (§4.6): the knowledge base is one page with five
 * surfaces — Browse, Incoming, Generated, Search, Vectors. Only Browse↔Search
 * was covered (rag-search.spec.ts); the Incoming/Generated/Vectors views shipped
 * with the File Management World and had no E2E. This is a pure navigation smoke:
 * each tab renders its page root without error. Content assertions (uploads,
 * media generation, vector counts) live in dedicated specs / unit tests.
 */
test.describe('@ci Files tabs', () => {
  test('every knowledge-base tab renders its page', async ({ page }) => {
    await test.step('Arrange: open the Files page', async () => {
      await openApp(page)
      await page.locator(selectors.nav.sidebarV2Files).click()
      await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.tabsBar)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Incoming tab navigates to /files/incoming', async () => {
      await page.locator(FILES.tabIncoming).click()
      await expect(page).toHaveURL(/\/files\/incoming/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageIncoming)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Generated tab navigates to /files/generated', async () => {
      await page.locator(FILES.tabGenerated).click()
      await expect(page).toHaveURL(/\/files\/generated/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.pageGenerated)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Search tab navigates to /files/search', async () => {
      await page.locator(FILES.tabSearch).click()
      await expect(page).toHaveURL(/\/files\/search/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.rag.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Vectors tab is admin-only (hidden for the worker user)', async () => {
      await expect(page.locator(FILES.tabVectors)).toHaveCount(0)
    })

    await test.step('Browse tab navigates back to /files', async () => {
      await page.locator(FILES.tabBrowse).click()
      await expect(page).toHaveURL(/\/files$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(FILES.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })
})
