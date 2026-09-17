/**
 * Empty chat landing: companion cards plus the self-aware question.
 * Stops after the user bubble appears — no provider wait.
 */
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const CHAT = selectors.chat

test.describe('@ci Chat empty landing', () => {
  test('shows companion apps and submits the self-aware question', async ({ page }) => {
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.startNewChat()

    await expect(page.locator(CHAT.companionLinks)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(CHAT.companionDesktop)).toHaveAttribute(
      'href',
      'https://github.com/metadist/synaplan-desktop'
    )
    await expect(page.locator(CHAT.companionMobile)).toHaveAttribute(
      'href',
      'https://github.com/metadist/synaplan-apps'
    )
    await expect(page.locator(CHAT.companionOutlook)).toHaveAttribute(
      'href',
      'https://github.com/metadist/Synamail'
    )

    const ask = page.locator(CHAT.selfAwareEmptyHintBtn)
    await expect(ask).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await ask.click()

    await expect(page.locator(CHAT.stateEmpty)).toBeHidden({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(CHAT.userMessageBubble)).toContainText('What can you do?', {
      timeout: TIMEOUTS.STANDARD,
    })
  })
})
