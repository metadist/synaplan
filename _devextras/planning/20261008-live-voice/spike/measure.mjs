#!/usr/bin/env node
/**
 * LV0 spike — not product code.
 *
 * Measures first-audio latency, delegation latency and reported usage for
 * gpt-live-1 (US and EU) and gemini-3.8-live. Also times one local
 * MessageProcessor round trip (POST /api/v1/messages/send) so the spoken
 * answer path can be added up.
 *
 * Reads provider keys from backend/.env. Never prints them.
 *
 *   node _devextras/planning/20261008-live-voice/spike/measure.mjs
 */

import { spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const repo = join(here, '../../../..')
const require = createRequire(join(repo, 'frontend/package.json'))
const WebSocket = require('ws')

const PHRASE = 'What is the capital of France?'
const CANNED =
  'The capital of France is Paris. It sits on the Seine and is the seat of the French government.'
const SESSION_BUDGET_MS = 75_000
const PIPELINE_BUDGET_MS = 45_000

const env = loadEnv(join(repo, 'backend/.env'))

function loadEnv(path) {
  const out = {}
  for (const line of readFileSync(path, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/)
    if (!m) continue
    out[m[1]] = m[2].trim().replace(/^["']|["']$/g, '')
  }
  return out
}

function redact(value) {
  return String(value)
    .replace(/sk-[A-Za-z0-9_-]{8,}/g, 'sk-…')
    .replace(/AIza[0-9A-Za-z_-]{8,}/g, 'AIza…')
    .replace(/Bearer\s+\S+/g, 'Bearer …')
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function pcmFromWav(buf) {
  let offset = 12
  while (offset + 8 <= buf.length) {
    const id = buf.toString('ascii', offset, offset + 4)
    const size = buf.readUInt32LE(offset + 4)
    if (id === 'data') return buf.subarray(offset + 8, offset + 8 + size)
    offset += 8 + size + (size % 2)
  }
  throw new Error('WAV has no data chunk')
}

function synthesize(rate) {
  const dir = mkdtempSync(join(tmpdir(), 'lv0-'))
  const aiff = join(dir, 'q.aiff')
  const wav = join(dir, `q-${rate}.wav`)
  const said = spawnSync('say', ['-v', 'Samantha', '-o', aiff, PHRASE], { encoding: 'utf8' })
  if (said.status !== 0) throw new Error(`say failed: ${said.stderr}`)
  const converted = spawnSync(
    'afconvert',
    ['-f', 'WAVE', '-d', `LEI16@${rate}`, aiff, wav],
    { encoding: 'utf8' },
  )
  if (converted.status !== 0) throw new Error(`afconvert failed: ${converted.stderr}`)
  return pcmFromWav(readFileSync(wav))
}

async function sendPaced(send, pcm, rate) {
  const bytesPerChunk = Math.floor((rate * 2 * 100) / 1000)
  const started = Date.now()
  for (let i = 0; i < pcm.length; i += bytesPerChunk) {
    const slice = pcm.subarray(i, Math.min(pcm.length, i + bytesPerChunk))
    const even = slice.length - (slice.length % 2)
    if (even > 0) send(slice.subarray(0, even))
    const elapsed = Date.now() - started
    const target = ((i + even) / (rate * 2)) * 1000
    if (target > elapsed) await sleep(target - elapsed)
  }
  const speechEnded = Date.now()
  const silence = Buffer.alloc(Math.floor(rate * 2 * 1.5))
  for (let i = 0; i < silence.length; i += bytesPerChunk) {
    send(silence.subarray(i, i + bytesPerChunk))
    await sleep(100)
  }
  return { speechEnded, audioSent: Date.now() }
}

function connect(url, headers) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(url, { headers })
    const timer = setTimeout(() => {
      ws.terminate()
      reject(new Error('connect timeout'))
    }, 15_000)
    ws.once('open', () => {
      clearTimeout(timer)
      resolve(ws)
    })
    ws.once('error', (error) => {
      clearTimeout(timer)
      reject(error)
    })
  })
}

async function timeMessageProcessor() {
  const started = Date.now()
  const login = await fetch('http://localhost:8000/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'demo@synaplan.com', password: 'demo123' }),
    signal: AbortSignal.timeout(15_000),
  })
  const cookies = (login.headers.getSetCookie?.() ?? [])
    .map((cookie) => cookie.split(';')[0])
    .join('; ')
  if (!login.ok || cookies === '') {
    return {
      ok: false,
      ms: Date.now() - started,
      status: login.status,
      error: 'login failed',
    }
  }
  const created = await fetch('http://localhost:8000/api/v1/chats', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Cookie: cookies },
    body: JSON.stringify({ title: 'LV0 spike' }),
    signal: AbortSignal.timeout(15_000),
  })
  const createdBody = await created.json().catch(() => ({}))
  const chatId = createdBody.chat?.id
  if (!created.ok || chatId == null) {
    return { ok: false, ms: Date.now() - started, status: created.status, error: 'chat create failed' }
  }
  // The demo user's default model is the canned test provider. Override with
  // gpt-5.4-mini (catalog id 232) so this times the real chat pipeline.
  const callStarted = Date.now()
  const response = await fetch('http://localhost:8000/api/v1/messages/stream', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
      Cookie: cookies,
    },
    body: JSON.stringify({
      message:
        'Answer in exactly two short spoken sentences. What is the capital of France?',
      chatId,
      modelId: '232',
    }),
    signal: AbortSignal.timeout(PIPELINE_BUDGET_MS),
  })
  if (!response.ok || !response.body) {
    const body = await response.text().catch(() => '')
    return {
      ok: false,
      ms: Date.now() - callStarted,
      status: response.status,
      error: redact(body.slice(0, 240)),
      modelId: 232,
    }
  }
  const reader = response.body.getReader()
  const decoder = new TextDecoder()
  let pending = ''
  let text = ''
  let firstTokenMs = null
  let error = null
  while (true) {
    const { done, value } = await reader.read()
    if (done) break
    pending += decoder.decode(value, { stream: true })
    const lines = pending.split('\n')
    pending = lines.pop() ?? ''
    for (const line of lines) {
      if (!line.startsWith('data:')) continue
      let data
      try {
        data = JSON.parse(line.slice(5).trim())
      } catch {
        continue
      }
      if (data.status === 'data' && data.chunk) {
        if (firstTokenMs == null) firstTokenMs = Date.now() - callStarted
        text += String(data.chunk)
      }
      if (data.status === 'error') error = redact(String(data.message ?? data.error ?? 'stream error'))
    }
  }
  const demo = /demo mode/i.test(text)
  return {
    ok: error == null && text.length > 0 && !demo,
    ms: Date.now() - callStarted,
    firstTokenMs,
    status: response.status,
    chars: text.length,
    preview: text.slice(0, 180),
    modelId: 232,
    providerModel: 'gpt-5.4-mini',
    error: error ?? (demo ? 'demo provider answered' : null),
  }
}

