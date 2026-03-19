/**
 * API-only real-AI smoke tests — fast model health checks without a browser.
 *
 * Tagged @noci @local so they never run in CI.
 *
 * Strategy: For each capability, fetch all available models via API,
 * set each as the default, create a chat, send a message via the SSE
 * streaming endpoint, and assert the stream completes with the expected
 * output (text, file, or transcription).
 *
 * Run:
 *   npx playwright test --config tests/e2e/playwright.local.config.ts --grep api
 */
import { test, expect } from '@playwright/test'
import { getApiUrl } from '../config/config'
import {
  loginAndGetCookie,
  fetchModelsByCapability,
  getDefaultModels,
  setDefaultModel,
  restoreDefaults,
  type ModelInfo,
} from '../helpers/api'
import { readFileSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)
const e2eDir = resolve(__dirname, '..')
const VISION_IMAGE = resolve(e2eDir, 'test_data/vision-pattern-64.png')
const AUDIO_FILE = resolve(e2eDir, 'test_data/test-audio.mp3')

const CREDENTIALS = {
  user: process.env.AUTH_USER || 'admin@synaplan.com',
  pass: process.env.AUTH_PASS || 'admin123',
}

function api(): string {
  return getApiUrl()
}

// ---------------------------------------------------------------------------
// Result tracking
// ---------------------------------------------------------------------------

interface ModelResult {
  model: string
  capability: string
  status: 'PASS' | 'FAIL'
  durationMs: number
  error?: string
  actualProvider?: string
  actualModel?: string
}

function printSummary(results: ModelResult[], capability: string): void {
  const pass = results.filter((r) => r.status === 'PASS').length
  const fail = results.filter((r) => r.status === 'FAIL').length

  console.log('')
  console.log('========================================')
  console.log(`  ${capability}: ${pass} PASS | ${fail} FAIL`)
  console.log('----------------------------------------')
  for (const r of results) {
    const dur = (r.durationMs / 1000).toFixed(1).padStart(6)
    const actual =
      r.actualProvider && r.actualModel ? ` [via ${r.actualProvider}/${r.actualModel}]` : ''
    if (r.status === 'PASS') {
      console.log(`  PASS  ${r.model.padEnd(40)} ${dur}s${actual}`)
    } else {
      console.log(`  FAIL  ${r.model.padEnd(40)} ${dur}s${actual}`)
      console.log(`        -> ${r.error}`)
    }
  }
  console.log('========================================')
  console.log('')
}

function label(m: ModelInfo): string {
  return `${m.service} / ${m.name}`
}

// ---------------------------------------------------------------------------
// API helpers
// ---------------------------------------------------------------------------

async function createChat(
  request: import('@playwright/test').APIRequestContext,
  cookie: string
): Promise<number> {
  const res = await request.post(`${api()}/api/v1/chats`, {
    headers: { Cookie: cookie, 'Content-Type': 'application/json' },
    data: JSON.stringify({}),
  })
  if (!res.ok()) throw new Error(`Create chat failed: ${res.status()}`)
  const body = await res.json()
  return body.chat.id
}

async function deleteChat(
  request: import('@playwright/test').APIRequestContext,
  cookie: string,
  chatId: number
): Promise<void> {
  await request.delete(`${api()}/api/v1/chats/${chatId}`, {
    headers: { Cookie: cookie },
  })
}

async function uploadFile(
  request: import('@playwright/test').APIRequestContext,
  cookie: string,
  filePath: string,
  fileName: string,
  mimeType: string
): Promise<number> {
  const buffer = readFileSync(filePath)
  const res = await request.post(`${api()}/api/v1/messages/upload-file`, {
    headers: { Cookie: cookie },
    multipart: {
      file: { name: fileName, mimeType, buffer },
    },
  })
  if (!res.ok()) {
    const text = await res.text()
    throw new Error(`File upload failed: ${res.status()} ${text}`)
  }
  const body = await res.json()
  return body.file_id
}

interface StreamResult {
  completed: boolean
  error?: string
  chunks: string[]
  hasFile: boolean
  fileType?: string
  fileUrl?: string
  provider?: string
  model?: string
  topic?: string
}

