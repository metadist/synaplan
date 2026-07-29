import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

/**
 * Incognito sessions are fully ephemeral: the backend processes turns
 * in-memory (no chat/message rows) and the transcript lives only in the
 * in-memory history store. These tests pin down the two exit paths
 * (toggle with confirmation, New Chat) and the no-persistence guarantee.
 *
 * The toggle renders twice (mobile + desktop instance); tests scope it via
 * the desktop wrapper since @ci functional specs run on desktop viewports.
 */

test.describe('@ci Incognito Chat', () => {
  test('incognito turn is not persisted and ending the session discards it', async ({ page }) => {
    // Kept short so the full text fits into both the chat title (50 chars)
    // and the sidebar preview (30 chars) if it ever leaked into a row.
    const uniqueMessage = `Incognito E2E ${Date.now()}`
    const chat = new ChatHelper(page)

    await test.step('Arrange: open app on a fresh chat', async () => {
      await openApp(page)
      await chat.startNewChat()
    })

    await test.step('Act: start an incognito session', async () => {
      await page
        .locator(selectors.incognito.desktopSection)
        .locator(selectors.incognito.toggle)
        .click()
      await page
        .locator(selectors.incognito.banner)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    })

    let previousCount: number
    await test.step('Act: send a message inside the session', async () => {
      previousCount = await chat.sendMessage(uniqueMessage)
    })

    await test.step('Assert: assistant answers without error', async () => {
      const answer = await chat.waitForAnswer(previousCount!)
      expect(answer.length).toBeGreaterThan(0)
    })

    await test.step('Assert: the chat manager lists no chat for the incognito turn', async () => {
      await page.locator(selectors.nav.sidebarV2ChatNav).click()
      const modal = page.locator(selectors.nav.modalChatManager)
      await modal.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(
        modal.locator(selectors.nav.chatV2Row).filter({ hasText: uniqueMessage })
      ).toHaveCount(0)
      await page.locator(selectors.nav.modalChatManagerBackdrop).click({ position: { x: 8, y: 8 } })
      await modal.waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: end the session via toggle and confirm the discard warning', async () => {
      await page
        .locator(selectors.incognito.desktopSection)
        .locator(selectors.incognito.toggle)
        .click()
      const confirmBtn = page.locator(selectors.dialog.confirmBtn)
      await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await confirmBtn.click()
      await page
        .locator(selectors.incognito.banner)
        .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
    })

    await test.step('Assert: the transcript is discarded from the restored surface', async () => {
      await expect(page.getByText(uniqueMessage)).toHaveCount(0)
    })

    await test.step('Assert: nothing survived a reload', async () => {
      await openApp(page)
      await expect(page.getByText(uniqueMessage)).toHaveCount(0)
      await page.locator(selectors.nav.sidebarV2ChatNav).click()
      const modal = page.locator(selectors.nav.modalChatManager)
      await modal.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(
        modal.locator(selectors.nav.chatV2Row).filter({ hasText: uniqueMessage })
      ).toHaveCount(0)
    })
  })

  test('starting a new chat leaves the incognito session', async ({ page }) => {
    const chat = new ChatHelper(page)

    await test.step('Arrange: open app and start an incognito session', async () => {
      await openApp(page)
      await page
        .locator(selectors.incognito.desktopSection)
        .locator(selectors.incognito.toggle)
        .click()
      await page
        .locator(selectors.incognito.banner)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    })

    await test.step('Act: click New Chat in the sidebar rail', async () => {
      await chat.startNewChat()
    })

    await test.step('Assert: the session ended and a normal empty chat is active', async () => {
      await page
        .locator(selectors.incognito.banner)
        .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
      await expect(page.locator(selectors.chat.stateEmpty)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
