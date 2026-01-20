import { test, expect, type Locator, type Page } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'

const PROMPT = 'Ai, this is a smoke test. Answer with "success" add nothing else'
const ONE_BY_ONE_PNG = Buffer.from(
  '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c6360000002000100ffff03000006000557bf0000000049454e44ae426082',
  'hex'
)

async function waitForAnswer(page: Page, previousCount: number): Promise<string> {
  const loader = page.locator(selectors.chat.loadIndicator)
  await loader.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => {})
  await loader.waitFor({ state: 'hidden' })

  const bubbles = page.locator(selectors.chat.aiAnswerBubble)
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
  await page.locator(selectors.chat.chatBtnToggle).click()
  await page.locator(selectors.chat.newChatButton).waitFor({ state: 'visible' })
  await page.locator(selectors.chat.newChatButton).click()
  await page.locator(selectors.chat.textInput).waitFor({ state: 'visible' })
}

async function attachFile(page: Page, file: { name: string; mimeType: string; buffer: Buffer }) {
  await page.locator(selectors.chat.attachBtn).click()
  await page.locator(selectors.chat.fileInput).setInputFiles(file)
}

async function openLatestAgainDropdown(page: Page): Promise<{
  toggle: Locator
  options: Locator
  optionCount: number
}> {
  const toggle = page
    .locator(selectors.chat.aiAnswerBubble)
    .last()
    .locator(selectors.chat.againDropdown)

  await expect(toggle).toBeVisible({ timeout: 10_000 })
  await expect(toggle).toBeEnabled({ timeout: 10_000 })
  await toggle.click()

  const dropdown = page.locator('.dropdown-panel').last()
  await dropdown.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {})

  const options = dropdown.locator(selectors.chat.againDropdownItem)
  await options
    .first()
    .waitFor({ state: 'visible', timeout: 10_000 })
    .catch(() => {})

  const optionCount = await options.count()

  return { toggle, options, optionCount }
}

async function runAgainOptions(
  page: Page,
  expectedToken?: string,
  failures?: string[],
  purpose?: string
) {
  const initialDropdown = await openLatestAgainDropdown(page)
  const optionCount = initialDropdown.optionCount
  await initialDropdown.toggle.click()

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
      } = await openLatestAgainDropdown(page)

      if (i >= currentOptionCount) {
        failures?.push(
          `${purpose || 'purpose'} model ${i} (index out of range, found ${currentOptionCount})`
        )
        await rowToggle.click()
        continue
      }

      const option = options.nth(i)
      await option.waitFor({ state: 'visible' })

      labelText = (await option.innerText()).toLowerCase().trim()
      if (labelText.includes('ollama')) {
        await rowToggle.click()
        continue
      }

      const currentCount = await page.locator(selectors.chat.aiAnswerBubble).count()
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
    } catch (error) {
      failures?.push(
        `${purpose || 'purpose'} model ${i} (${labelText || 'unknown'}): ${
          error instanceof Error ? error.message : String(error)
        }`
      )
    }
  }
}

test('@smoke Standard model generates valid answer "success" id=003', async ({ page }) => {
  await login(page)

  await startNewChat(page)
  await page.locator(selectors.chat.textInput).waitFor({ state: 'visible' })

  const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count()
  await page.locator(selectors.chat.textInput).fill(PROMPT)
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await waitForAnswer(page, previousCount)
  await expect(aiText).toContain('success')
})

test('@smoke All models can generate a valid answer "success" id=004', async ({ page }) => {
  const failures: string[] = []

  await login(page)

  await startNewChat(page)
  await page.locator(selectors.chat.textInput).waitFor({ state: 'visible' })

  try {
    const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count()
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

test.skip('@regression vision models respond and can be retried via again id=008', async ({
  page,
}) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  await attachFile(page, {
    name: 'vision-1x1.png',
    mimeType: 'image/png',
    buffer: ONE_BY_ONE_PNG,
  })

  const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count()
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

test.skip('@regression image generation via /pic supports again id=009', async ({ page }) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count()
  await page.locator(selectors.chat.textInput).fill('/pic draw a tiny blue square')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await waitForAnswer(page, previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await runAgainOptions(page, undefined, failures, 'image generation')

  if (failures.length > 0) {
    console.warn('Image generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})

test.skip('@regression video generation via /vid supports again id=010', async ({ page }) => {
  const failures: string[] = []

  await login(page)
  await ensureAdvancedMode(page)
  await startNewChat(page)

  const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count()
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

test.skip('@regression stt coverage via voice input id=011', async () => {
  test.skip(true, 'No automated STT trigger available in UI; requires manual voice input.')
})

test.skip('@regression tts coverage via audio playback id=012', async () => {
  test.skip(true, 'No automated TTS validation path without parsing audio; keep manual.')
})
