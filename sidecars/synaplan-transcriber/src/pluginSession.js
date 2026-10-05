import { createHash } from 'node:crypto'
import { muxOpusPackets, opusPacketSamples } from './oggOpus.js'

const ROUTE = '/api/v1/plugins/synascriber/public/transcriber/sessions'

/** 128 random bits, base64url, no padding. */
const REF_PATTERN = /^[A-Za-z0-9_-]{22}$/

/** Leftovers shorter than this (48 kHz samples) are mostly breath and noise. */
const MIN_FINAL_SAMPLES = 48000

const DEFAULT_RETRY_MS = 60_000
const FINISH_RETRY_MS = 10 * 60_000

export function isSessionRef(ref) {
  return typeof ref === 'string' && REF_PATTERN.test(ref)
}

export function refHash8(ref) {
  return createHash('sha256').update(String(ref)).digest('hex').slice(0, 8)
}

function retryAfterMs(response) {
  const raw = response.headers?.get?.('retry-after')
  if (raw == null || raw === '') {
    return null
  }
  const seconds = Number(raw)
  if (Number.isFinite(seconds)) {
    return Math.max(0, Math.round(seconds * 1000))
  }
  const when = Date.parse(raw)
  if (Number.isFinite(when)) {
    return Math.max(0, when - Date.now())
  }
  return null
}

function httpError(status, body, retryAfter) {
  const error = new Error(body?.message || `Synaplan returned ${status}`)
  error.status = status
  if (retryAfter != null) {
    error.retryAfterMs = retryAfter
  }
  return error
}

/**
 * Client for the synaScriber plugin's public transcriber routes.
 * The bearer token is the transcriber token; the plugin checks every call
 * against a live session.
 */
