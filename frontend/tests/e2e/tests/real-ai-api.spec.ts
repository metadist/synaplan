/**
 * Cloud AI Model Health Check — API-only smoke tests.
 *
 * Purpose: Verify that every cloud model we offer still responds correctly.
 *          Chef starts this, sees which models need attention.
 *
 * Tagged @noci @local — never runs in CI.
 *
 * Run:
 *   npm run test:e2e:real-ai                (all tests — API + UI)
 *   npm run test:e2e:real-ai -- --grep api  (API-only)
 *   INCLUDE_VIDEO=1 npm run test:e2e:real-ai -- --grep api  (with TEXT2VID)
 */
import { test, expect } from '@playwright/test'
import { getApiUrl } from '../config/config'
import {
  loginAndGetCookie,
  fetchModelsByCapability,
  getDefaultModels,
  setDefaultModel,
  isCloudProvider,
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

const INCLUDE_VIDEO = process.env.INCLUDE_VIDEO === '1'

function api(): string {
  return getApiUrl()
}

// ---------------------------------------------------------------------------
// Error classification — normalized types for actionable reports
// ---------------------------------------------------------------------------

type ErrorType =
  | 'http_error'
  | 'provider_error'
  | 'timeout'
  | 'provider_mismatch'
  | 'empty_response'
  | 'missing_file'
  | 'unknown'

function classifyError(raw: string): ErrorType {
  const lower = raw.toLowerCase()
  if (lower.includes('http 4') || lower.includes('http 5')) return 'http_error'
  if (lower.includes('timeout') || lower.includes('exceeded')) return 'timeout'
  if (lower.includes('mismatch') || lower.includes('fallback')) return 'provider_mismatch'
  if (lower.includes('empty result') || lower.includes('no text')) return 'empty_response'
  if (lower.includes('expected file') || lower.includes('none received')) return 'missing_file'
  if (
    lower.includes('provider error') ||
    lower.includes('stream error') ||
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
  providerReported?: string
  modelReported?: string
}

interface HealthReport {
  timestamp: string
  baseUrl: string
  includesVideo: boolean
  results: ModelResult[]
}

// ---------------------------------------------------------------------------
// Console output
// ---------------------------------------------------------------------------

function printConsoleSummary(results: ModelResult[]): void {
  const tested = results.filter((r) => r.status !== 'SKIP')
  const caps = [...new Set(tested.map((r) => r.capability))]

  console.log('\n========== Cloud AI Health Check ==========')
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
  console.log('--------------------------------------------')
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
  console.log('============================================\n')
}

// ---------------------------------------------------------------------------
// Report attachments
// ---------------------------------------------------------------------------

async function attachReport(
  testInfo: import('@playwright/test').TestInfo,
  report: HealthReport
): Promise<void> {
  await testInfo.attach('health-report.json', {
    body: JSON.stringify(report, null, 2),
    contentType: 'application/json',
  })

  const tested = report.results.filter((r) => r.status !== 'SKIP')
  const caps = [...new Set(tested.map((r) => r.capability))]
  const lines: string[] = [
    `Cloud AI Health Check — ${report.timestamp}`,
    `URL: ${report.baseUrl}`,
    '',
  ]

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
      const via = r.providerReported ? ` [via ${r.providerReported}/${r.modelReported}]` : ''
      if (r.status === 'PASS') {
        lines.push(`  OK    ${(r.service + ' / ' + r.modelName).padEnd(40)} ${dur}s${via}`)
      } else {
        lines.push(`  FAIL  ${(r.service + ' / ' + r.modelName).padEnd(40)} ${dur}s${via}`)
        lines.push(`        ${r.errorType}: ${r.errorMessage}`)
      }
    }
    lines.push('')
  }

  const totalPass = tested.filter((r) => r.status === 'PASS').length
  const totalFail = tested.filter((r) => r.status === 'FAIL').length
  lines.push(`TOTAL: ${totalPass} OK | ${totalFail} FAIL`)

  await testInfo.attach('health-report.txt', {
    body: lines.join('\n'),
    contentType: 'text/plain',
  })
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

  if (!res.ok()) {
    return {
      completed: false,
      chunks: [],
      hasFile: false,
      error: `HTTP ${res.status()} ${res.statusText()}`,
    }
  }

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
      // non-JSON SSE line
    }
  }

  return result
}

// ---------------------------------------------------------------------------
// Stream timeouts per capability
// ---------------------------------------------------------------------------

const STREAM_TIMEOUT: Record<string, number> = {
  CHAT: 30_000,
  PIC2TEXT: 30_000,
  TEXT2PIC: 60_000,
  TEXT2VID: 180_000,
  TEXT2SOUND: 60_000,
  SOUND2TEXT: 30_000,
}

// ---------------------------------------------------------------------------
// Capability runner — one session per capability, safe for parallel use
// ---------------------------------------------------------------------------

