/**
 * Local real-AI smoke tests — run manually against a local dev instance
 * with real AI providers configured.
 *
 * Tagged @noci @local so they never run in CI.
 *
 * Strategy: For each capability, fetch all available models via API,
 * set each as the default, start a new chat, trigger the capability,
 * and assert a structural result (done state, media element, non-empty text).
 *
 * Run:
 *   npx playwright test --config tests/e2e/playwright.local.config.ts
 */
import { test, expect } from '../test-setup'
import { request as playwrightRequest } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'
import {
  loginAndGetCookie,
  fetchModelsByCapability,
  getDefaultModels,
  setDefaultModel,
  restoreDefaults,
  isOllama,
  type ModelInfo,
} from '../helpers/api'
import { readFileSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'

// ---------------------------------------------------------------------------
// Result tracking
// ---------------------------------------------------------------------------

interface ModelResult {
  model: string
  capability: string
  status: 'PASS' | 'FAIL' | 'SKIP'
  durationMs: number
  error?: string
}

function printSummary(results: ModelResult[], capability: string): void {
  const pass = results.filter((r) => r.status === 'PASS').length
  const fail = results.filter((r) => r.status === 'FAIL').length
  const skip = results.filter((r) => r.status === 'SKIP').length

  console.log('')
  console.log('========================================')
  console.log(`  ${capability}: ${pass} PASS | ${fail} FAIL | ${skip} SKIP`)
  console.log('----------------------------------------')
  for (const r of results) {
    const dur = (r.durationMs / 1000).toFixed(1).padStart(6)
    if (r.status === 'PASS') {
      console.log(`  PASS  ${r.model.padEnd(40)} ${dur}s`)
    } else if (r.status === 'FAIL') {
      console.log(`  FAIL  ${r.model.padEnd(40)} ${dur}s`)
      console.log(`        -> ${r.error}`)
    } else {
      console.log(`  SKIP  ${r.model.padEnd(40)} (ollama)`)
    }
  }
  console.log('========================================')
  console.log('')
}

function modelLabel(m: ModelInfo): string {
  return `${m.service} / ${m.name}`
}

// ---------------------------------------------------------------------------
// Timeouts per capability
// ---------------------------------------------------------------------------

const ANSWER_TIMEOUT: Record<string, number> = {
  CHAT: TIMEOUTS.VERY_LONG,
  PIC2TEXT: TIMEOUTS.VERY_LONG,
  TEXT2PIC: TIMEOUTS.EXTREME,
  TEXT2VID: 180_000,
  TEXT2SOUND: TIMEOUTS.VERY_LONG,
  SOUND2TEXT: TIMEOUTS.VERY_LONG,
}

const TEST_TIMEOUT: Record<string, number> = {
  CHAT: 300_000,
  PIC2TEXT: 300_000,
  TEXT2PIC: 300_000,
  TEXT2VID: 600_000,
  TEXT2SOUND: 300_000,
  SOUND2TEXT: 300_000,
}

// ---------------------------------------------------------------------------
// Fixture paths
// ---------------------------------------------------------------------------

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)
const e2eDir = resolve(__dirname, '..')
const VISION_IMAGE = resolve(e2eDir, 'test_data/vision-pattern-64.png')
const AUDIO_FILE = resolve(e2eDir, 'test_data/test-audio.mp3')

// ---------------------------------------------------------------------------
// Shared test runner
// ---------------------------------------------------------------------------

interface CapabilityTestConfig {
  capability: string
  models: ModelInfo[]
  sendMessage: (page: import('@playwright/test').Page, chat: ChatHelper) => Promise<number>
  assertAnswer: (
    page: import('@playwright/test').Page,
    chat: ChatHelper,
    previousCount: number,
    timeout: number
  ) => Promise<void>
}

