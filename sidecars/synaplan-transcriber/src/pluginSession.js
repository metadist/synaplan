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

    /**
     * Live caption of the window so far. Best effort: anything but 200 means
     * "no caption this time", never the end of the session.
     */
    async preview(ref, fields) {
      const form = new FormData()
      form.append('audio', new Blob([fields.audio], { type: 'audio/ogg' }), 'window.ogg')
      form.append('endpointId', String(fields.endpointId))
      form.append('seq', String(fields.seq))
      form.append('t0Ms', String(fields.t0Ms))
      form.append('t1Ms', String(fields.t1Ms))
      const { status, body } = await call(`/${encodeURIComponent(ref)}/preview`, { method: 'POST', body: form })
      if (status !== 200) {
        return null
      }
      return {
        text: String(body.text || '').trim(),
        dropped: body.dropped ?? null,
        speechEndMs: Number.isFinite(body.speechEndMs) ? body.speechEndMs : null,
        segmentEndsMs: Array.isArray(body.segmentEndsMs) ? body.segmentEndsMs.filter(Number.isFinite) : [],
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

function caption({ sessionId, endpointId, seq, text, language, name, at, interim = false }) {
  const participant = { id: endpointId }
  if (name) {
    participant.name = name
  }
  return {
    type: 'transcription-result',
    event: 'transcription-result',
    message_id: `${sessionId}:${endpointId}:${seq}`,
    is_interim: interim,
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
  previewEveryMs = 1500,
  idlePreviewEveryMs = 3000,
  minPreviewMs = 1000,
  pauseMs = 700,
  silenceDropMs = 4000,
  minSoftCutMs = 1000,
  softCutGuardMs = 300,
  softCutPadMs = 60,
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
  let previews = 0
  let pauseCuts = 0
  let silentDrops = 0
  let softCuts = 0
  let hardCuts = 0
  const inFlight = new Set()
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
        previewing: false,
        lastPreviewAt: 0,
        heard: false,
        interimSeq: 0,
        lastPreview: null,
        cutQueued: false,
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

  /** Removes the first `count` packets of a speaker's window and returns them with their length. */
  function take(entry, count) {
    const packets = entry.packets.splice(0, Math.min(count, entry.packets.length))
    const samples = packets.reduce((sum, packet) => sum + opusPacketSamples(packet), 0)
    const at = entry.startedAt
    entry.samples = Math.max(0, entry.samples - samples)
    entry.startedAt = entry.packets.length > 0 ? at + Math.round(samples / 48) : 0
    return { packets, samples, at }
  }

  /**
   * A window that showed an interim caption but ends without a final one
   * (no text, dropped, failed, captions turned off) gets an empty final
   * under the same message id, so the line never keeps text that is not in
   * the transcript.
   */
  function settleInterim(entry, seq, send) {
    if (entry.interimSeq !== seq) {
      return
    }
    entry.interimSeq = 0
    send(caption({
      sessionId: currentSessionId,
      endpointId: entry.endpointId,
      seq,
      text: '',
      language: meetingLanguage,
      name: roster[entry.endpointId]?.name,
      at: now(),
    }))
  }

  async function flush(tag, send, cut, { limit = Infinity, seq: expected = null } = {}) {
    const entry = speakers.get(tag)
    if (entry) {
      entry.cutQueued = false
    }
    if (expected !== null && entry && entry.seq + 1 !== expected) {
      return
    }
    if (!entry || entry.packets.length === 0 || ended) {
      if (entry) {
        entry.packets = []
        entry.samples = 0
      }
      return
    }
    const { packets, samples, at } = take(entry, limit)
    const endpointId = entry.endpointId || endpointFromTag(tag)
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
      settleInterim(entry, seq, send)
      await reportGap(endpointId, t0Ms, t1Ms)
      return
    }
    const result = outcome.value
    if (result.ended) {
      settleInterim(entry, seq, send)
      ended = true
      return
    }
    windows += 1
    if (!result.text || result.dropped) {
      settleInterim(entry, seq, send)
      return
    }
    entry.text = `${entry.text} ${result.text}`.trim()
    if (!captionsOn) {
      settleInterim(entry, seq, send)
      return
    }
    entry.interimSeq = entry.interimSeq === seq ? 0 : entry.interimSeq
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

  function enqueue(tag, send, cut, options) {
    const entry = speaker(tag)
    if (cut !== 'stop') {
      entry.cutQueued = true
    }
    entry.chain = entry.chain.then(() => flush(tag, send, cut, options)).catch(() => {})
    return entry.chain
  }

  /** How many leading packets of the window fit into `ms`. */
  function packetsWithin(entry, ms) {
    const limit = ms * 48
    let samples = 0
    let count = 0
    for (const packet of entry.packets) {
      samples += opusPacketSamples(packet)
      if (samples > limit) {
        break
      }
      count += 1
    }
    return count
  }

  /**
   * At the length limit, end the window at the last phrase boundary the
   * latest preview reported (cut "soft"), so no word is split across two
   * windows; the rest starts the next one. Without such a boundary it is a
   * hard cut.
   */
  function limitCut(tag, send) {
    const entry = speakers.get(tag)
    const last = entry.lastPreview
    if (last && last.seq === entry.seq + 1) {
      const boundary = [...last.ends].reverse().find((end) => end >= minSoftCutMs && end <= last.durationMs - softCutGuardMs)
      if (boundary !== undefined) {
        const count = packetsWithin(entry, boundary + softCutPadMs)
        if (count > 0 && count < entry.packets.length) {
          softCuts += 1
          enqueue(tag, send, 'soft', { limit: count, seq: last.seq })
          return
        }
      }
    }
    hardCuts += 1
    enqueue(tag, send, 'hard')
  }

  /**
   * While someone speaks, the window so far goes to the plugin's preview
   * route every `previewEveryMs` (less often after silence): the answer is a
   * live caption, shows where speech paused (the window then ends there), and
   * stretches without speech are dropped before they become a window.
   */
  function maybePreview(tag, send) {
    if (previewEveryMs <= 0 || !captionsOn || closing || ended || typeof client.preview !== 'function') {
      return
    }
    const entry = speakers.get(tag)
    if (!entry || entry.previewing || entry.cutQueued || entry.packets.length === 0) {
      return
    }
    const durationMs = Math.round(entry.samples / 48)
    const every = entry.heard ? previewEveryMs : idlePreviewEveryMs
    if (durationMs < minPreviewMs || now() - entry.lastPreviewAt < every) {
      return
    }
    const snap = { seq: entry.seq + 1, count: entry.packets.length, durationMs }
    const t0Ms = Math.max(0, entry.startedAt - origin)
    entry.previewing = true
    entry.lastPreviewAt = now()
    previews += 1
    const work = client.preview(ref, {
      endpointId: entry.endpointId || endpointFromTag(tag),
      seq: snap.seq,
      t0Ms,
      t1Ms: t0Ms + Math.max(1, durationMs),
      audio: muxOpusPackets(entry.packets.slice(0, snap.count)),
    }).then((result) => onPreview(tag, send, snap, result)).catch(() => {}).finally(() => {
      entry.previewing = false
      inFlight.delete(work)
    })
    inFlight.add(work)
  }

  function onPreview(tag, send, snap, result) {
    const entry = speakers.get(tag)
    if (!entry || !result || closing || ended || !captionsOn || entry.seq + 1 !== snap.seq) {
      return
    }
    if (!result.text || result.dropped) {
      entry.heard = false
      if (snap.durationMs >= silenceDropMs && entry.interimSeq !== snap.seq) {
        take(entry, snap.count)
        silentDrops += 1
      }
      return
    }
    entry.heard = true
    entry.interimSeq = snap.seq
    entry.lastPreview = { seq: snap.seq, durationMs: snap.durationMs, ends: result.segmentEndsMs || [] }
    send(caption({
      sessionId: currentSessionId,
      endpointId: entry.endpointId || endpointFromTag(tag),
      seq: snap.seq,
      text: result.text,
      language: meetingLanguage,
      name: roster[entry.endpointId]?.name,
      at: now(),
      interim: true,
    }))
    if (result.speechEndMs !== null && snap.durationMs - result.speechEndMs >= pauseMs) {
      pauseCuts += 1
      enqueue(tag, send, 'pause', { limit: snap.count, seq: snap.seq })
    }
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
      if (entry.interimSeq) {
        settleInterim(entry, entry.interimSeq, send)
      }
    }
    await postLifecycle('session-end')
    await postLifecycle('finished')
    ended = true
    return { state: gaps > 0 ? 'saved_with_gaps' : 'finished', gaps }
  }

  return {
    ended: () => ended,
    stats: () => ({ windows, failures, gaps, speakers: speakers.size, previews, pauseCuts, softCuts, hardCuts, silentDrops }),

    /** Resolves when no preview and no window is in flight (tests, shutdown). */
    async settle() {
      while (inFlight.size > 0) {
        await Promise.all([...inFlight])
      }
      await Promise.all([...speakers.values()].map((entry) => entry.chain))
    },

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
        if (!entry.cutQueued) {
          limitCut(tag, send)
        }
        return
      }
      maybePreview(tag, send)
    },

    finish(send) {
      if (!finishing) {
        finishing = finishOnce(send)
      }
      return finishing
    },
  }
}