async function streamMessage(
  request: import('@playwright/test').APIRequestContext,
  cookie: string,
  opts: {
    chatId: number
    message: string
    fileIds?: number[]
    timeoutMs?: number
  }
): Promise<StreamResult> {
  const params = new URLSearchParams({
    message: opts.message,
    chatId: String(opts.chatId),
  })
  if (opts.fileIds?.length) params.set('fileIds', opts.fileIds.join(','))

  const url = `${api()}/api/v1/messages/stream?${params}`
  const timeout = opts.timeoutMs ?? 60_000

  const res = await request.get(url, {
    headers: { Cookie: cookie, Accept: 'text/event-stream' },
    timeout,
  })

  const text = await res.text()
  const lines = text.split('\n')

  const result: StreamResult = { completed: false, chunks: [], hasFile: false }

  for (const line of lines) {
    if (!line.startsWith('data: ')) continue
    try {
      const data = JSON.parse(line.slice(6))
      if (data.status === 'data' && data.chunk) {
        result.chunks.push(data.chunk)
      } else if (data.status === 'complete') {
        result.completed = true
        result.provider = data.provider
        result.model = data.model
        result.topic = data.topic
      } else if (data.status === 'error') {
        result.error = data.error || 'Unknown error'
      } else if (data.status === 'file') {
        result.hasFile = true
        result.fileType = data.type
        result.fileUrl = data.url
      }
    } catch {
      // non-JSON line, skip
    }
  }

  return result
}

// ---------------------------------------------------------------------------
// Per-model stream timeout: short for chat/errors, long for media generation
// ---------------------------------------------------------------------------

const STREAM_TIMEOUT: Record<string, number> = {
  CHAT: 30_000,
  PIC2TEXT: 30_000,
  TEXT2PIC: 60_000,
  TEXT2VID: 180_000,
  TEXT2SOUND: 30_000,
  SOUND2TEXT: 30_000,
}

// ---------------------------------------------------------------------------
// Generic capability runner
// ---------------------------------------------------------------------------

interface CapabilityConfig {
  capability: string
  message: string
  needsFile?: { path: string; name: string; mime: string }
  requireFile?: boolean
  skipProviderCheck?: boolean
}

async function runCapability(
  config: CapabilityConfig,
  request: import('@playwright/test').APIRequestContext
): Promise<ModelResult[]> {
  const cookie = await loginAndGetCookie(request, CREDENTIALS)
  const allModels = await fetchModelsByCapability(request, cookie)
  const models = allModels[config.capability] ?? []
  const results: ModelResult[] = []
  const originalDefaults = await getDefaultModels(request, cookie)

  expect(models.length, `At least one ${config.capability} model`).toBeGreaterThan(0)

  try {
    for (const model of models) {
      const start = Date.now()
      let chatId: number | undefined
      try {
        await setDefaultModel(request, cookie, config.capability, model.id)
        chatId = await createChat(request, cookie)

        let fileIds: number[] | undefined
        if (config.needsFile) {
          const fileId = await uploadFile(
            request,
            cookie,
            config.needsFile.path,
            config.needsFile.name,
            config.needsFile.mime
          )
          fileIds = [fileId]
        }

        const stream = await streamMessage(request, cookie, {
          chatId,
          message: config.message,
          fileIds,
          timeoutMs: STREAM_TIMEOUT[config.capability],
        })

        if (stream.error) {
          throw new Error(`Stream error: ${stream.error}`)
        }
        if (!stream.completed) {
          throw new Error('Stream did not complete (no complete event)')
        }

        const actualProvider = stream.provider ?? 'unknown'
        const actualModel = stream.model ?? 'unknown'

        if (actualModel === 'error') {
          const errorText = stream.chunks.join('').trim().slice(0, 200)
          throw new Error(`Provider error (${actualProvider}): model="error". ${errorText}`)
        }

        const expectedService = model.service.toLowerCase()
        if (
          !config.skipProviderCheck &&
          actualProvider !== 'unknown' &&
          expectedService !== 'test' &&
          actualProvider.toLowerCase() !== expectedService
        ) {
          throw new Error(
            `Model mismatch: expected "${model.service}" but got "${actualProvider}/${actualModel}"`
          )
        }

        if (config.requireFile && !stream.hasFile) {
          const fullText = stream.chunks.join('').trim()
          const hint = fullText.length > 0 ? ` (got text: "${fullText.slice(0, 100)}...")` : ''
          throw new Error(`Expected file event for ${config.capability} but none received${hint}`)
        }

        if (!config.requireFile) {
          const fullText = stream.chunks.join('')
          if (fullText.trim().length === 0 && !stream.hasFile) {
            throw new Error('Response has no text and no file — empty result')
          }
        }

        results.push({
          model: label(model),
          capability: config.capability,
          status: 'PASS',
          durationMs: Date.now() - start,
          actualProvider,
          actualModel,
        })
      } catch (error) {
        results.push({
          model: label(model),
          capability: config.capability,
          status: 'FAIL',
          durationMs: Date.now() - start,
          error: error instanceof Error ? error.message : String(error),
        })
      } finally {
        if (chatId) {
          await deleteChat(request, cookie, chatId).catch(() => {})
        }
      }
    }
  } finally {
    await restoreDefaults(request, cookie, originalDefaults)
  }

  return results
}

