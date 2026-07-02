import type { Locator, Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { selectors } from './selectors'
import { TIMEOUTS } from '../config/config'

export class ChatHelper {
  constructor(private page: Page) {}

  conversationBubbles(): Locator {
    return this.page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble)
  }

  /**
   * Fill the chat input and click send. Returns the AI-bubble count before
   * sending so callers can pass it straight to {@link waitForAnswer}.
   *
   * Waits for the send button to become enabled after filling — this guards
   * against the rare race where Playwright's synthetic fill() event reaches
   * Vue's @input handler a tick too late for canSend to flip before click().
   */
  async sendMessage(text: string): Promise<number> {
    const previousCount = await this.conversationBubbles().count()
    const textInput = this.page.locator(selectors.chat.textInput)
    const sendBtn = this.page.locator(selectors.chat.sendBtn)

    await textInput.fill(text)
    await expect(sendBtn).toBeEnabled({ timeout: TIMEOUTS.STANDARD })
    await sendBtn.click()

    return previousCount
  }

  async waitForAnswer(previousCount: number, longTimeout = false): Promise<string> {
    const bubbles = this.conversationBubbles()
    const newBubble = bubbles.nth(previousCount)
    const raceTimeout = longTimeout ? TIMEOUTS.EXTREME : TIMEOUTS.LONG

    // Wait directly for 'visible' (auto-retries on detach) instead of the racy
    // attached → scrollIntoViewIfNeeded → visible sequence: after the perf push,
    // historyStore.loadMessages() can replace messages.value while a freshly
    // optimistic assistant bubble is mid-mount, briefly detaching the DOM node
    // we just resolved. scrollIntoViewIfNeeded snapshots the handle and throws
    // 'Element is not attached to the DOM' if the node is swapped mid-call;
    // waitFor({ state: 'visible' }) re-resolves the locator and is stable.
    // Subsequent inner-locator waits and Playwright's own auto-scroll handle
    // any viewport positioning needed for downstream interactions.
    await newBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    const result = await Promise.race([
      newBubble
        .locator(selectors.chat.messageDone)
        .waitFor({ state: 'visible', timeout: raceTimeout })
        .then(() => 'done' as const),
      newBubble
        .locator(selectors.chat.messageTopicError)
        .waitFor({ state: 'visible', timeout: raceTimeout })
        .then(() => 'error' as const),
    ])
    if (result === 'error') {
      throw new Error('Assistant message ended in error state (messageTopicError visible)')
    }

    const answerBody = newBubble.locator(selectors.chat.assistantAnswerBody).last()

    // Wait for the bubble's textContent to stabilise before reading it.
    //
    // After the perf overhaul the streaming text path renders cheaply (escape +
    // <br>) for speed during the stream, then re-renders through the full
    // marked + DOMPurify + highlight.js pipeline once `isStreaming` flips
    // false. That second render is on a microtask boundary inside MessageText,
    // so `data-testid="message-done"` can become visible a tick or two before
    // the bubble's final HTML lands. Reading innerText immediately after the
    // selector check would race that re-render and return a half-rendered
    // snapshot (e.g. "ollama" instead of "ollama stub response").
    //
    // Polling for stability — text unchanged for STABILITY_WINDOW_MS — fixes
    // it without coupling the test to internal render scheduling.
    const STABILITY_WINDOW_MS = 200
    const POLL_INTERVAL_MS = 50
    const MAX_WAIT_MS = 5000
    const start = Date.now()
    let lastText = ''
    let stableSince = 0

    while (Date.now() - start < MAX_WAIT_MS) {
      const current = (await answerBody.innerText()).trim()
      if (current.length > 0 && current === lastText) {
        if (stableSince === 0) {
          stableSince = Date.now()
        } else if (Date.now() - stableSince >= STABILITY_WINDOW_MS) {
          return current.toLowerCase()
        }
      } else {
        lastText = current
        stableSince = 0
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS))
    }

    return lastText.toLowerCase()
  }

  /**
   * App-mode selection moved from the header into Settings. We persist it
   * directly via localStorage and reload — fastest, race-free, and matches
   * exactly what the appMode store reads on init.
   */
  async ensureAdvancedMode(): Promise<void> {
    await this.page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
    await this.page.reload()
    await this.page
      .locator(selectors.nav.sidebarV2Channels)
      .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }

  /**
   * Start a new chat. Supports V2 (sidebar plus button) and V1 (chat toggle + dropdown).
   *
   * Waits for the new chat to be fully committed by asserting the empty-state
   * marker (`state-empty`) is visible. This is required to avoid a race with
   * `historyStore.loadMessages()` which is triggered asynchronously by the
   * `activeChatId` watcher in ChatView: if we sample `conversationBubbles().count()`
   * before that async call replaces `messages.value = []`, the count picks up
   * DOM residue from the previously active chat, and any later bubbles added
   * optimistically by `sendMessage` get wiped by the delayed replacement —
   * leaving `waitForAnswer(previousCount)` stuck on a non-existent `nth(N)` bubble.
   */
  async startNewChat(): Promise<void> {
    const v2NewChatBtn = this.page.locator(selectors.nav.sidebarV2NewChat)
    try {
      await v2NewChatBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await v2NewChatBtn.click()
    } catch {
      await this.page.locator(selectors.chat.chatBtnToggle).waitFor({ state: 'visible' })
      await this.page.locator(selectors.chat.chatBtnToggle).click()
      await this.page.locator(selectors.chat.newChatButton).waitFor({ state: 'visible' })
      await this.page.locator(selectors.chat.newChatButton).click()
    }

    const textInput = this.page.locator(selectors.chat.textInput)
    await textInput.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    await expect(textInput).toHaveValue('', { timeout: TIMEOUTS.SHORT })
    await expect(textInput).toBeEnabled()

    await this.page
      .locator(selectors.chat.stateEmpty)
      .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    // The empty state can render a tick before chatsStore.activeChatId is
    // committed. sendMessage() clicks Send the moment the input is enabled, and
    // if activeChatId is still null the send bails out with "No active chat
    // selected" — no SSE, no bubble, and waitForAnswer() times out. Wait for the
    // persisted active-chat id so the chat is guaranteed ready before we send.
    await this.page.waitForFunction(
      () => !!window.localStorage.getItem('synaplan_active_chat_id'),
      undefined,
      { timeout: TIMEOUTS.STANDARD }
    )
  }

  async attachFile(file: { name: string; mimeType: string; buffer: Buffer }): Promise<void> {
    await this.page.locator(selectors.chat.attachBtn).click()

    const modal = this.page.locator(selectors.fileSelection.modal)
    await modal.waitFor({ state: 'visible', timeout: 10_000 })

    const [fileChooser] = await Promise.all([
      this.page.waitForEvent('filechooser'),
      modal.locator(selectors.fileSelection.uploadButton).click(),
    ])
    await fileChooser.setFiles(file)

    await modal.getByText(file.name).first().waitFor({ state: 'visible', timeout: 30_000 })

    const attachButton = modal.locator(selectors.fileSelection.attachButton)
    await expect(attachButton).toBeEnabled({ timeout: 30_000 })
    await attachButton.click()
    await modal.waitFor({ state: 'hidden', timeout: 10_000 })
  }

  async openLatestAgainDropdown(): Promise<{
    toggle: Locator
    options: Locator
    optionCount: number
  }> {
    const latestBubble = this.conversationBubbles().last()
    // 'visible' instead of 'attached' + scrollIntoViewIfNeeded() avoids the
    // detach-during-reconciliation race; Playwright auto-scrolls before clicks.
    await latestBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    const toggle = latestBubble.locator(selectors.chat.againDropdown)
    await expect(toggle).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(toggle).toBeEnabled({ timeout: TIMEOUTS.STANDARD })

    const dropdown = latestBubble.locator(selectors.chat.againDropdownPanel)
    const isAlreadyOpen = await dropdown.isVisible().catch(() => false)
    if (!isAlreadyOpen) {
      await toggle.click()
      await dropdown.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    }

    const options = dropdown.locator(selectors.chat.againDropdownItem)
    await options.first().waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    const optionCount = await options.count()

    return { toggle, options, optionCount }
  }

  async runAgainOptions(
    expectedToken?: string,
    failures?: string[],
    purpose?: string,
    longTimeout = false
  ): Promise<void> {
    const initialDropdown = await this.openLatestAgainDropdown()
    const optionCount = initialDropdown.optionCount
    await initialDropdown.toggle.click()
    await this.waitForDropdownHidden()

    if (optionCount === 0) {
      failures?.push(`No models available for ${purpose || 'unknown purpose'}`)
      return
    }

    const startIndex = optionCount > 1 ? 1 : 0

    for (let i = startIndex; i < optionCount; i += 1) {
      let labelText = ''
      try {
        const {
          toggle: rowToggle,
          options,
          optionCount: currentOptionCount,
        } = await this.openLatestAgainDropdown()

        if (i >= currentOptionCount) {
          failures?.push(
            `${purpose || 'purpose'} model ${i} (index out of range, found ${currentOptionCount})`
          )
          await rowToggle.click()
          await this.waitForDropdownHidden()
          continue
        }

        const option = options.nth(i)
        await option.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await expect(option).toBeEnabled({ timeout: TIMEOUTS.SHORT })

        labelText = (await option.innerText()).trim().toLowerCase()
        if (labelText.includes('ollama')) {
          await rowToggle.click()
          await this.waitForDropdownHidden()
          continue
        }

        const currentCount = await this.conversationBubbles().count()
        await option.click()
        await this.waitForDropdownHidden()

        const aiText = await this.waitForAnswer(currentCount, longTimeout)

        if (expectedToken) {
          await expect
            .soft(
              aiText,
              `Model ${i} (${labelText || 'unknown'}) should respond for ${purpose || 'purpose'}`
            )
            .toContain(expectedToken)
        } else {
          await expect
            .soft(
              aiText.includes('error') || aiText.includes('failed'),
              `Model ${i} (${labelText || 'unknown'}) should not return error text`
            )
            .toBeFalsy()
        }
      } catch (error) {
        failures?.push(
          `${purpose || 'purpose'} model ${i} (${labelText || 'unknown'}): ${
            error instanceof Error ? error.message : String(error)
          }`
        )
        try {
          const latestBubble = this.conversationBubbles().last()
          const dropdown = latestBubble.locator(selectors.chat.againDropdownPanel)
          if (await dropdown.isVisible()) {
            const toggle = latestBubble.locator(selectors.chat.againDropdown)
            await toggle.click()
            await this.waitForDropdownHidden()
          }
        } catch {
          // Dropdown closed or timeout – ignore
        }
      }
    }
  }

  private async waitForDropdownHidden(): Promise<void> {
    await this.conversationBubbles()
      .last()
      .locator(selectors.chat.againDropdownPanel)
      .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
      .catch(() => {
        // Dropdown may already be hidden or auto-closed — not critical
      })
  }
}
