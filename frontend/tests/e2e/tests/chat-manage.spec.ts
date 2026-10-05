import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper, openChatManager } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

/**
 * Chat manager row actions: rename (dialog prompt) and delete (danger
 * confirm). Complements chat-share.spec.ts, which covers the third row
 * action (Share) through the same menu.
 */

test.describe('@ci Chat Management', () => {
  test('user can rename a chat and delete it via the chat manager', async ({ page }) => {
    const renamedTitle = `Renamed chat ${Date.now()}`
    const chat = new ChatHelper(page)

    await test.step('Arrange: open app and start a fresh chat', async () => {
      await openApp(page)
      // The fresh chat is the newest entry → guaranteed first row in the
      // manager (sorted by activity, newest first).
      await chat.startNewChat()
    })

    await test.step('Act: rename the newest chat via the row menu', async () => {
      await openChatManager(page)
      const list = page.locator(selectors.nav.sidebarChats)
      const newestRow = list.locator(selectors.nav.chatV2Row).first()
      await newestRow.hover()
      await newestRow.locator(selectors.nav.chatV2RowMenu).click({ force: true })
      await page.locator(selectors.nav.chatV2Rename).click()

      const promptInput = page.locator(selectors.dialog.promptInput)
      await promptInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await promptInput.fill(renamedTitle)
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: the row shows the new title', async () => {
      const list = page.locator(selectors.nav.sidebarChats)
      await expect(
        list.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the new title survives a reload', async () => {
      await openApp(page)
      await openChatManager(page)
      const list = page.locator(selectors.nav.sidebarChats)
      await expect(
        list.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: delete the renamed chat with confirmation', async () => {
      const list = page.locator(selectors.nav.sidebarChats)
      const row = list.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      await row.hover()
      await row.locator(selectors.nav.chatV2RowMenu).click({ force: true })
      await page.locator(selectors.nav.chatV2Delete).click()

      const confirmBtn = page.locator(selectors.dialog.confirmBtn)
      await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await confirmBtn.click()
    })

    await test.step('Assert: the chat is gone and the surface stays usable', async () => {
      const list = page.locator(selectors.nav.sidebarChats)
      await expect(
        list.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })

      // Deleting the active chat must fall back to another chat; the composer stays usable.
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
    })
  })
})
