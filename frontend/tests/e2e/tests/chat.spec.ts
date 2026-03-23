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

test.describe('@ci @smoke Chat', () => {
  test('standard model generates answer', async ({ page, credentials }) => {
    await login(page, credentials)
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
})

test.describe('@noci @regression Chat — vision', () => {
  test('user can upload an image and gets a description from all models', async ({
    page,
    credentials,
  }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await login(page, credentials)
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
})

test.describe('@noci @regression Chat — image generation', () => {
  test('user can generate an image and test all models', async ({ page, credentials }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await login(page, credentials)
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
})

test.describe('@noci @regression Chat — video generation', () => {
  test('user can generate a video and test all models', async ({ page, credentials }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await login(page, credentials)
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
})
