import { test, expect } from '../test-setup'
import { type Locator, type Page } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { TIMEOUTS, INTERVALS } from '../config/config'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import path from 'path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const PROMPT = 'Ai, this is a smoke test. Answer with "success" add nothing else'
const VISION_FIXTURE = readFileSync(path.join(__dirname, '../fixtures/vision-pattern-64.png'))

function conversationBubbles(page: Page) {
  return page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble)
}

async function waitForAnswer(page: Page, previousCount: number, longTimeout = false): Promise<string> {
  // Wait for the new answer bubble to appear in DOM (attached, not visible)
  // This fixes the flaky timeout issue where bubble exists but is outside viewport
  const bubbles = page.locator(selectors.chat.aiAnswerBubble)
  const newBubble = bubbles.nth(previousCount)
  
  // Use longer timeout for slow operations like video generation
  const loaderTimeout = longTimeout ? TIMEOUTS.EXTREME : TIMEOUTS.VERY_LONG
  const textStabilizeTimeout = longTimeout ? TIMEOUTS.VERY_LONG : TIMEOUTS.STANDARD
  
  // Wait for bubble to be attached to DOM (more reliable than 'visible')
  await newBubble.waitFor({ state: 'attached', timeout: loaderTimeout })
  
  // Scroll bubble into view to ensure it's actually visible
  await newBubble.scrollIntoViewIfNeeded()
  
  // Now wait for it to be visible (after scrolling)
  await newBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })

  // Verify the count
  await expect(bubbles).toHaveCount(previousCount + 1, { timeout: TIMEOUTS.SHORT })

  const answer = newBubble.locator(selectors.chat.messageText)
  const bubbleLoader = newBubble.locator(selectors.chat.loadIndicator)

  // Step 1: Wait for loader to disappear (first signal that streaming might be done)
  // Video generation takes much longer, so use extended timeout when requested
  await bubbleLoader.waitFor({ state: 'hidden', timeout: loaderTimeout }).catch(() => {
    // If loader never appeared (very fast response), that's fine - continue
  })

  // Step 2: Wait for text to stabilize after loader disappears
  // This handles the case where loader disappears but text still changes
  // (e.g., intermediate responses like "the user is continuing to run")
  let previousText = ''
  let stableText = ''
  await expect
    .poll(
      async () => {
        const currentText = (await answer.innerText()).trim().toLowerCase()
        // Text is stable if it's non-empty and hasn't changed for 2 consecutive checks
        if (currentText.length > 0 && currentText === previousText) {
          stableText = currentText
          return currentText
        }
        previousText = currentText
        return null // Still changing
      },
      {
        timeout: textStabilizeTimeout, // Longer timeout for slow operations like video generation
        intervals: INTERVALS.FAST(), // Check every 500ms-1s
      }
    )
    .not.toBeNull()

  return stableText
}

async function ensureAdvancedMode(page: Page) {
  const modeToggle = page.locator(selectors.header.modeToggle)
  await modeToggle.waitFor({ state: 'visible' })
  const modeLabel = (await modeToggle.innerText()).toLowerCase()
  if (modeLabel.includes('easy')) {
    await modeToggle.click()
    await expect(modeToggle).toContainText(/advanced/i)
  }
}

async function startNewChat(page: Page) {
  const conversation = page.locator(selectors.chat.messageContainer)

  await page.locator(selectors.chat.chatBtnToggle).waitFor({ state: 'visible' })
  await page.locator(selectors.chat.chatBtnToggle).click()
  await page.locator(selectors.chat.newChatButton).waitFor({ state: 'visible' })
  await page.locator(selectors.chat.newChatButton).click()
  const textInput = page.locator(selectors.chat.textInput)
  await textInput.waitFor({ state: 'visible' })

  await expect(conversation.locator(selectors.chat.aiAnswerBubble)).toHaveCount(0, {
    timeout: 10_000,
  })
  await expect(textInput).toHaveValue('', { timeout: 10_000 })
}

async function attachFile(page: Page, file: { name: string; mimeType: string; buffer: Buffer }) {
  await page.locator(selectors.chat.attachBtn).click()

  const modal = page.locator(selectors.fileSelection.modal)
  await modal.waitFor({ state: 'visible', timeout: 10_000 })

  const [fileChooser] = await Promise.all([
    page.waitForEvent('filechooser'),
    modal.locator(selectors.fileSelection.uploadButton).click(),
  ])
  await fileChooser.setFiles(file)

  await modal.getByText(file.name).first().waitFor({ state: 'visible', timeout: 30_000 })

  const attachButton = modal.locator(selectors.fileSelection.attachButton)
  await expect(attachButton).toBeEnabled({ timeout: 30_000 })
  await attachButton.click()
  await modal.waitFor({ state: 'hidden', timeout: 10_000 })
}

async function openLatestAgainDropdown(page: Page): Promise<{
  toggle: Locator
  options: Locator
  optionCount: number
}> {
  // Get the latest answer bubble and scroll it into view first
  const latestBubble = page.locator(selectors.chat.aiAnswerBubble).last()
  await latestBubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
  await latestBubble.scrollIntoViewIfNeeded()
  
  const toggle = latestBubble.locator(selectors.chat.againDropdown)

  // Wait for toggle to be visible and enabled
  await expect(toggle).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  await expect(toggle).toBeEnabled({ timeout: TIMEOUTS.STANDARD })
  
  // Check if dropdown is already open (to avoid double-click issues)
  const dropdown = page.locator('.dropdown-panel').last()
  const isAlreadyOpen = await dropdown.isVisible().catch(() => false)
  
  if (!isAlreadyOpen) {
    await toggle.click()
    // Wait for dropdown animation to complete
    await dropdown.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }

  const options = dropdown.locator(selectors.chat.againDropdownItem)
  // Wait for at least one option to be visible
  await options.first().waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

  const optionCount = await options.count()

  return { toggle, options, optionCount }
}