async function runCapabilityTest(
  config: CapabilityTestConfig,
  page: import('@playwright/test').Page,
  credentials: { user: string; pass: string },
  request: import('@playwright/test').APIRequestContext
): Promise<ModelResult[]> {
  const results: ModelResult[] = []
  const cookie = await loginAndGetCookie(request, credentials)
  const originalDefaults = await getDefaultModels(request, cookie)

  try {
    await login(page, credentials)
    const chat = new ChatHelper(page)
    await chat.ensureAdvancedMode()

    for (const model of config.models) {
      if (isOllama(model)) {
        results.push({
          model: modelLabel(model),
          capability: config.capability,
          status: 'SKIP',
          durationMs: 0,
        })
        continue
      }

      const start = Date.now()
      try {
        await setDefaultModel(request, cookie, config.capability, model.id)
        await chat.startNewChat()

        const previousCount = await config.sendMessage(page, chat)
        await config.assertAnswer(page, chat, previousCount, ANSWER_TIMEOUT[config.capability])

        results.push({
          model: modelLabel(model),
          capability: config.capability,
          status: 'PASS',
          durationMs: Date.now() - start,
        })
      } catch (error) {
        results.push({
          model: modelLabel(model),
          capability: config.capability,
          status: 'FAIL',
          durationMs: Date.now() - start,
          error: error instanceof Error ? error.message : String(error),
        })
      }
    }
  } finally {
    await restoreDefaults(request, cookie, originalDefaults)
  }

  return results
}

// ---------------------------------------------------------------------------
// Assertion helpers
// ---------------------------------------------------------------------------

async function assertTextAnswer(
  page: import('@playwright/test').Page,
  chat: ChatHelper,
  previousCount: number,
  timeout: number
): Promise<void> {
  const aiText = await chat.waitForAnswer(previousCount, timeout)
  expect.soft(aiText.length, 'AI should produce a non-empty reply').toBeGreaterThan(0)
}

async function assertImageAnswer(
  page: import('@playwright/test').Page,
  chat: ChatHelper,
  previousCount: number,
  timeout: number
): Promise<void> {
  const bubbles = chat.conversationBubbles()
  const bubble = bubbles.nth(previousCount)
  await bubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
  await bubble.scrollIntoViewIfNeeded()

  const result = await Promise.race([
    bubble
      .locator(selectors.chat.messageDone)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'done' as const),
    bubble
      .locator(selectors.chat.messageTopicError)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'error' as const),
  ])
  if (result === 'error') {
    throw new Error('Image generation ended in error state')
  }

  await expect
    .soft(bubble.locator(selectors.chat.messageImage).first())
    .toBeVisible({ timeout: TIMEOUTS.SHORT })
}

async function assertVideoAnswer(
  page: import('@playwright/test').Page,
  chat: ChatHelper,
  previousCount: number,
  timeout: number
): Promise<void> {
  const bubbles = chat.conversationBubbles()
  const bubble = bubbles.nth(previousCount)
  await bubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
  await bubble.scrollIntoViewIfNeeded()

  const result = await Promise.race([
    bubble
      .locator(selectors.chat.messageDone)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'done' as const),
    bubble
      .locator(selectors.chat.messageTopicError)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'error' as const),
  ])
  if (result === 'error') {
    throw new Error('Video generation ended in error state')
  }

  await expect
    .soft(bubble.locator(selectors.chat.messageVideo).first())
    .toBeVisible({ timeout: TIMEOUTS.SHORT })
}

async function assertAudioAnswer(
  page: import('@playwright/test').Page,
  chat: ChatHelper,
  previousCount: number,
  timeout: number
): Promise<void> {
  const bubbles = chat.conversationBubbles()
  const bubble = bubbles.nth(previousCount)
  await bubble.waitFor({ state: 'attached', timeout: TIMEOUTS.STANDARD })
  await bubble.scrollIntoViewIfNeeded()

  const result = await Promise.race([
    bubble
      .locator(selectors.chat.messageDone)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'done' as const),
    bubble
      .locator(selectors.chat.messageTopicError)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'error' as const),
  ])
  if (result === 'error') {
    throw new Error('TTS generation ended in error state')
  }

  await expect
    .soft(bubble.locator(selectors.chat.messageAudio).first())
    .toBeVisible({ timeout: TIMEOUTS.SHORT })
}

// ===========================================================================
// CHAT
// ===========================================================================