interface CapabilityConfig {
  capability: string
  message: string
  needsFile?: { path: string; name: string; mime: string }
  requireFile?: boolean
  // BUG: The SSE complete event always reports the CHAT provider, not the
  // capability-specific provider. For SOUND2TEXT the backend transcribes via
  // whisper (OpenAI/Groq) then forwards the text to the chat default (Groq),
  // so the complete event says "groq" regardless of which STT model was used.
  // Same for PIC2TEXT — vision result is answered by the chat model.
  // Until the backend reports the actual capability provider, skip this check. Issue #583
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
    for (const model of cloudModels) {
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

        if (actualModel === 'error' || stream.topic === 'ERROR') {
          const errorText = stream.chunks.join('').trim().slice(0, 200)
          throw new Error(`Provider error (${actualProvider}/${actualModel}): ${errorText}`)
        }

        const expectedService = model.service.toLowerCase()
        if (!config.skipProviderCheck && expectedService !== 'test') {
          if (actualProvider === 'unknown') {
            throw new Error(
              `Provider unknown: expected "${model.service}" but backend did not report provider`
            )
          }
          if (actualProvider.toLowerCase() !== expectedService) {
            throw new Error(
              `Model mismatch: expected "${model.service}" but got "${actualProvider}/${actualModel}" — possible fallback?`
            )
          }
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
          capability: config.capability,
          service: model.service,
          modelName: model.name,
          modelId: model.id,
          status: 'PASS',
          durationMs: Date.now() - start,
          providerReported: actualProvider,
          modelReported: actualModel,
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
      } finally {
        if (chatId) {
          await deleteChat(request, cookie, chatId).catch(() => {})
        }
      }
    }
  } finally {
    if (originalCapDefault !== null) {
      await setDefaultModel(request, cookie, config.capability, originalCapDefault).catch(() => {})
    }
  }

  return results
}

// ===========================================================================
// Capability definitions
// ===========================================================================

const DAILY_CAPABILITIES: CapabilityConfig[] = [
  {
    capability: 'CHAT',
    message: 'Hello! Reply with one short sentence.',
  },
  {
    capability: 'TEXT2PIC',
    message: '/pic a small blue square on white background',
    requireFile: true,
  },
  {
    capability: 'TEXT2SOUND',
    message: 'Read this aloud: Hello, this is a test.',
    requireFile: true,
  },
  {
    capability: 'PIC2TEXT',
    message: 'What do you see in this image? Describe it briefly.',
    needsFile: { path: VISION_IMAGE, name: 'vision-test.png', mime: 'image/png' },
    skipProviderCheck: true,
  },
  {
    capability: 'SOUND2TEXT',
    message: 'Transcribe this audio file.',
    needsFile: { path: AUDIO_FILE, name: 'test-audio.mp3', mime: 'audio/mpeg' },
    skipProviderCheck: true,
  },
]

const VIDEO_CAPABILITY: CapabilityConfig = {
  capability: 'TEXT2VID',
  message: '/vid short clip of a robot waving hello',
  requireFile: true,
}

// ===========================================================================
// Test
// ===========================================================================

test.describe('@noci @local @api Cloud AI health check', () => {
  test('smoke-test every cloud model across all capabilities', async ({ request }, testInfo) => {
    testInfo.setTimeout(300_000)

    const cookie = await loginAndGetCookie(request, CREDENTIALS)
    const allModels = await fetchModelsByCapability(request, cookie)

    const configs = INCLUDE_VIDEO ? [...DAILY_CAPABILITIES, VIDEO_CAPABILITY] : DAILY_CAPABILITIES

    const activeConfigs = configs.filter((c) =>
      (allModels[c.capability] ?? []).some(isCloudProvider)
    )

    const settled = await Promise.allSettled(
      activeConfigs.map((config) => runCapability(config, request))
    )

    const allResults: ModelResult[] = []
    for (let i = 0; i < settled.length; i++) {
      const outcome = settled[i]
      const config = activeConfigs[i]
      if (outcome.status === 'fulfilled') {
        allResults.push(...outcome.value)
      } else {
        allResults.push({
          capability: config.capability,
          service: '(runner)',
          modelName: config.capability,
          modelId: 0,
          status: 'FAIL',
          durationMs: 0,
          errorType: 'unknown',
          errorMessage: String(outcome.reason),
        })
      }
    }

    const report: HealthReport = {
      timestamp: new Date().toISOString(),
      baseUrl: api(),
      includesVideo: INCLUDE_VIDEO,
      results: allResults,
    }

    printConsoleSummary(allResults)
    await attachReport(testInfo, report)

    const failures = allResults.filter((r) => r.status === 'FAIL')
    expect(failures, `${failures.length} model(s) failed — see report for details`).toHaveLength(0)
  })
})