async function runOpenAi({ label, host, pcm, brainText }) {
  const result = {
    provider: 'openai',
    label,
    host,
    model: 'gpt-live-1',
    transport: 'websocket',
    storeRequested: false,
  }
  const t0 = Date.now()
  const mark = (key) => {
    if (result[key] == null) result[key] = Date.now() - t0
  }
  let ws
  try {
    ws = await connect(`wss://${host}/v1/live/sessions`, {
      Authorization: `Bearer ${env.OPENAI_API_KEY}`,
      'OpenAI-Safety-Identifier': 'lv0-spike',
    })
    mark('connectedMs')
  } catch (error) {
    result.error = redact(error.message)
    return result
  }

  const events = []
  let delegationId = null
  let commentarySentAt = null
  let resolveDone
  const done = new Promise((resolve) => {
    resolveDone = resolve
  })

  const send = (payload) => ws.send(JSON.stringify(payload))
  const finish = (reason) => {
    result.endReason = reason
    if (ws.readyState === WebSocket.OPEN) {
      try {
        send({ type: 'session.close' })
      } catch {
        ws.terminate()
      }
    }
  }

  ws.on('message', (raw) => {
    let event
    try {
      event = JSON.parse(raw.toString())
    } catch {
      events.push({ type: 'non-json' })
      return
    }
    events.push({ type: event.type, at: Date.now() - t0 })
    if (event.type === 'error') {
      result.providerError = redact(JSON.stringify(event.error ?? event).slice(0, 400))
      if (!result.startedMs) finish('error')
      return
    }
    if (event.type === 'session.started') {
      mark('startedMs')
      result.sessionIdPrefix = String(event.session?.id ?? '').slice(0, 8)
      result.resolvedDelegation = event.session?.delegation?.type ?? null
    }
    if (event.type === 'session.input_transcript.delta') {
      result.heard = ((result.heard ?? '') + String(event.delta ?? '')).slice(0, 180)
    }
    if (event.type === 'session.output_audio.delta') {
      const at = Date.now() - t0
      if (commentarySentAt == null) mark('firstAudioMs')
      else if (at - commentarySentAt > 400) mark('audioAfterCommentaryMs')
      result.audioBytes = (result.audioBytes ?? 0) + Buffer.from(event.delta ?? '', 'base64').length
    }
    if (event.type === 'session.delegation.created' && event.delegation?.target === 'client') {
      mark('delegationMs')
      delegationId = event.delegation.id
    }
    if (event.type === 'session.usage.updated') result.usage = event.usage ?? event
    if (event.type === 'session.closed') {
      result.finalUsage = event.usage ?? null
      result.closed = true
      resolveDone()
    }
  })
  ws.on('close', () => resolveDone())
  ws.on('error', (error) => {
    result.socketError = redact(error.message)
    resolveDone()
  })

  send({
    type: 'session.start',
    event_id: 'lv0-start',
    session: {
      model: 'gpt-live-1',
      store: false,
      instructions:
        'You never answer factual questions yourself. Delegate every question to the client backend, then speak only what that backend returns. Keep the spoken reply to two short sentences.',
      audio: {
        format: { type: 'audio/pcm', rate: 24000 },
        output: { voice: 'marin' },
      },
      delegation: { type: 'client' },
    },
  })

  const started = await Promise.race([
    waitFor(() => result.startedMs != null || result.providerError, 20_000),
    sleep(20_000),
  ])
  if (!result.startedMs) {
    result.error = result.providerError ?? 'session.started did not arrive'
    result.eventTypes = [...new Set(events.map((event) => event.type))]
    ws.terminate()
    return result
  }
  void started

  const paced = await sendPaced(
    (chunk) => send({ type: 'session.input_audio.append', audio: chunk.toString('base64') }),
    pcm,
    24000,
  )
  result.speechEndedMs = paced.speechEnded - t0
  result.audioSentMs = paced.audioSent - t0

  const delegated = await Promise.race([
    waitFor(() => result.delegationMs != null || result.firstAudioMs != null, 25_000),
    sleep(25_000),
  ])
  void delegated

  if (delegationId) {
    send({
      type: 'session.thinking.append',
      event_id: 'lv0-thinking',
      delegation_id: delegationId,
      content: 'Checking your notes.',
    })
    send({
      type: 'session.commentary.append',
      event_id: 'lv0-commentary',
      delegation_id: delegationId,
      content: brainText,
    })
    commentarySentAt = Date.now() - t0
    result.commentarySentMs = commentarySentAt
    // Closing immediately drops the answer: the API reports
    // context_injection_incomplete. Wait for the spoken reply.
    await Promise.race([
      waitFor(() => result.audioAfterCommentaryMs != null, 12_000).then(() => sleep(4_000)),
      sleep(12_000),
    ])
  }

  finish(delegationId ? 'measured' : 'no-delegation')
  await Promise.race([done, sleep(12_000)])
  if (!result.closed) ws.terminate()
  result.wallMs = Date.now() - t0
  result.eventTypes = [...new Set(events.map((event) => event.type))]
  result.storeAccepted = result.startedMs != null && !String(result.providerError ?? '').includes('store')
  return result
}