test.describe('@noci @local Chat — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every CHAT model', async ({ page, credentials }, testInfo) => {
    const cap = 'CHAT'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one CHAT model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          const prev = await chat.conversationBubbles().count()
          await pg.locator(selectors.chat.textInput).fill('Hello! Reply with one short sentence.')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertTextAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All CHAT models should pass').toEqual([])
  })
})

// ===========================================================================
// IMAGE GENERATION (TEXT2PIC)
// ===========================================================================

test.describe('@noci @local Image Generation — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every TEXT2PIC model', async ({ page, credentials }, testInfo) => {
    const cap = 'TEXT2PIC'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one TEXT2PIC model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          const prev = await chat.conversationBubbles().count()
          await pg
            .locator(selectors.chat.textInput)
            .fill('/pic a small blue square on white background')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertImageAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All TEXT2PIC models should pass').toEqual([])
  })
})

// ===========================================================================
// VIDEO GENERATION (TEXT2VID)
// ===========================================================================

test.describe('@noci @local Video Generation — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every TEXT2VID model', async ({ page, credentials }, testInfo) => {
    const cap = 'TEXT2VID'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one TEXT2VID model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          const prev = await chat.conversationBubbles().count()
          await pg.locator(selectors.chat.textInput).fill('/vid short clip of a robot waving hello')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertVideoAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All TEXT2VID models should pass').toEqual([])
  })
})

// ===========================================================================
// VISION / IMAGE RECOGNITION (PIC2TEXT)
// ===========================================================================

test.describe('@noci @local Vision — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every PIC2TEXT model', async ({ page, credentials }, testInfo) => {
    const cap = 'PIC2TEXT'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const visionBuffer = readFileSync(VISION_IMAGE)

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one PIC2TEXT model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          await chat.attachFile({
            name: 'vision-test.png',
            mimeType: 'image/png',
            buffer: visionBuffer,
          })
          const prev = await chat.conversationBubbles().count()
          await pg
            .locator(selectors.chat.textInput)
            .fill('What do you see in this image? Describe it briefly.')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertTextAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All PIC2TEXT models should pass').toEqual([])
  })
})

// ===========================================================================
// TEXT-TO-SPEECH (TEXT2SOUND)
// ===========================================================================

test.describe('@noci @local TTS — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every TEXT2SOUND model', async ({ page, credentials }, testInfo) => {
    const cap = 'TEXT2SOUND'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one TEXT2SOUND model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          const prev = await chat.conversationBubbles().count()
          await pg
            .locator(selectors.chat.textInput)
            .fill('Read this aloud: Hello, this is a test of text to speech.')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertAudioAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All TEXT2SOUND models should pass').toEqual([])
  })
})

// ===========================================================================
// SPEECH-TO-TEXT (SOUND2TEXT)
// ===========================================================================

test.describe('@noci @local STT — all models', () => {
  test.describe.configure({ mode: 'serial' })

  test('test every SOUND2TEXT model', async ({ page, credentials }, testInfo) => {
    const cap = 'SOUND2TEXT'
    testInfo.setTimeout(TEST_TIMEOUT[cap])

    const audioBuffer = readFileSync(AUDIO_FILE)

    const ctx = await playwrightRequest.newContext()
    const allModels = await fetchModelsByCapability(ctx, await loginAndGetCookie(ctx, credentials))
    const models = allModels[cap] ?? []
    expect(models.length, 'At least one SOUND2TEXT model should be available').toBeGreaterThan(0)

    const results = await runCapabilityTest(
      {
        capability: cap,
        models,
        sendMessage: async (pg) => {
          const chat = new ChatHelper(pg)
          await chat.attachFile({
            name: 'test-audio.mp3',
            mimeType: 'audio/mpeg',
            buffer: audioBuffer,
          })
          const prev = await chat.conversationBubbles().count()
          await pg.locator(selectors.chat.textInput).fill('Transcribe this audio file.')
          await pg.locator(selectors.chat.sendBtn).click()
          return prev
        },
        assertAnswer: assertTextAnswer,
      },
      page,
      credentials,
      ctx
    )

    await ctx.dispose()
    printSummary(results, cap)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(failures, 'All SOUND2TEXT models should pass').toEqual([])
  })
})
