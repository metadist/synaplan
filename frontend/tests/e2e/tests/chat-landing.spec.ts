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
    await expect(page.locator(CHAT.companionChoice)).toBeVisible()
    await expect(page.locator(CHAT.companionAppStore)).toHaveAttribute(
      'href',
      'https://apps.apple.com/app/id6784278288?ct=app-welcome'
    )
    await expect(page.locator(CHAT.companionPlayStore)).toHaveAttribute(
      'href',
      'https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dapp-welcome'
    )
    await expect(page.locator(CHAT.companionSource)).toHaveAttribute(
      'href',
      'https://github.com/metadist/synaplan'
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
