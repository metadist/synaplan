import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

test.describe('Chat Share', () => {
  test('@001 @ci @smoke User can share chat and open shared link in incognito', async ({
    page,
    credentials,
  }) => {
    const uniqueMessage = `Chat share E2E ${Date.now()} – please reply briefly.`

    await test.step('Arrange: login via UI', async () => {
      await login(page, credentials)
    })

    const chat = new ChatHelper(page)
    await test.step('Arrange: start new chat', async () => {
      await chat.startNewChat()
    })

    const previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send message', async () => {
      await page.locator(selectors.chat.textInput).fill(uniqueMessage)
      await page.locator(selectors.chat.sendBtn).click()
    })

    await test.step('Wait for chat terminal state (done or error)', async () => {
      await chat.waitForAnswer(previousCount)
    })

    await test.step('Assert: user and assistant messages exist', async () => {
      await expect(page.locator(selectors.chat.messageUser)).toBeVisible()
      await expect(page.locator(selectors.chat.messageAssistant)).toBeVisible()
    })

    await test.step('Open share modal: chat manager → last chat (first row, newest first) → 3 dots → Share', async () => {
      const v2ChatNav = page.locator(selectors.nav.sidebarV2ChatNav)
      const v2Visible = await v2ChatNav.isVisible().catch(() => false)
      if (v2Visible) {
        await v2ChatNav.click()
        const modal = page.locator(selectors.nav.modalChatManager)
        await modal.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await modal
          .locator(selectors.nav.chatManagerListRows)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        // Last chat = first row (newest first); prefer data-testid per E2E rules
        const lastChatRow = modal.locator(selectors.nav.chatV2Row).first()
        await lastChatRow.scrollIntoViewIfNeeded()
        await lastChatRow.hover()
        const menuBtn = lastChatRow.locator(selectors.nav.chatV2RowMenu)
        await menuBtn.click({ force: true })
        await page.locator(selectors.nav.chatV2Share).click()
      } else {
        const dropdownSection = page.locator(selectors.share.chatDropdownSection)
        if (!(await dropdownSection.isVisible())) {
          const expandBtn = page.locator(selectors.nav.sidebarExpand)
          if (await expandBtn.isVisible().catch(() => false)) {
            await expandBtn.click()
          }
          await page.locator(selectors.chat.chatBtnToggle).click()
        }
        await dropdownSection.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        const items = page.locator(selectors.share.chatDropdownRow)
        await expect(items).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
        const topChatRow = items.first()
        await topChatRow.hover()
        await topChatRow.locator(selectors.share.chatMenuButton).click()
        await topChatRow.locator(selectors.share.chatShareButton).click()
      }
      await page
        .locator(selectors.share.shareModal)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Create share link and wait for share terminal state', async () => {
      const modal = page.locator(selectors.share.modalRoot)
      const shareCreateBtn = modal.locator(selectors.share.shareCreate)
      await shareCreateBtn.click()

      const result = await Promise.race([
        modal
          .locator(selectors.share.shareDone)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
          .then(() => 'done' as const),
        modal
          .locator(selectors.share.shareError)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
          .then(() => 'error' as const),
      ])
      if (result === 'error') {
        throw new Error('Share ended in error state (shareError visible)')
      }
    })

    let shareUrl = ''
    await test.step('Read share URL from share-link-input', async () => {
      const linkEl = page.locator(selectors.share.shareLinkInput)
      await linkEl.waitFor({ state: 'visible' })
      shareUrl = await linkEl.evaluate((el) =>
        el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement
          ? el.value
          : (el.textContent ?? '').trim()
      )
      expect(shareUrl.length).toBeGreaterThan(0)
      expect(shareUrl).toContain('/shared/')
    })

    await test.step('Open share URL in incognito and assert read-only view', async () => {
      const incognito = await page.context().browser()!.newContext()
      const sharedPage = await incognito.newPage()
      try {
        await sharedPage.goto(shareUrl, { waitUntil: 'domcontentloaded' })
        await sharedPage
          .locator(selectors.sharedChat.sharedChatRoot)
          .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await sharedPage
          .locator(selectors.sharedChat.sharedMessageList)
          .waitFor({ state: 'visible' })
        const messages = sharedPage.locator(selectors.sharedChat.messageItem)
        const count = await messages.count()
        expect(count).toBeGreaterThanOrEqual(1)
        await expect(sharedPage.locator(selectors.sharedChat.sharedMessageList)).toContainText(
          uniqueMessage,
          { timeout: TIMEOUTS.STANDARD }
        )
        await expect(sharedPage.locator(selectors.chat.textInput)).not.toBeVisible()
      } finally {
        await incognito.close()
      }
    })
  })
})