async function runAgainOptions(
  page: Page,
  expectedToken?: string,
  failures?: string[],
  purpose?: string,
  longTimeout = false
) {
  // Open dropdown and get initial option count
  const initialDropdown = await openLatestAgainDropdown(page)
  const optionCount = initialDropdown.optionCount
  
  // Close dropdown after reading count
  await initialDropdown.toggle.click()
  // Wait for dropdown to close (animation)
  await page.locator('.dropdown-panel').last().waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})

  if (optionCount === 0) {
    failures?.push(`No models available for ${purpose || 'unknown purpose'}`)
    return
  }

  const startIndex = optionCount > 1 ? 1 : 0

  for (let i = startIndex; i < optionCount; i += 1) {
    let labelText = ''
    let rawLabel = ''
    try {
      // Re-open dropdown for each iteration (ensures fresh state)
      const {
        toggle: rowToggle,
        options,
        optionCount: currentOptionCount,
      } = await openLatestAgainDropdown(page)

      // Validate index before proceeding
      if (i >= currentOptionCount) {
        failures?.push(
          `${purpose || 'purpose'} model ${i} (index out of range, found ${currentOptionCount})`
        )
        await rowToggle.click()
        // Wait for dropdown to close
        await page.locator('.dropdown-panel').last().waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})
        continue
      }

      const option = options.nth(i)
      // Wait for option to be visible and enabled
      await option.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await expect(option).toBeEnabled({ timeout: TIMEOUTS.SHORT })

      rawLabel = (await option.innerText()).trim()
      labelText = rawLabel.toLowerCase()
      if (labelText.includes('ollama')) {
        await rowToggle.click()
        // Wait for dropdown to close
        await page.locator('.dropdown-panel').last().waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})
        continue
      }

      // Get current bubble count before clicking
      const currentCount = await page.locator(selectors.chat.aiAnswerBubble).count()
      
      // Click option and wait for dropdown to close
      await option.click()
      // Wait for dropdown to close before proceeding
      await page.locator('.dropdown-panel').last().waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})
      
      // Wait a bit for the new message to start appearing
      await page.waitForTimeout(500)

      // Wait for answer with increased timeout for slower models (e.g., video generation)
      const aiText = await waitForAnswer(page, currentCount, longTimeout)
      
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
      
      // Ensure dropdown is closed even on error
      try {
        const dropdown = page.locator('.dropdown-panel').last()
        if (await dropdown.isVisible()) {
          const toggle = page.locator(selectors.chat.aiAnswerBubble).last().locator(selectors.chat.againDropdown)
          await toggle.click()
          await dropdown.waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {})
        }
      } catch {
        // Ignore cleanup errors
      }
    }
  }
}

test('@noci @smoke Standard model generates valid answer "success" id=003', async ({ page }) => {
  await login(page)

  await startNewChat(page)

  const previousCount = await conversationBubbles(page).count()
  await page.locator(selectors.chat.textInput).fill(PROMPT)
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await waitForAnswer(page, previousCount)
  await expect(aiText).toContain('success')
})

test('@noci @smoke All models can generate a valid answer "success" id=004', async ({ page }) => {
  const failures: string[] = []

  await login(page)

  await startNewChat(page)

  try {
    const previousCount = await conversationBubbles(page).count()
    await page.locator(selectors.chat.textInput).fill(PROMPT)
    await page.locator(selectors.chat.sendBtn).click()

    const aiText = await waitForAnswer(page, previousCount)
    await expect(aiText, 'Initial model should answer').toContain('success')
  } catch (error) {
    failures.push(
      `Initial message failed: ${error instanceof Error ? error.message : String(error)}`
    )
  }

  await runAgainOptions(page, 'success', failures, 'chat')

  if (failures.length > 0) {
    console.warn('Model run issues:', failures)
    await expect.soft(failures, 'All models should respond without errors').toEqual([])
  }
})

test('@noci @regression vision models respond and can be retried via again id=008', async ({
  page,
}) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  await attachFile(page, {
    name: 'vision-1x1.png',
    mimeType: 'image/png',
    buffer: VISION_FIXTURE,
  })

  const previousCount = await conversationBubbles(page).count()
  await page.locator(selectors.chat.textInput).fill('What do you see in this image?')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await waitForAnswer(page, previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await runAgainOptions(page, undefined, failures, 'vision')

  if (failures.length > 0) {
    console.warn('Vision purpose issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})

test('@noci @regression image generation supports again id=009', async ({ page }) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  const previousCount = await conversationBubbles(page).count()
  await page.locator(selectors.chat.textInput).fill('draw a tiny blue square')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await waitForAnswer(page, previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await runAgainOptions(page, undefined, failures, 'image generation')

  if (failures.length > 0) {
    console.warn('Image generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})

test('@noci @regression video generation via /vid supports again id=010', async ({ page }) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  const previousCount = await conversationBubbles(page).count()
  await page.locator(selectors.chat.textInput).fill('/vid short demo clip of a robot waving')
  await page.locator(selectors.chat.sendBtn).click()

  // Video generation takes much longer, use extended timeout
  const aiText = await waitForAnswer(page, previousCount, true)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  // Pass longTimeout=true to runAgainOptions so retries also use extended timeout
  await runAgainOptions(page, undefined, failures, 'video generation', true)

  if (failures.length > 0) {
    console.warn('Video generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})
