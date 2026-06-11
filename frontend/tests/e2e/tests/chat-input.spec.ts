/**
 * Chat-input action row — phase 3 of the navigation/UX redesign
 * (_devextras/planning/20260611-navigation-ia-cleanup.md §4.7).
 *
 * Contract: the row holds exactly three pills — Model, Tools, Knowledge
 * folder. Thinking and Voice reply are toggle rows INSIDE the Tools
 * dropdown (state surfaces on the pill as a dot, Q8); the only navigation
 * lives inside the pickers as clearly marked link rows (Manage folders…,
 * Summarizer).
 */
import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const CHAT = selectors.chat

async function ensureAdvancedMode(page: Page) {
  await page.evaluate(() => localStorage.setItem('app_mode', 'advanced'))
  await page.reload()
  await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

test.describe('Chat input: action row (§4.7)', () => {
  test.beforeEach(async ({ page, credentials }) => {
    await login(page, credentials)
    await ensureAdvancedMode(page)
    await expect(page.locator(CHAT.secondaryActions)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  })

  test('@ci row holds exactly the three pills — Model, Tools, Knowledge folder', async ({
    page,
  }) => {
    const row = page.locator(CHAT.secondaryActions)
    await expect(row.locator(CHAT.modelToggle)).toBeVisible()
    await expect(row.locator(CHAT.toolsToggle)).toBeVisible()
    await expect(row.locator(CHAT.knowledgeFolderBtn)).toBeVisible()

    // The retired standalone pills must not come back.
    await expect(row.locator('[data-testid="btn-chat-thinking"]')).toHaveCount(0)
    await expect(row.locator('[data-testid="btn-chat-voice-reply"]')).toHaveCount(0)
    await expect(row.locator('[data-testid="btn-manage-knowledge-groups"]')).toHaveCount(0)
  })

  test('@ci Tools dropdown lists command tools, toggles and the Summarizer link', async ({
    page,
  }) => {
    await page.locator(CHAT.toolsToggle).click()
    const panel = page.locator(CHAT.toolsPanel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })

    await expect(panel.locator('[data-testid="btn-tool-web-search"]')).toBeVisible()
    await expect(panel.locator('[data-testid="btn-tool-image-gen"]')).toBeVisible()
    await expect(panel.locator('[data-testid="btn-tool-video-gen"]')).toBeVisible()
    await expect(panel.locator(CHAT.toolThinking)).toBeVisible()
    await expect(panel.locator(CHAT.toolVoiceReply)).toBeVisible()
    await expect(panel.locator(CHAT.toolSummarizerLink)).toBeVisible()
  })

  test('@ci Voice-reply toggle flips state and surfaces as a dot on the pill (Q8)', async ({
    page,
  }) => {
    await expect(page.locator(CHAT.toolsActiveBadge)).toHaveCount(0)

    await page.locator(CHAT.toolsToggle).click()
    const voiceRow = page.locator(CHAT.toolVoiceReply)
    await expect(voiceRow).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await voiceRow.click()

    // Toggle rows keep the dropdown open (users flip several at once).
    await expect(page.locator(CHAT.toolsPanel)).toBeVisible()
    await expect(page.locator(CHAT.toolsActiveBadge)).toBeVisible()

    // Toggle back off — the dot disappears.
    await voiceRow.click()
    await expect(page.locator(CHAT.toolsActiveBadge)).toHaveCount(0)
  })

  test('@ci Summarizer link row navigates to the summarizer tool (Q3)', async ({ page }) => {
    await page.locator(CHAT.toolsToggle).click()
    await page.locator(CHAT.toolSummarizerLink).click()
    await expect(page).toHaveURL(/\/tools\/doc-summary/, { timeout: TIMEOUTS.STANDARD })
  })

  test('@ci Knowledge-folder picker opens with None option and Manage link to Files', async ({
    page,
  }) => {
    await page.locator(CHAT.knowledgeFolderBtn).click()
    const panel = page.locator(CHAT.knowledgeFolderPanel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(panel.locator(CHAT.knowledgeFolderNone)).toBeVisible()

    await panel.locator(CHAT.manageFoldersLink).click()
    await expect(page).toHaveURL(/\/files/, { timeout: TIMEOUTS.STANDARD })
  })
})
