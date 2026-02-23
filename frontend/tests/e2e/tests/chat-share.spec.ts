import { test, expect, type Locator } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

test.describe('Chat Share', () => {
  test('User can share a chat and open shared link in incognito id=share-001', async ({
    page,
    credentials,
  }) => {
    const uniqueMessage = `Chat share E2E ${Date.now()} – please reply briefly.`

    await test.step('Arrange: login via UI', async () => {
      await login(page, credentials)
    })

    let previousBubbleCount = 0
    let newBubble: Locator
    await test.step('Arrange: start new chat and send unique message', async () => {
      const chat = new ChatHelper(page)
      await chat.startNewChat()
      const bubbles = page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble)
      previousBubbleCount = await bubbles.count()
      await page.locator(selectors.chat.textInput).fill(uniqueMessage)
      await page.locator(selectors.chat.sendBtn).click()
    })
    newBubble = page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble).nth(previousBubbleCount)

    await test.step('Wait for chat terminal state (done or error)', async () => {
      await newBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
      await newBubble.scrollIntoViewIfNeeded()

      const result = await Promise.race([
        newBubble.locator(selectors.chat.chatDone).waitFor({ state: 'visible', timeout: TIMEOUTS.LONG }).then(() => 'done' as const),
        newBubble.locator(selectors.chat.chatError).waitFor({ state: 'visible', timeout: TIMEOUTS.LONG }).then(() => 'error' as const),
      ])
      if (result === 'error') {
        throw new Error('Chat ended in error state (chatError visible)')
      }
    })

    await test.step('Assert: user and assistant messages exist, assistant non-empty', async () => {
      await expect(page.locator(selectors.chat.messageUser)).toBeVisible()
      await expect(page.locator(selectors.chat.messageAssistant)).toBeVisible()
      const assistantBody = newBubble.locator(selectors.chat.assistantAnswerBody)
      await expect(assistantBody).not.toHaveText('', { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Open share modal via chat dropdown', async () => {
      const dropdownSection = page.locator(selectors.share.chatDropdownSection)
      if (!(await dropdownSection.isVisible())) {
        await page.locator(selectors.chat.chatBtnToggle).click()
      }
      await dropdownSection.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      const items = page.locator(selectors.share.chatDropdownRow)
      await expect(items).toHaveCount(1, { timeout: TIMEOUTS.STANDARD })
      const onlyItem = items.first()
      await onlyItem.hover()
      await onlyItem.locator(selectors.share.chatMenuButton).click()
      await onlyItem.locator(selectors.share.chatShareButton).click()
      await page.locator(selectors.share.shareModal).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Create share link and wait for share terminal state', async () => {
      const modal = page.locator(selectors.share.modalRoot)
      const shareCreateBtn = modal.locator(selectors.share.shareCreate)
      await shareCreateBtn.click()

      const result = await Promise.race([
        modal.locator(selectors.share.shareDone).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD }).then(() => 'done' as const),
        modal.locator(selectors.share.shareError).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD }).then(() => 'error' as const),
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
        await sharedPage.locator(selectors.sharedChat.sharedChatRoot).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await sharedPage.locator(selectors.sharedChat.sharedMessageList).waitFor({ state: 'visible' })
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
