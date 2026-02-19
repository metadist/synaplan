import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { FIXTURE_PATHS, PROMPTS } from '../config/test-data'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import path from 'path'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const e2eDir = path.join(__dirname, '..')

const VISION_FIXTURE = readFileSync(path.join(e2eDir, FIXTURE_PATHS.VISION_PATTERN_64))

test('@003 @smoke Standard model generates answer', async ({ page }) => {
  await login(page)
  const chat = new ChatHelper(page)

  await test.step('Arrange: start new chat', async () => {
    await chat.startNewChat()
  })

  const previousCount = await chat.conversationBubbles().count()

  await test.step('Act: send smoke test message', async () => {
    await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
    await page.locator(selectors.chat.sendBtn).click()
  })

  const aiText = await chat.waitForAnswer(previousCount)

  await test.step('Assert: stream ended and assistant reply non-empty', async () => {
    expect(aiText.length).toBeGreaterThan(0)
  })
})

test('@004 @noci @nightly User can chat with all models and get a "success" answer', async ({
  page,
}) => {
  test.setTimeout(120_000)
  const failures: string[] = []
  const chat = new ChatHelper(page)

  await login(page)
  await chat.startNewChat()

  try {
    const previousCount = await chat.conversationBubbles().count()
    await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
    await page.locator(selectors.chat.sendBtn).click()

    const aiText = await chat.waitForAnswer(previousCount)
    await expect(aiText, 'Initial model should answer').toContain('success')
  } catch (error) {
    failures.push(
      `Initial message failed: ${error instanceof Error ? error.message : String(error)}`
    )
  }

  await chat.runAgainOptions('success', failures, 'chat')

  if (failures.length > 0) {
    console.warn('Model run issues:', failures)
    await expect.soft(failures, 'All models should respond without errors').toEqual([])
  }
})

test('@008 @noci @regression User can upload an image and gets a discription from all models', async ({
  page,
}) => {
  const failures: string[] = []
  const chat = new ChatHelper(page)

  await login(page)
  await chat.ensureAdvancedMode()
  await chat.startNewChat()

  await chat.attachFile({
    name: 'vision-1x1.png',
    mimeType: 'image/png',
    buffer: VISION_FIXTURE,
  })

  const previousCount = await chat.conversationBubbles().count()
  await page.locator(selectors.chat.textInput).fill('What do you see in this image?')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await chat.waitForAnswer(previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await chat.runAgainOptions(undefined, failures, 'vision')

  if (failures.length > 0) {
    console.warn('Vision purpose issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})

test('@009 @noci @regression User can generate an image and test all models', async ({
  page,
}) => {
  const failures: string[] = []
  const chat = new ChatHelper(page)

  await login(page)
  await chat.ensureAdvancedMode()
  await chat.startNewChat()

  const previousCount = await chat.conversationBubbles().count()
  await page.locator(selectors.chat.textInput).fill('draw a tiny blue square')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await chat.waitForAnswer(previousCount)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()

  await chat.runAgainOptions(undefined, failures, 'image generation')

  if (failures.length > 0) {
    console.warn('Image generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})

test('@010 @noci @regression User can generate a video and test all models', async ({ page }) => {
  const failures: string[] = []
  const chat = new ChatHelper(page)

  await login(page)
  await chat.ensureAdvancedMode()
  await chat.startNewChat()

  const previousCount = await chat.conversationBubbles().count()
  await page.locator(selectors.chat.textInput).fill('/vid short demo clip of a robot waving')
  await page.locator(selectors.chat.sendBtn).click()

  const aiText = await chat.waitForAnswer(previousCount, true)
  await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()
  await chat.runAgainOptions(undefined, failures, 'video generation', true)

  if (failures.length > 0) {
    console.warn('Video generation issues:', failures)
    await expect.soft(failures).toEqual([])
  }
})
