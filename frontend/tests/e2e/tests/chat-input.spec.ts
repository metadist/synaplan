/**
 * Chat-input controls — Release 4.0 lean composer
 * (_devextras/planning/release4.0/10_landing-streamline-guest-ux.md §5).
 *
 * The old always-visible action row is gone. Per-message controls (Model,
 * Tools, Knowledge folder) plus "Attach files" now live inside the "+" menu.
 * The menu always opens; Thinking/Voice reply/Enhance remain toggle rows
 * INSIDE the Tools dropdown, and the only navigation lives inside the pickers
 * as clearly marked link rows (Manage folders…, Summarizer).
 */
import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS } from '../config/config'

const CHAT = selectors.chat

/**
 * Open the "+" menu and wait for its content to settle.
 *
 * On mobile, touch event normalization can swallow a single click on the
 * toggle (the handler IS wired up, but the browser discards the pointer event
 * during layout shifts). We retry the click via toPass() — but unlike the old
 * helper, we never mask failures with .catch(() => false).
 */
async function openPlusMenu(page: Page) {
  const panel = page.locator(CHAT.plusPanel)
  if (await panel.isVisible()) return panel

  const toggle = page.locator(CHAT.plusToggle)
  await expect(toggle).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  await expect(async () => {
    if (await panel.isVisible()) return
    await toggle.click()
    await expect(panel).toBeVisible({ timeout: 2000 })
  }).toPass({ timeout: TIMEOUTS.STANDARD })

  await expect(panel.locator(CHAT.attachBtn)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return panel
}

/**
 * Open the Tools dropdown inside the "+" menu and wait for it to settle.
 * Same retry rationale as openPlusMenu — the tools toggle can also miss on mobile.
 */
async function openToolsDropdown(page: Page) {
  await openPlusMenu(page)

  const toolsPanel = page.locator(CHAT.toolsPanel)
  const toolsToggle = page.locator(CHAT.toolsToggle)
  await expect(toolsToggle).toBeVisible({ timeout: TIMEOUTS.SHORT })

  await expect(async () => {
    if (await toolsPanel.isVisible()) return
    await toolsToggle.click()
    await expect(toolsPanel).toBeVisible({ timeout: 2000 })
  }).toPass({ timeout: TIMEOUTS.STANDARD })
}

test.describe('Chat input: "+" menu (§5)', () => {
  test.beforeEach(async ({ page, credentials }) => {
    await login(page, credentials)
    await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(CHAT.plusToggle)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  })

  test('@ci "+" menu holds Attach, Model, Tools and Knowledge folder', async ({ page }) => {
    const panel = await openPlusMenu(page)
    await expect(panel.locator(CHAT.attachBtn)).toBeVisible()
    await expect(panel.locator(CHAT.modelToggle)).toBeVisible()
    await expect(panel.locator(CHAT.toolsToggle)).toBeVisible()
    await expect(panel.locator(CHAT.knowledgeFolderBtn)).toBeVisible()

    // The retired standalone pills must not come back.
    await expect(panel.locator('[data-testid="btn-chat-thinking"]')).toHaveCount(0)
    await expect(panel.locator('[data-testid="btn-chat-voice-reply"]')).toHaveCount(0)
    await expect(panel.locator('[data-testid="btn-manage-knowledge-groups"]')).toHaveCount(0)
  })

  test('@ci Tools dropdown lists command tools, toggles and the Summarizer link', async ({
    page,
  }) => {
    await openToolsDropdown(page)
    const panel = page.locator(CHAT.toolsPanel)

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
    await openPlusMenu(page)
    await expect(page.locator(CHAT.toolsActiveBadge)).toHaveCount(0)

    await openToolsDropdown(page)
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

  test('@ci @layout Enhance sits in-shell on desktop and inside Tools on mobile', async ({
    page,
  }) => {
    const isMobile = (page.viewportSize()?.width ?? 1280) < 768

    // The control only appears once there is text to act on.
    const input = page.locator(CHAT.textInput)
    await input.fill('Wo wohnt der Esel?')
    await expect(input).toHaveValue('Wo wohnt der Esel?', { timeout: TIMEOUTS.SHORT })

    if (isMobile) {
      // No in-shell sparkles button crowding the narrow input…
      await expect(page.locator(CHAT.enhanceButton)).toHaveCount(0)
      // …the control lives in the Tools dropdown (inside the "+" menu) instead.
      await openToolsDropdown(page)
      await expect(page.locator(CHAT.toolEnhance)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    } else {
      await expect(page.locator(CHAT.enhanceButton)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await openToolsDropdown(page)
      await expect(page.locator(CHAT.toolEnhance)).toBeHidden()
    }
  })

  test('@ci Summarizer link row navigates to the summarizer tool (Q3)', async ({ page }) => {
    await openToolsDropdown(page)
    await expect(page.locator(CHAT.toolSummarizerLink)).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await page.locator(CHAT.toolSummarizerLink).click()
    await expect(page).toHaveURL(/\/ai\/summarizer/, { timeout: TIMEOUTS.STANDARD })
  })

  test('@ci Knowledge-folder picker opens with None option and Manage link to Files', async ({
    page,
  }) => {
    await openPlusMenu(page)
    await page.locator(CHAT.knowledgeFolderBtn).click()
    const panel = page.locator(CHAT.knowledgeFolderPanel)
    await expect(panel).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(panel.locator(CHAT.knowledgeFolderNone)).toBeVisible()

    await panel.locator(CHAT.manageFoldersLink).click()
    await expect(page).toHaveURL(/\/files/, { timeout: TIMEOUTS.STANDARD })
  })
})