async function runGemini({ pcm, brainText }) {
  const result = {
    provider: 'google',
    label: 'gemini',
    model: 'gemini-3.8-live',
    transport: 'websocket',
  }
  const t0 = Date.now()
  const mark = (key) => {
    if (result[key] == null) result[key] = Date.now() - t0
  }
  const url =
    'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent?key=' +
    env.GOOGLE_GEMINI_API_KEY
  let ws
  try {
    ws = await connect(url, {})
    mark('connectedMs')
  } catch (error) {
    result.error = redact(error.message)
    return result
  }

  const events = []
  let toolAnswered = false
  let resolveDone
  const done = new Promise((resolve) => {
    resolveDone = resolve
  })
  const send = (payload) => ws.send(JSON.stringify(payload))

  ws.on('message', (raw) => {
    let event
    try {
      event = JSON.parse(raw.toString())
    } catch {
      events.push('non-json')
      return
    }
    const type = Object.keys(event)[0] ?? 'empty'
    events.push(type)
    if (event.setupComplete) mark('startedMs')
    if (event.error) result.providerError = redact(JSON.stringify(event.error).slice(0, 400))
    const parts = event.serverContent?.modelTurn?.parts ?? []
    for (const part of parts) {
      if (part.inlineData?.data) {
        if (!toolAnswered) mark('firstAudioMs')
        else mark('audioAfterToolMs')
        result.audioBytes = (result.audioBytes ?? 0) + Buffer.from(part.inlineData.data, 'base64').length
      }
    }
    if (event.usageMetadata) result.usage = event.usageMetadata
    if (event.toolCall && !toolAnswered) {
      mark('delegationMs')
      toolAnswered = true
      const calls = event.toolCall.functionCalls ?? []
      result.toolNames = calls.map((call) => call.name)
      send({
        toolResponse: {
          functionResponses: calls.map((call) => ({
            id: call.id,
            name: call.name,
            response: { result: brainText, scheduling: 'WHEN_IDLE' },
          })),
        },
      })
      result.toolResponseSentMs = Date.now() - t0
    }
    if (event.goAway) result.goAway = true
  })
  ws.on('close', () => resolveDone())
  ws.on('error', (error) => {
    result.socketError = redact(error.message)
    resolveDone()
  })

  send({
    setup: {
      model: 'models/gemini-3.8-live',
      generationConfig: { responseModalities: ['AUDIO'] },
      systemInstruction: {
        parts: [
          {
            text: 'You never answer factual questions yourself. Always call consult_synaplan and then speak only that result, in two short sentences.',
          },
        ],
      },
      tools: [
        {
          functionDeclarations: [
            {
              name: 'consult_synaplan',
              description: 'Ask Synaplan for the answer to the user question. Required for every factual question.',
              parameters: {
                type: 'OBJECT',
                properties: { question: { type: 'STRING' } },
                required: ['question'],
              },
            },
          ],
        },
      ],
      inputAudioTranscription: {},
      outputAudioTranscription: {},
      realtimeInputConfig: {
        automaticActivityDetection: { endOfSpeechSensitivity: 'END_SENSITIVITY_HIGH' },
      },
    },
  })

  await Promise.race([waitFor(() => result.startedMs != null || result.providerError, 20_000), sleep(20_000)])
  if (!result.startedMs) {
    result.error = result.providerError ?? 'setupComplete did not arrive'
    result.eventTypes = [...new Set(events)]
    ws.close()
    return result
  }

  const paced = await sendPaced(
    (chunk) =>
      send({
        realtimeInput: {
          audio: { mimeType: 'audio/pcm;rate=16000', data: chunk.toString('base64') },
        },
      }),
    pcm,
    16000,
  )
  result.speechEndedMs = paced.speechEnded - t0
  result.audioSentMs = paced.audioSent - t0

  await Promise.race([
    waitFor(() => result.audioAfterToolMs != null || (result.delegationMs == null && result.firstAudioMs != null), 30_000),
    sleep(30_000),
  ])
  if (result.delegationMs != null && result.audioAfterToolMs == null) {
    await Promise.race([waitFor(() => result.audioAfterToolMs != null, 15_000), sleep(15_000)])
  }

  result.wallMs = Date.now() - t0
  result.eventTypes = [...new Set(events)]
  ws.close()
  await Promise.race([done, sleep(5_000)])
  return result
}

