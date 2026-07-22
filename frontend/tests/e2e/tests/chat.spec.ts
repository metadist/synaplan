import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
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
  test('standard model generates answer', async ({ page }) => {
    await openApp(page)
    const chat = new ChatHelper(page)

    await test.step('Arrange: start new chat', async () => {
      await chat.startNewChat()
    })

    const previousCount = await chat.sendMessage(PROMPTS.CHAT_SMOKE)

    const aiText = await chat.waitForAnswer(previousCount)

    await test.step('Assert: stream ended and assistant reply non-empty', async () => {
      expect(aiText.length).toBeGreaterThan(0)
    })
  })
})

test.describe('@noci @nightly Chat — all models', () => {
  test('user can chat with all models and get a "success" answer', async ({ page }) => {
    test.setTimeout(120_000)
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.startNewChat()

    try {
      const previousCount = await chat.sendMessage(PROMPTS.CHAT_SMOKE)
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
})

test.describe('@noci @regression Chat — vision', () => {
  test('user can upload an image and gets a description from all models', async ({ page }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.ensureAdvancedMode()
    await chat.startNewChat()

    await chat.attachFile({
      name: 'vision-1x1.png',
      mimeType: 'image/png',
      buffer: VISION_FIXTURE,
    })

    const previousCount = await chat.sendMessage('What do you see in this image?')
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
  test('user can generate an image and test all models', async ({ page }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.ensureAdvancedMode()
    await chat.startNewChat()

    const previousCount = await chat.sendMessage('draw a tiny blue square')
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
  test('user can generate a video and test all models', async ({ page }) => {
    const failures: string[] = []
    const chat = new ChatHelper(page)

    await openApp(page)
    await chat.ensureAdvancedMode()
    await chat.startNewChat()

    const previousCount = await chat.sendMessage('/vid short demo clip of a robot waving')
    const aiText = await chat.waitForAnswer(previousCount, true)
    await expect.soft(aiText.includes('error') || aiText.includes('failed')).toBeFalsy()
    await chat.runAgainOptions(undefined, failures, 'video generation', true)

    if (failures.length > 0) {
      console.warn('Video generation issues:', failures)
      await expect.soft(failures).toEqual([])
    }
  })
})
