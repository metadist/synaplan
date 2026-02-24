import type { Locator, Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { selectors } from './selectors'
import { TIMEOUTS } from '../config/config'

export class ChatHelper {
  constructor(private page: Page) {}

  conversationBubbles(): Locator {
    return this.page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble)
  }

  async waitForAnswer(previousCount: number, longTimeout = false): Promise<string> {
    const bubbles = this.conversationBubbles()
    const newBubble = bubbles.nth(previousCount)
    const raceTimeout = longTimeout ? TIMEOUTS.EXTREME : TIMEOUTS.LONG

    await newBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
    await newBubble.scrollIntoViewIfNeeded()
    await newBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })

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
    return (await answerBody.innerText()).trim().toLowerCase()
  }

  async ensureAdvancedMode(): Promise<void> {
    const modeToggle = this.page.locator(selectors.header.modeToggle)
    await modeToggle.waitFor({ state: 'visible' })
    const modeLabel = (await modeToggle.innerText()).toLowerCase()
    if (modeLabel.includes('easy')) {
      await modeToggle.click()
      await expect(modeToggle).toContainText(/advanced/i)
    }
  }

  /**
   * Start a new chat. Supports V2 (sidebar plus button) and V1 (chat toggle + dropdown).
   * Waits for the new chat input to be visible, empty, and enabled.
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
    await latestBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
    await latestBubble.scrollIntoViewIfNeeded()
    const toggle = latestBubble.locator(selectors.chat.againDropdown)
    await expect(toggle).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(toggle).toBeEnabled({ timeout: TIMEOUTS.STANDARD })

    const dropdown = this.page.locator('.dropdown-panel').last()
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
    await this.page
      .locator('.dropdown-panel')
      .last()
      .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
      .catch(() => {})

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
          await this.page
            .locator('.dropdown-panel')
            .last()
            .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
            .catch(() => {})
          continue
        }

        const option = options.nth(i)
        await option.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
        await expect(option).toBeEnabled({ timeout: TIMEOUTS.SHORT })

        labelText = (await option.innerText()).trim().toLowerCase()
        if (labelText.includes('ollama')) {
          await rowToggle.click()
          await this.page
            .locator('.dropdown-panel')
            .last()
            .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
            .catch(() => {})
          continue
        }

        const currentCount = await this.conversationBubbles().count()
        await option.click()
        await this.page
          .locator('.dropdown-panel')
          .last()
          .waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT })
          .catch(() => {})

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
          const dropdown = this.page.locator('.dropdown-panel').last()
          if (await dropdown.isVisible()) {
            const toggle = this.conversationBubbles().last().locator(selectors.chat.againDropdown)
            await toggle.click()
            await dropdown.waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})
          }
        } catch {
          // Dropdown closed or timeout – ignore
        }
      }
    }
  }
}
