import { randomUUID } from 'node:crypto'
import { muxOpusPackets, opusPacketSamples } from './oggOpus.js'

const ROUTE = '/api/v1/plugins/synascriber/transcriber/sessions'

/**
 * Client for the synaScriber plugin's transcriber routes. The API key belongs
 * to the plugin owner; the plugin checks every call against a live session.
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
    return { status: response.status, body }
  }

  return {
    async bind(ref) {
      const { status, body } = await call(`/${encodeURIComponent(ref)}`)
      if (status === 410 || status === 404) {
        return null
      }
      if (status !== 200) {
        throw new Error(body.message || `Synaplan returned ${status}`)
      }
      return body
    },

    async window(ref, { speaker, at, audio }) {
      const query = new URLSearchParams({ speaker, at: String(at) })
      const { status, body } = await call(`/${encodeURIComponent(ref)}/audio?${query}`, {
        method: 'POST',
        headers: { 'Content-Type': 'audio/ogg' },
        body: audio,
      })
      if (status === 410) {
        return { ended: true, text: '' }
      }
      if (status !== 200) {
        throw new Error(body.message || `Synaplan returned ${status}`)
      }
      return { ended: false, text: String(body.text || '').trim() }
    },

    async finish(ref) {
      const { status, body } = await call(`/${encodeURIComponent(ref)}/finish`, { method: 'POST' })
      if (status !== 200) {
        throw new Error(body.message || `Synaplan returned ${status}`)
      }
      return body
    },
  }
}

function caption(tag, text, language) {
  return {
    type: 'transcription-result',
    event: 'transcription-result',
    message_id: randomUUID(),
    is_interim: false,
    transcript: [{ text }],
    participant: { id: tag },
    timestamp: Date.now(),
    language,
  }
}

function mediaTag(message) {
  return message?.media?.tag || message?.start?.tag || message?.stop?.tag || message?.tag || 'speaker'
}

/**
 * One Jitsi meeting bound to one synaScriber session. Each speaker's Opus
 * packets are cut into windows, wrapped as Ogg and sent to the plugin, which
 * transcribes and stores them. Audio never touches the disk here.
 */
/** Leftovers shorter than this (48 kHz samples) are mostly breath and noise; Whisper invents words for them. */
const MIN_FINAL_SAMPLES = 48000

export function createPluginSession({ ref, language, client, commitAfterMs = 8000, now = Date.now }) {
  const commitSamples = Math.round(commitAfterMs * 48)
  const speakers = new Map()
  let ended = false
  let windows = 0
  let failures = 0

  function speaker(tag) {
    let entry = speakers.get(tag)
    if (!entry) {
      entry = { packets: [], samples: 0, startedAt: 0, chain: Promise.resolve() }
      speakers.set(tag, entry)
    }
    return entry
  }

  async function flush(tag, send) {
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
    entry.packets = []
    entry.samples = 0
    entry.startedAt = 0
    try {
      const result = await client.window(ref, { speaker: tag, at, audio: muxOpusPackets(packets) })
      windows += 1
      if (result.ended) {
        ended = true
        return
      }
      if (result.text) {
        send(caption(tag, result.text, language))
      }
    } catch {
      failures += 1
    }
  }

  function enqueue(tag, send) {
    const entry = speaker(tag)
    entry.chain = entry.chain.then(() => flush(tag, send))
    return entry.chain
  }

  return {
    ended: () => ended,
    stats: () => ({ windows, failures, speakers: speakers.size }),

    async handle(message, send) {
      if (!message || typeof message !== 'object') {
        return
      }
      if (message.event === 'ping') {
        send({ event: 'pong', id: message.id })
        return
      }
      if (ended) {
        return
      }
      if (message.event === 'start') {
        speaker(mediaTag(message))
        return
      }
      if (message.event === 'stop') {
        await enqueue(mediaTag(message), send)
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
      if (entry.packets.length === 0) {
        entry.startedAt = now()
      }
      entry.packets.push(packet)
      entry.samples += opusPacketSamples(packet)
      if (entry.samples >= commitSamples) {
        enqueue(tag, send)
      }
    },

    async finish(send) {
      await Promise.all([...speakers.values()].map((entry) => entry.chain))
      for (const [tag, entry] of speakers) {
        if (entry.samples >= MIN_FINAL_SAMPLES) {
          await flush(tag, send)
        }
      }
      return client.finish(ref)
    },
  }
}
