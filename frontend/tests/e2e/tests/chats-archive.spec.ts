import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

test.describe('@ci All chats archive', () => {
  test('History Show all opens All chats', async ({ page }) => {
    await openApp(page)
    await page.locator(selectors.nav.sidebarV2ChatNav).click()
    await expect(page.locator(selectors.nav.modalChatManager)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await page.locator(selectors.nav.chatV2ShowAll).click()
    await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.pages.chats)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('/statistics#chats lands on All chats', async ({ page }) => {
    await openApp(page)
    await page.goto('/statistics#chats', { waitUntil: 'commit' })
    await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.pages.chats)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('Usage page keeps usage only', async ({ page }) => {
    await openApp(page)
    await page.goto('/statistics')
    await expect(page.locator(selectors.pages.statistics)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator('[data-testid="section-chat-browser"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="comp-chat-browser"]')).toHaveCount(0)
  })
})
