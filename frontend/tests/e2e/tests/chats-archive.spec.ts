import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { nameActiveChat } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

test.describe('@ci All chats archive', () => {
  test('History Show all opens All chats', async ({ page }) => {
    await openApp(page)
    await page.locator(selectors.nav.sidebarV2ChatNav).click()
    await expect(page.locator(selectors.nav.sidebarChats)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.locator('[data-testid="btn-sidebar-v2-chats-toggle"]').hover()
    await page.locator(selectors.nav.chatV2ShowAll).click()
    await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.pages.chats)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('/statistics#chats lands on All chats', async ({ page }) => {
    await openApp(page)
    await page.goto('/statistics#chats', { waitUntil: 'commit' })
    await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.pages.chats)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('Usage page keeps usage only', async ({ page }) => {
    await openApp(page)
    await page.goto('/statistics')
    await expect(page.locator(selectors.pages.statistics)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator('[data-testid="section-chat-browser"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="comp-chat-browser"]')).toHaveCount(0)
  })

  test('Archived lists only archived chats and leaves the open chat on screen', async ({
    page,
  }) => {
    await openApp(page)
    await page.locator(selectors.nav.sidebarV2ChatNav).click()
    const title = `Archive walk ${Date.now()}`
    await nameActiveChat(page, title)
    const row = () => page.locator('[data-testid="row-chat-v2"]').filter({ hasText: title })
    await expect(row()).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    const activeBefore = await page.evaluate(() =>
      window.localStorage.getItem('synaplan_active_chat_id')
    )

    const archivedLoad = page.waitForResponse(
      (res) => {
        const url = new URL(res.url())
        return (
          url.pathname.endsWith('/api/v1/chats') &&
          url.searchParams.get('archived') === '1' &&
          res.request().method() === 'GET' &&
          res.ok()
        )
      },
      { timeout: TIMEOUTS.VERY_LONG }
    )
    await page.locator('[data-testid="btn-sidebar-archived-chats"]').click()
    const archivedResponse = await archivedLoad
    const archivedBody = (await archivedResponse.json()) as { chats?: unknown[] }

    await expect(row()).toHaveCount(0)
    await expect(page.locator(selectors.chat.textInput)).toBeVisible()
    await expect
      .poll(() => page.evaluate(() => window.localStorage.getItem('synaplan_active_chat_id')))
      .toBe(activeBefore)
    if ((archivedBody.chats ?? []).length === 0) {
      await expect(page.locator('[data-testid="text-sidebar-v2-archived-empty"]')).toBeVisible()
    }

    await page.locator('[data-testid="btn-sidebar-archived-chats"]').click()
    await expect(row()).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })

    await row().locator('[data-testid="btn-chat-v2-row-menu"]').click()
    const archived = page.waitForResponse(
      (res) => res.url().includes('/archive') && res.request().method() === 'POST' && res.ok(),
      { timeout: TIMEOUTS.VERY_LONG }
    )
    await page.locator('[data-testid="btn-chat-v2-archive"]').click()
    await archived
    await expect(row()).toHaveCount(0)
    await expect(page.locator(selectors.chat.textInput)).toBeVisible()

    await page.locator('[data-testid="btn-sidebar-archived-chats"]').click()
    await expect(row()).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    await row().locator('button').first().click()
    await expect(page.locator(selectors.chat.textInput)).toBeVisible()

    await page.locator('[data-testid="btn-sidebar-v2-new-chat"]').click()
    await expect(page.locator('[data-testid="btn-sidebar-archived-chats"]')).toHaveText('Archived')
    await expect(page.locator(selectors.chat.textInput)).toBeVisible()

    await page.locator('[data-testid="btn-sidebar-archived-chats"]').click()
    await expect(row()).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    await row().locator('[data-testid="btn-chat-v2-row-menu"]').click()
    const restored = page.waitForResponse(
      (res) => res.url().includes('/unarchive') && res.request().method() === 'POST' && res.ok(),
      { timeout: TIMEOUTS.VERY_LONG }
    )
    await page.locator('[data-testid="btn-chat-v2-archive"]').click()
    await restored
    await expect(row()).toHaveCount(0)

    await page.locator('[data-testid="btn-sidebar-archived-chats"]').click()
    await expect(row()).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
  })
})