function waitFor(predicate, ms) {
  const started = Date.now()
  return new Promise((resolve) => {
    const timer = setInterval(() => {
      if (predicate() || Date.now() - started > ms) {
        clearInterval(timer)
        resolve()
      }
    }, 50)
  })
}

function usd(n) {
  return Math.round(n * 10000) / 10000
}

// Seeded BCOST_BUDGET_MONTHLY per level (SubscriptionPlanSeeder). Live voice
// shares this budget with every other paid action (decision 7).
function minutesPerBudget(usdPerMin) {
  const plans = [
    ['PRO', 10],
    ['TEAM', 30],
    ['BUSINESS', 60],
  ]
  return Object.fromEntries(
    plans.map(([level, budget]) => [
      level,
      { budget, voiceOnlyMinutesNoMarkup: Math.floor(budget / usdPerMin) },
    ]),
  )
}

const only = process.argv.slice(2)
const wanted = (name) => only.length === 0 || only.includes(name)
const previous = (() => {
  try {
    return JSON.parse(readFileSync(join(here, 'results.json'), 'utf8'))
  } catch {
    return {}
  }
})()
const summary = { ...previous, measuredAt: new Date().toISOString(), phrase: PHRASE }

if (wanted('pipeline')) {
  summary.pipeline = await timeMessageProcessor().catch((error) => ({
    ok: false,
    error: redact(error.message),
  }))
}
const brainText =
  summary.pipeline?.ok && !/demo mode/i.test(summary.pipeline.preview ?? '')
    ? summary.pipeline.preview
    : CANNED
