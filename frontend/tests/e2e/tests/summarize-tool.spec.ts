/**
 * NV07 — Summarize a document runs in chat.
 * Provider-free: stops at "tool armed", it does not send.
 */
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const CHAT = selectors.chat
const NAV = selectors.nav

test.describe('Summarize a document in chat', () => {
  test('@ci Tools → Summarize a document arms the composer', async ({ page }) => {
    await openApp(page)
    await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await page.locator(CHAT.plusToggle).click()
    await expect(page.locator(CHAT.plusPanel)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await page.locator(CHAT.toolsToggle).click()
    await expect(page.locator(CHAT.toolsPanel)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await page.locator(CHAT.toolSummarize).click()

    await expect(page.locator(CHAT.summarizeOptions)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page).toHaveURL(/\/(?:\?.*)?$/, { timeout: TIMEOUTS.SHORT })
  })

  test('@ci /ai/summarizer redirects and arms the tool', async ({ page }) => {
    await openApp(page)
    await page.goto('/ai/summarizer', { waitUntil: 'commit' })
    await expect(page.locator(CHAT.summarizeOptions)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  })

  test('@ci Manage Assistants has no Summarizer page link', async ({ page }) => {
    await openApp(page)
    const manage = page.locator(NAV.sidebarV2Manage)
    await expect(manage).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await manage.click()
    await page.locator(NAV.flyoutGroup('assistants')).click()
    const sub = page.locator(NAV.navSubDropdown)
    await expect(sub).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(sub.locator('[data-testid="link-sidebar-v2-doc-summary"]')).toHaveCount(0)
  })
})
