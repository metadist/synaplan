/**
 * Cloud AI Model Health Check — browser-based UI tests.
 *
 * Purpose: Same as the API-only test, but runs through the actual UI so you
 *          can visually verify that chat, images, audio, video etc. render
 *          correctly in the browser.
 *
 * Tagged @noci @local — never runs in CI.
 *
 * Run:
 *   npm run test:e2e:real-ai                        (all tests — API + UI)
 *   npm run test:e2e:real-ai -- --grep-invert api   (UI-only)
 *   INCLUDE_VIDEO=1 npm run test:e2e:real-ai        (with TEXT2VID)
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
  isCloudProvider,
  type ModelInfo,
} from '../helpers/api'
import { readFileSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'

const INCLUDE_VIDEO = process.env.INCLUDE_VIDEO === '1'

// ---------------------------------------------------------------------------
// Error classification — same as API test for consistent reports
// ---------------------------------------------------------------------------

type ErrorType =
  | 'http_error'
  | 'provider_error'
  | 'timeout'
  | 'empty_response'
  | 'missing_file'
  | 'unknown'

function classifyError(raw: string): ErrorType {
  const lower = raw.toLowerCase()
  if (lower.includes('http 4') || lower.includes('http 5')) return 'http_error'
  if (lower.includes('timeout') || lower.includes('exceeded')) return 'timeout'
  if (lower.includes('empty') || lower.includes('no text')) return 'empty_response'
  if (lower.includes('expected file') || lower.includes('none received')) return 'missing_file'
  if (
    lower.includes('provider error') ||
    lower.includes('error state') ||
    lower.includes('did not complete')
  )
    return 'provider_error'
  return 'unknown'
}

// ---------------------------------------------------------------------------
// Result types
// ---------------------------------------------------------------------------

interface ModelResult {
  capability: string
  service: string
  modelName: string
  modelId: number
  status: 'PASS' | 'FAIL' | 'SKIP'
  durationMs: number
  errorType?: ErrorType
  errorMessage?: string
}

interface HealthReport {
  timestamp: string
  mode: 'ui'
  includesVideo: boolean
  results: ModelResult[]
}

function modelLabel(m: ModelInfo): string {
  return `${m.service} / ${m.name}`
}

// ---------------------------------------------------------------------------
// Console output
// ---------------------------------------------------------------------------

function printConsoleSummary(results: ModelResult[]): void {
  const tested = results.filter((r) => r.status !== 'SKIP')
  const caps = [...new Set(tested.map((r) => r.capability))]

  console.log('\n========== Cloud AI Health Check (UI) ==========')
  for (const cap of caps) {
    const capResults = tested.filter((r) => r.capability === cap)
    const pass = capResults.filter((r) => r.status === 'PASS').length
    const fail = capResults.filter((r) => r.status === 'FAIL').length
    const icon = fail > 0 ? 'x' : '✓'
    console.log(`  ${icon}  ${cap.padEnd(12)} ${pass} pass | ${fail} fail`)
  }

  const totalPass = tested.filter((r) => r.status === 'PASS').length
  const totalFail = tested.filter((r) => r.status === 'FAIL').length
  const totalSkip = results.length - tested.length
  console.log('-------------------------------------------------')
  console.log(`     TOTAL        ${totalPass} pass | ${totalFail} fail | ${totalSkip} skipped`)

  const failures = tested.filter((r) => r.status === 'FAIL')
  if (failures.length > 0) {
    console.log('\n  Models that need attention:')
    console.log('  --------------------------')
    for (const f of failures) {
      const dur = (f.durationMs / 1000).toFixed(1)
      console.log(`  ${f.capability.padEnd(12)} ${f.service} / ${f.modelName}  (${dur}s)`)
      console.log(`               ${f.errorType}: ${f.errorMessage}`)
    }
  }
  console.log('=================================================\n')
}

// ---------------------------------------------------------------------------
// Report attachments
// ---------------------------------------------------------------------------

async function attachReport(
  testInfo: import('@playwright/test').TestInfo,
  report: HealthReport
): Promise<void> {
  await testInfo.attach('health-report-ui.json', {
    body: JSON.stringify(report, null, 2),
    contentType: 'application/json',
  })

  const tested = report.results.filter((r) => r.status !== 'SKIP')
  const caps = [...new Set(tested.map((r) => r.capability))]
  const lines: string[] = [`Cloud AI Health Check (UI) — ${report.timestamp}`, '']

  for (const cap of caps) {
    const capResults = tested.filter((r) => r.capability === cap)
    const pass = capResults.filter((r) => r.status === 'PASS').length
    const fail = capResults.filter((r) => r.status === 'FAIL').length
    const skip = report.results.filter((r) => r.capability === cap && r.status === 'SKIP').length
    const header =
      skip > 0 ? `${pass} OK | ${fail} FAIL  (${skip} skipped)` : `${pass} OK | ${fail} FAIL`
    lines.push(`${cap}: ${header}`, '----------------------------------------')
    for (const r of capResults) {
      const dur = (r.durationMs / 1000).toFixed(1).padStart(6)
      if (r.status === 'PASS') {
        lines.push(
          `  OK    ${modelLabel({ service: r.service, name: r.modelName } as ModelInfo).padEnd(40)} ${dur}s`
        )
      } else {
        lines.push(
          `  FAIL  ${modelLabel({ service: r.service, name: r.modelName } as ModelInfo).padEnd(40)} ${dur}s`
        )
        lines.push(`        ${r.errorType}: ${r.errorMessage}`)
      }
    }
    lines.push('')
  }

  const totalPass = tested.filter((r) => r.status === 'PASS').length
  const totalFail = tested.filter((r) => r.status === 'FAIL').length
  lines.push(`TOTAL: ${totalPass} OK | ${totalFail} FAIL`)

  await testInfo.attach('health-report-ui.txt', {
    body: lines.join('\n'),
    contentType: 'text/plain',
  })
}

// ---------------------------------------------------------------------------
// Timeouts per capability
// ---------------------------------------------------------------------------

const ANSWER_TIMEOUT: Record<string, number> = {
  CHAT: TIMEOUTS.VERY_LONG,
  PIC2TEXT: TIMEOUTS.VERY_LONG,
  TEXT2PIC: TIMEOUTS.EXTREME,
  TEXT2VID: 180_000,
  TEXT2SOUND: 60_000,
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
// Assertion helpers
// ---------------------------------------------------------------------------

async function assertTextAnswer(
  _page: import('@playwright/test').Page,
  chat: ChatHelper,
  previousCount: number,
  timeout: number
): Promise<void> {
  const aiText = await chat.waitForAnswer(previousCount, timeout >= TIMEOUTS.EXTREME)
  expect.soft(aiText.length, 'AI should produce a non-empty reply').toBeGreaterThan(0)
}

async function assertMediaAnswer(
  chat: ChatHelper,
  previousCount: number,
  timeout: number,
  mediaSelector: string,
  mediaName: string
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
    throw new Error(`${mediaName} generation ended in error state`)
  }

  await expect.soft(bubble.locator(mediaSelector).first()).toBeVisible({ timeout: TIMEOUTS.SHORT })
}

// ---------------------------------------------------------------------------
// Capability config
// ---------------------------------------------------------------------------

interface CapabilityConfig {
  capability: string
  sendMessage: (page: import('@playwright/test').Page, chat: ChatHelper) => Promise<number>
  assertAnswer: (
    page: import('@playwright/test').Page,
    chat: ChatHelper,
    previousCount: number,
    timeout: number
  ) => Promise<void>
}

function buildCapabilities(): CapabilityConfig[] {
  const visionBuffer = readFileSync(VISION_IMAGE)
  const audioBuffer = readFileSync(AUDIO_FILE)

  const daily: CapabilityConfig[] = [
    {
      capability: 'CHAT',
      sendMessage: async (pg) => {
        const chat = new ChatHelper(pg)
        const prev = await chat.conversationBubbles().count()
        await pg.locator(selectors.chat.textInput).fill('Hello! Reply with one short sentence.')
        await pg.locator(selectors.chat.sendBtn).click()
        return prev
      },
      assertAnswer: assertTextAnswer,
    },
    {
      capability: 'TEXT2PIC',
      sendMessage: async (pg) => {
        const chat = new ChatHelper(pg)
        const prev = await chat.conversationBubbles().count()
        await pg
          .locator(selectors.chat.textInput)
          .fill('/pic a small blue square on white background')
        await pg.locator(selectors.chat.sendBtn).click()
        return prev
      },
      assertAnswer: async (_page, chat, prev, timeout) =>
        assertMediaAnswer(chat, prev, timeout, selectors.chat.messageImage, 'Image'),
    },
    {
      capability: 'TEXT2SOUND',
      sendMessage: async (pg) => {
        const chat = new ChatHelper(pg)
        const prev = await chat.conversationBubbles().count()
        await pg
          .locator(selectors.chat.textInput)
          .fill('Read this aloud: Hello, this is a test of text to speech.')
        await pg.locator(selectors.chat.sendBtn).click()
        return prev
      },
      assertAnswer: async (_page, chat, prev, timeout) =>
        assertMediaAnswer(chat, prev, timeout, selectors.chat.messageAudio, 'TTS'),
    },
    {
      capability: 'PIC2TEXT',
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
    {
      capability: 'SOUND2TEXT',
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
  ]

  if (INCLUDE_VIDEO) {
    daily.push({
      capability: 'TEXT2VID',
      sendMessage: async (pg) => {
        const chat = new ChatHelper(pg)
        const prev = await chat.conversationBubbles().count()
        await pg.locator(selectors.chat.textInput).fill('/vid short clip of a robot waving hello')
        await pg.locator(selectors.chat.sendBtn).click()
        return prev
      },
      assertAnswer: async (_page, chat, prev, timeout) =>
        assertMediaAnswer(chat, prev, timeout, selectors.chat.messageVideo, 'Video'),
    })
  }

  return daily
}

// ---------------------------------------------------------------------------
// Test runner — one capability at a time (serial, uses browser)
// ---------------------------------------------------------------------------

async function runCapabilityUI(
  config: CapabilityConfig,
  page: import('@playwright/test').Page,
  credentials: { user: string; pass: string },
  apiCtx: import('@playwright/test').APIRequestContext
): Promise<ModelResult[]> {
  const results: ModelResult[] = []
  const cookie = await loginAndGetCookie(apiCtx, credentials)
  const allModels = await fetchModelsByCapability(apiCtx, cookie)
  const models = allModels[config.capability] ?? []
  const originalDefaults = await getDefaultModels(apiCtx, cookie)
  const originalCapDefault = originalDefaults[config.capability] ?? null

  const cloudModels = models.filter(isCloudProvider)
  const skippedModels = models.filter((m) => !isCloudProvider(m))

  for (const m of skippedModels) {
    results.push({
      capability: config.capability,
      service: m.service,
      modelName: m.name,
      modelId: m.id,
      status: 'SKIP',
      durationMs: 0,
    })
  }

  if (cloudModels.length === 0) return results

  try {
    await login(page, credentials)
    const chat = new ChatHelper(page)
    await chat.ensureAdvancedMode()

    for (const model of cloudModels) {
      const start = Date.now()
      try {
        await setDefaultModel(apiCtx, cookie, config.capability, model.id)
        await chat.startNewChat()

        const previousCount = await config.sendMessage(page, chat)
        await config.assertAnswer(page, chat, previousCount, ANSWER_TIMEOUT[config.capability])

        results.push({
          capability: config.capability,
          service: model.service,
          modelName: model.name,
          modelId: model.id,
          status: 'PASS',
          durationMs: Date.now() - start,
        })
      } catch (error) {
        const msg = error instanceof Error ? error.message : String(error)
        results.push({
          capability: config.capability,
          service: model.service,
          modelName: model.name,
          modelId: model.id,
          status: 'FAIL',
          durationMs: Date.now() - start,
          errorType: classifyError(msg),
          errorMessage: msg,
        })
      }
    }
  } finally {
    if (originalCapDefault !== null) {
      await setDefaultModel(apiCtx, cookie, config.capability, originalCapDefault).catch(() => {})
    }
  }

  return results
}

// ===========================================================================
// Tests — one test.describe per capability for visual separation in report
// ===========================================================================

const CAPABILITIES = buildCapabilities()

for (const config of CAPABILITIES) {
  test.describe(`@noci @local ${config.capability} — UI health check`, () => {
    test.describe.configure({ mode: 'serial' })

    test(`test every ${config.capability} cloud model`, async ({ page, credentials }, testInfo) => {
      testInfo.setTimeout(TEST_TIMEOUT[config.capability] ?? 300_000)

      const ctx = await playwrightRequest.newContext()
      const results = await runCapabilityUI(config, page, credentials, ctx)
      await ctx.dispose()

      const report: HealthReport = {
        timestamp: new Date().toISOString(),
        mode: 'ui',
        includesVideo: INCLUDE_VIDEO,
        results,
      }

      printConsoleSummary(results)
      await attachReport(testInfo, report)

      const failures = results.filter((r) => r.status === 'FAIL')
      expect(
        failures,
        `${failures.length} ${config.capability} model(s) failed — see report`
      ).toHaveLength(0)
    })
  })
}