summary.brainSource = brainText === CANNED ? 'canned' : 'message-processor'

const pcm24 = wanted('openai-us') || wanted('openai-eu') ? synthesize(24000) : null
const pcm16 = wanted('gemini') ? synthesize(16000) : null
if (wanted('openai-us')) {
  summary.openaiUs = await runOpenAi({ label: 'us', host: 'api.openai.com', pcm: pcm24, brainText })
}
if (wanted('openai-eu')) {
  summary.openaiEu = await runOpenAi({ label: 'eu', host: 'eu.api.openai.com', pcm: pcm24, brainText })
}
if (wanted('gemini')) summary.gemini = await runGemini({ pcm: pcm16, brainText })

const seconds = (summary.openaiUs.finalUsage?.seconds ?? summary.openaiUs.usage?.seconds) || null
summary.listPriceUsdPerMin = { gptLive: 0.05, geminiAudioIn: 0.005, geminiAudioOut: 0.018 }
delete summary.quotasAtListPrice
summary.budgetAtListPrice = {
  gptLive: minutesPerBudget(0.05),
  geminiBothDirections: minutesPerBudget(0.023),
}
summary.openaiReportedSeconds = seconds
void SESSION_BUDGET_MS

const outPath = join(here, 'results.json')
writeFileSync(outPath, JSON.stringify(summary, null, 2))
console.log(JSON.stringify(summary, null, 2))
