import type { Page } from '@playwright/test'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

/**
 * Chat manager row actions: rename (dialog prompt) and delete (danger
 * confirm). Complements chat-share.spec.ts, which covers the third row
 * action (Share) through the same menu.
 */

async function openChatManager(page: Page): Promise<void> {
  await page.locator(selectors.nav.sidebarV2ChatNav).click()
  const modal = page.locator(selectors.nav.modalChatManager)
  await modal.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  await modal
    .locator(selectors.nav.chatManagerListRows)
    .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
}

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
      const modal = page.locator(selectors.nav.modalChatManager)
      const newestRow = modal.locator(selectors.nav.chatV2Row).first()
      await newestRow.hover()
      await newestRow.locator(selectors.nav.chatV2RowMenu).click({ force: true })
      await page.locator(selectors.nav.chatV2Rename).click()

      const promptInput = page.locator(selectors.dialog.promptInput)
      await promptInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await promptInput.fill(renamedTitle)
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: the row shows the new title', async () => {
      const modal = page.locator(selectors.nav.modalChatManager)
      await expect(
        modal.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the new title survives a reload', async () => {
      await openApp(page)
      await openChatManager(page)
      const modal = page.locator(selectors.nav.modalChatManager)
      await expect(
        modal.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: delete the renamed chat with confirmation', async () => {
      const modal = page.locator(selectors.nav.modalChatManager)
      const row = modal.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      await row.hover()
      await row.locator(selectors.nav.chatV2RowMenu).click({ force: true })
      await page.locator(selectors.nav.chatV2Delete).click()

      const confirmBtn = page.locator(selectors.dialog.confirmBtn)
      await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await confirmBtn.click()
    })

    await test.step('Assert: the chat is gone and the surface stays usable', async () => {
      const modal = page.locator(selectors.nav.modalChatManager)
      await expect(
        modal.locator(selectors.nav.chatV2Row).filter({ hasText: renamedTitle })
      ).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })

      // Close the modal via the backdrop and verify the chat surface still works
      // (deleting the active chat must fall back to another/new chat).
      await page.locator(selectors.nav.modalChatManagerBackdrop).click({ position: { x: 8, y: 8 } })
      await modal.waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(selectors.chat.textInput)).toBeEnabled()
    })
  })
})