// ===========================================================================
// Single test that runs ALL capabilities — parallel where possible
// ===========================================================================

test.describe('@noci @local @api All AI capabilities', () => {
  test('smoke-test every model across all capabilities', async ({ request }, testInfo) => {
    testInfo.setTimeout(600_000)

    const cookie = await loginAndGetCookie(request, CREDENTIALS)
    const allModels = await fetchModelsByCapability(request, cookie)

    const allResults: ModelResult[] = []

    // Phase 1: Run capabilities that don't need file upload in parallel
    const chatConfig: CapabilityConfig = {
      capability: 'CHAT',
      message: 'Hello! Reply with one short sentence.',
    }
    const text2picConfig: CapabilityConfig = {
      capability: 'TEXT2PIC',
      message: '/pic a small blue square on white background',
      requireFile: true,
    }
    const text2vidConfig: CapabilityConfig = {
      capability: 'TEXT2VID',
      message: '/vid short clip of a robot waving hello',
      requireFile: true,
    }
    const text2soundConfig: CapabilityConfig = {
      capability: 'TEXT2SOUND',
      message: 'Read this aloud: Hello, this is a test.',
      requireFile: true,
    }
    const pic2textConfig: CapabilityConfig = {
      capability: 'PIC2TEXT',
      message: 'What do you see in this image? Describe it briefly.',
      needsFile: { path: VISION_IMAGE, name: 'vision-test.png', mime: 'image/png' },
      skipProviderCheck: true,
    }
    const sound2textConfig: CapabilityConfig = {
      capability: 'SOUND2TEXT',
      message: 'Transcribe this audio file.',
      needsFile: { path: AUDIO_FILE, name: 'test-audio.mp3', mime: 'audio/mpeg' },
      skipProviderCheck: true,
    }

    const configs = [
      chatConfig,
      text2picConfig,
      text2vidConfig,
      text2soundConfig,
      pic2textConfig,
      sound2textConfig,
    ]

    for (const config of configs) {
      const models = allModels[config.capability] ?? []
      if (models.length === 0) continue
      const results = await runCapability(config, request)
      printSummary(results, config.capability)
      allResults.push(...results)
    }

    // Print grand summary
    const caps = [...new Set(allResults.map((r) => r.capability))]
    console.log('\n\n============ GRAND SUMMARY ============')
    for (const cap of caps) {
      const capResults = allResults.filter((r) => r.capability === cap)
      const pass = capResults.filter((r) => r.status === 'PASS').length
      const fail = capResults.filter((r) => r.status === 'FAIL').length
      console.log(`  ${cap.padEnd(12)} ${pass} PASS | ${fail} FAIL`)
    }
    const totalPass = allResults.filter((r) => r.status === 'PASS').length
    const totalFail = allResults.filter((r) => r.status === 'FAIL').length
    console.log('----------------------------------------')
    console.log(`  TOTAL        ${totalPass} PASS | ${totalFail} FAIL`)
    console.log('=========================================\n')

    // Assert: each capability that has models must have at least one pass
    for (const cap of caps) {
      const capResults = allResults.filter((r) => r.capability === cap)
      const passed = capResults.filter((r) => r.status === 'PASS')
      expect(passed.length, `At least one ${cap} model must pass`).toBeGreaterThan(0)
    }
  })
})
