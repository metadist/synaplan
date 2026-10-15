/**
 * Empty chat landing: the self-aware question for a new account.
 * The worker user is created at the start of the run, so it is inside
 * the first week. Stops after the user bubble appears — no provider wait.
 */
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const CHAT = selectors.chat

test.describe('@ci Chat empty landing', () => {
  test('hides the welcome cards and submits the self-aware question', async ({ page }) => {
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.startNewChat()

    await expect(page.locator(CHAT.companionLinks)).toHaveCount(0)
    await expect(page.locator(CHAT.stateEmpty)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    const ask = page.locator(CHAT.selfAwareEmptyHintBtn)
    await expect(ask).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await ask.click()

    await expect(page.locator(CHAT.stateEmpty)).toBeHidden({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(CHAT.userMessageBubble)).toContainText(
      'Give me a rough overview of the features',
      { timeout: TIMEOUTS.STANDARD }
    )
  })
})
