import { test, expect, type Locator, type Page } from '@playwright/test'
import { readFileSync } from 'fs'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'

const PROMPT = 'Ai, this is a smoke test. Answer with "success" add nothing else'
const VISION_FIXTURE = readFileSync(new URL('../fixtures/vision-pattern-64.png', import.meta.url))

function conversationBubbles(page: Page) {
  return page.locator(selectors.chat.messageContainer).locator(selectors.chat.aiAnswerBubble)
}

async function waitForAnswer(page: Page, previousCount: number): Promise<string> {
  const loader = page.locator(selectors.chat.loadIndicator)
  await loader.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => {})
  await loader.waitFor({ state: 'hidden' })

  const bubbles = conversationBubbles(page)
  await expect(bubbles).toHaveCount(previousCount + 1, { timeout: 30_000 })

  const answer = bubbles.nth(previousCount).locator(selectors.chat.messageText)
  return (await answer.innerText()).trim().toLowerCase()
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
  const toggle = conversationBubbles(page)
    .last()
    .locator(selectors.chat.againDropdown)

  await toggle.scrollIntoViewIfNeeded()
  await expect(toggle).toBeVisible({ timeout: 10_000 })
  await expect(toggle).toBeEnabled({ timeout: 10_000 })
  await toggle.click()

  const dropdown = page.locator('.dropdown-panel').last()
  try {
    await dropdown.waitFor({ state: 'visible', timeout: 5_000 })
  } catch {
    await toggle.click()
    await dropdown.waitFor({ state: 'visible', timeout: 5_000 })
  }

  const options = dropdown.locator(selectors.chat.againDropdownItem)
  await options.first().waitFor({ state: 'visible', timeout: 5_000 })

  const optionCount = await options.count()

  return { toggle, options, optionCount }
}

async function runAgainOptions(
  page: Page,
  expectedToken?: string,
  failures?: string[],
  purpose?: string
) {
  async function restoreLastWorkingModel(label: string) {
    try {
      const { toggle, options } = await openLatestAgainDropdown(page)
      const candidate = options.filter({ hasText: label }).first()
      if ((await candidate.count()) === 0) {
        await toggle.click()
        return
      }

      const currentCount = await conversationBubbles(page).count()
      await candidate.click()
      await waitForAnswer(page, currentCount)
    } catch (restoreError) {
      failures?.push(
        `Failed to restore last working model (${label}): ${
          restoreError instanceof Error ? restoreError.message : String(restoreError)
        }`
      )
    }
  }

  let initialDropdown: Awaited<ReturnType<typeof openLatestAgainDropdown>>
  try {
    initialDropdown = await openLatestAgainDropdown(page)
  } catch (error) {
    failures?.push(
      `${purpose || 'purpose'} dropdown failed to open: ${
        error instanceof Error ? error.message : String(error)
      }`
    )
    return
  }
  const optionCount = initialDropdown.optionCount
  await initialDropdown.toggle.click()

  if (optionCount === 0) {
    failures?.push(`No models available for ${purpose || 'unknown purpose'}`)
    return
  }

  const startIndex = optionCount > 1 ? 1 : 0
  let lastWorkingLabel: string | null = null

  for (let i = startIndex; i < optionCount; i += 1) {
    let labelText = ''
    let rawLabel = ''
    try {
      const {
        toggle: rowToggle,
        options,
        optionCount: currentOptionCount,
      } = await openLatestAgainDropdown(page)

      if (i >= currentOptionCount) {
        failures?.push(
          `${purpose || 'purpose'} model ${i} (index out of range, found ${currentOptionCount})`
        )
        await rowToggle.click()
        continue
      }

      const option = options.nth(i)
      await option.waitFor({ state: 'visible', timeout: 5_000 })

      rawLabel = (await option.innerText()).trim()
      labelText = rawLabel.toLowerCase()
      if (labelText.includes('ollama')) {
        await rowToggle.click()
        continue
      }

      const currentCount = await conversationBubbles(page).count()
      await option.click()

      const aiText = await waitForAnswer(page, currentCount)
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
      lastWorkingLabel = rawLabel
    } catch (error) {
      failures?.push(
        `${purpose || 'purpose'} model ${i} (${labelText || 'unknown'}): ${
          error instanceof Error ? error.message : String(error)
        }`
      )
      if (lastWorkingLabel) {
        await restoreLastWorkingModel(lastWorkingLabel)
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
    await expect.soft(aiText, 'Initial model should answer').toContain('success')
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

  const aiText = await waitForAnswer(page, previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await runAgainOptions(page, undefined, failures, 'video generation')

  if (failures.length > 0) {
    console.warn('Video generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})