export function createPluginClient({ baseUrl, apiKey, fetchImpl = fetch }) {
  const root = `${baseUrl.replace(/\/$/, '')}${ROUTE}`

  async function call(path, options = {}) {
    const headers = new Headers(options.headers || {})
    headers.set('Authorization', `Bearer ${apiKey}`)
    const response = await fetchImpl(`${root}${path}`, { ...options, headers })
    let body = {}
    try {
      body = await response.json()
    } catch {
      body = {}
    }
    return { status: response.status, body, retryAfterMs: retryAfterMs(response) }
  }

  return {
    async bind(ref, meetingId) {
      const query = new URLSearchParams({ meetingId: meetingId || '' })
      const { status, body } = await call(`/${encodeURIComponent(ref)}?${query}`)
      if (status === 404 || status === 409 || status === 410) {
        return null
      }
      if (status !== 200) {
        throw httpError(status, body)
      }
      return body
    },

    async window(ref, fields) {
      const form = new FormData()
      form.append('audio', new Blob([fields.audio], { type: 'audio/ogg' }), 'window.ogg')
      form.append('endpointId', String(fields.endpointId))
      form.append('seq', String(fields.seq))
      form.append('t0Ms', String(fields.t0Ms))
      form.append('t1Ms', String(fields.t1Ms))
      form.append('cut', String(fields.cut))
      form.append('prompt', String(fields.prompt || '').slice(0, 500))
      const key = fields.idempotencyKey || `${refHash8(ref)}:${fields.endpointId}:${fields.seq}`
      const { status, body, retryAfterMs: retryAfter } = await call(`/${encodeURIComponent(ref)}/audio`, {
        method: 'POST',
        headers: { 'Idempotency-Key': key },
        body: form,
      })
      if (status === 409 || status === 410) {
        return { ended: true, text: '', dropped: null }
      }
      if (status === 429 || status === 503) {
        throw httpError(status, body, retryAfter)
      }
      if (status !== 200) {
        throw httpError(status, body, retryAfter)
      }
      return {
        ended: false,
        text: String(body.text || '').trim(),
        dropped: body.dropped ?? null,
        language: body.language || '',
        speaker: body.speaker || '',
      }
    },

    async event(ref, payload) {
      const { status, body, retryAfterMs: retryAfter } = await call(`/${encodeURIComponent(ref)}/events`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
      if (status === 204 || status === 200) {
        return
      }
      throw httpError(status, body, retryAfter)
    },
  }
}

function retryable(error) {
  const status = error?.status
  return status === 429 || status === 503 || status == null
}

async function withRetry(work, budgetMs, sleep) {
  let waited = 0
  let lastError = null
  for (;;) {
    try {
      return { ok: true, value: await work() }
    } catch (error) {
      lastError = error
      if (!retryable(error)) {
        return { ok: false, error }
      }
      const pause = Math.max(0, error.retryAfterMs ?? 1000)
      const counted = Math.max(pause, 200)
      if (waited + counted > budgetMs) {
        return { ok: false, error }
      }
      await sleep(pause)
      waited += counted
    }
  }
}

function endpointFromTag(tag) {
  const value = String(tag || 'speaker')
  const dash = value.indexOf('-')
  return dash > 0 ? value.slice(0, dash) : value
}

function endpointOf(message, tag) {
  const custom = message?.start?.customParameters?.endpointId
    || message?.media?.customParameters?.endpointId
    || message?.customParameters?.endpointId
  if (typeof custom === 'string' && custom !== '') {
    return custom
  }
  return endpointFromTag(tag)
}

function mediaTag(message) {
  return message?.media?.tag || message?.start?.tag || message?.stop?.tag || message?.tag || 'speaker'
}

function promptFor(text, glossary) {
  const tail = text.slice(-200)
  const extra = glossary.filter((item) => typeof item === 'string' && item).join(', ')
  if (!extra) {
    return tail.slice(0, 500)
  }
  const room = Math.max(0, 500 - tail.length - (tail ? 1 : 0))
  const gloss = extra.slice(0, room)
  if (!gloss) {
    return tail
  }
  return tail ? `${tail}\n${gloss}` : gloss
}

function caption({ sessionId, endpointId, seq, text, language, name, at }) {
  const participant = { id: endpointId }
  if (name) {
    participant.name = name
  }
  return {
    type: 'transcription-result',
    event: 'transcription-result',
    message_id: `${sessionId}:${endpointId}:${seq}`,
    is_interim: false,
    transcript: [{ text }],
    participant,
    timestamp: at,
    language,
  }
}

/**
 * One Jitsi meeting bound to one synaScriber session. Each speaker's Opus
 * packets are cut into windows, wrapped as Ogg and posted to the plugin.
 * Audio never touches the disk here.
 */
export function createPluginSession({
  ref,
  meetingId = '',
  language = 'en',
  sessionId = '',
  captions = true,
  speakers: initialRoster = {},
  glossary: initialGlossary = [],
  client,
  commitAfterMs = 8000,
  now = Date.now,
  sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  retryForMs = DEFAULT_RETRY_MS,
  finishRetryForMs = FINISH_RETRY_MS,
  refreshEveryMs = 30_000,
}) {
  const commitSamples = Math.round(commitAfterMs * 48)
  const origin = now()
  const speakers = new Map()
  let ended = false
  let closing = false
  let windows = 0
  let failures = 0
  let gaps = 0
  let meetingLanguage = language
  let captionsOn = captions !== false
  let currentSessionId = sessionId
  let roster = initialRoster && typeof initialRoster === 'object' ? initialRoster : {}
  let glossary = Array.isArray(initialGlossary) ? initialGlossary : []
  let stopRefresh = () => {}
  let finishing = null

  function applyBinding(binding) {
    if (binding.language) {
      meetingLanguage = binding.language
    }
    if (typeof binding.captions === 'boolean') {
      captionsOn = binding.captions
    }
    if (binding.sessionId) {
      currentSessionId = binding.sessionId
    }
    if (binding.speakers && typeof binding.speakers === 'object') {
      roster = binding.speakers
    }
    if (Array.isArray(binding.glossary)) {
      glossary = binding.glossary
    }
  }

  function speaker(tag) {
    let entry = speakers.get(tag)
    if (!entry) {
      entry = {
        packets: [],
        samples: 0,
        startedAt: 0,
        chain: Promise.resolve(),
        endpointId: endpointFromTag(tag),
        seq: 0,
        text: '',
      }
      speakers.set(tag, entry)
    }
    return entry
  }

  async function reportGap(endpointId, t0Ms, t1Ms) {
    gaps += 1
    failures += 1
    try {
      await client.event(ref, {
        type: 'stt-failure',
        endpointId,
        at: t1Ms,
        detail: `gap ${t0Ms}-${t1Ms} speech_unavailable`,
      })
    } catch {
      // The window is already counted. Finish still reports that the session ended.
    }
  }

  async function flush(tag, send, cut) {
    const entry = speakers.get(tag)
    if (!entry || entry.packets.length === 0 || ended) {
      if (entry) {
        entry.packets = []
        entry.samples = 0
      }
      return
    }
    const packets = entry.packets
    const at = entry.startedAt
    const samples = entry.samples
    const endpointId = entry.endpointId || endpointFromTag(tag)
    entry.packets = []
    entry.samples = 0
    entry.startedAt = 0
    entry.seq += 1
    const seq = entry.seq
    const t0Ms = Math.max(0, at - origin)
    const t1Ms = t0Ms + Math.max(1, Math.round(samples / 48))
    const audio = muxOpusPackets(packets)
    const prompt = promptFor(entry.text, glossary)
    const outcome = await withRetry(() => client.window(ref, {
      endpointId,
      seq,
      t0Ms,
      t1Ms,
      cut,
      prompt,
      audio,
      idempotencyKey: `${refHash8(ref)}:${endpointId}:${seq}`,
    }), retryForMs, sleep)
    if (!outcome.ok) {
      await reportGap(endpointId, t0Ms, t1Ms)
      return
    }
    const result = outcome.value
    if (result.ended) {
      ended = true
      return
    }
    windows += 1
    if (!result.text || result.dropped) {
      return
    }
    entry.text = `${entry.text} ${result.text}`.trim()
    if (!captionsOn) {
      return
    }
    send(caption({
      sessionId: currentSessionId,
      endpointId,
      seq,
      text: result.text,
      language: meetingLanguage,
      name: roster[endpointId]?.name,
      at: now(),
    }))
  }

  function enqueue(tag, send, cut) {
    const entry = speaker(tag)
    entry.chain = entry.chain.then(() => flush(tag, send, cut)).catch(() => {})
    return entry.chain
  }

  async function postLifecycle(type) {
    const outcome = await withRetry(
      () => client.event(ref, { type, at: now() }),
      finishRetryForMs,
      sleep,
    )
    if (!outcome.ok) {
      throw new Error(outcome.error?.message || `Synaplan did not accept ${type}`)
    }
  }

  async function finishOnce(send) {
    closing = true
    stopRefresh()
    await Promise.all([...speakers.values()].map((entry) => entry.chain))
    for (const [tag, entry] of speakers) {
      if (entry.samples >= MIN_FINAL_SAMPLES) {
        await flush(tag, send, 'stop')
      }
    }
    await postLifecycle('session-end')
    await postLifecycle('finished')
    ended = true
    return { state: gaps > 0 ? 'saved_with_gaps' : 'finished', gaps }
  }

  return {
    ended: () => ended,
    stats: () => ({ windows, failures, gaps, speakers: speakers.size }),

    async connect() {
      await client.event(ref, { type: 'connected', at: now() })
      if (refreshEveryMs > 0) {
        const timer = setInterval(() => {
          client.bind(ref, meetingId).then((binding) => {
            if (!binding) {
              ended = true
              return
            }
            applyBinding(binding)
          }).catch(() => {})
        }, refreshEveryMs)
        if (typeof timer.unref === 'function') {
          timer.unref()
        }
        stopRefresh = () => clearInterval(timer)
      }
    },

    async handle(message, send) {
      if (!message || typeof message !== 'object') {
        return
      }
      if (message.event === 'ping') {
        send({ event: 'pong', id: message.id })
        return
      }
      if (message.event === 'session-end') {
        await this.finish(send)
        return
      }
      if (closing || ended) {
        return
      }
      if (message.event === 'start') {
        const tag = mediaTag(message)
        const entry = speaker(tag)
        entry.endpointId = endpointOf(message, tag)
        await client.event(ref, {
          type: 'speaker-start',
          endpointId: entry.endpointId,
          at: now(),
        }).catch(() => {})
        return
      }
      if (message.event === 'stop') {
        const tag = mediaTag(message)
        const entry = speaker(tag)
        entry.endpointId = endpointOf(message, tag)
        await enqueue(tag, send, 'stop')
        await client.event(ref, {
          type: 'speaker-stop',
          endpointId: entry.endpointId,
          at: now(),
        }).catch(() => {})
        return
      }
      if (message.event !== 'media' || typeof message.media?.payload !== 'string') {
        return
      }
      const packet = Buffer.from(message.media.payload, 'base64')
      if (packet.length === 0) {
        return
      }
      const tag = mediaTag(message)
      const entry = speaker(tag)
      entry.endpointId = endpointOf(message, tag)
      if (entry.packets.length === 0) {
        entry.startedAt = now()
      }
      entry.packets.push(packet)
      entry.samples += opusPacketSamples(packet)
      if (entry.samples >= commitSamples) {
        enqueue(tag, send, 'hard')
      }
    },

    finish(send) {
      if (!finishing) {
        finishing = finishOnce(send)
      }
      return finishing
    },
  }
}
