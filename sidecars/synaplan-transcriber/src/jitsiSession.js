import { randomUUID } from 'node:crypto'
import { muxOpusPackets, opusPacketSamples } from './oggOpus.js'
import { STT_DOWN } from './matrix.js'

function transcriptionResult(tag, text, language) {
  return {
    type: 'transcription-result',
    event: 'transcription-result',
    message_id: randomUUID(),
    is_interim: false,
    transcript: [{ text }],
    participant: { id: tag || 'speaker' },
    timestamp: Date.now(),
    language: language && language !== 'auto' ? language : 'en',
  }
}

function mediaTag(message) {
  return message?.media?.tag || message?.start?.tag || message?.stop?.tag || message?.tag || 'speaker'
}

/**
 * One Jitsi meeting on one WebSocket. Opus packets are windowed, wrapped
 * as Ogg, and sent to Synaplan. The bridge only ever sees text back.
 */
export function createMeetingSession({
  meetingId,
  synaplan,
  commitAfterMs = 8000,
  language = 'auto',
  model = '',
}) {
  const commitSamples = Math.round(commitAfterMs * 48)
  const speakers = new Map()
  const lines = []
  let failureSent = false
  let hadAudio = false

  function speaker(tag) {
    let entry = speakers.get(tag)
    if (!entry) {
      entry = { packets: [], samples: 0, chain: Promise.resolve() }
      speakers.set(tag, entry)
    }
    return entry
  }

  async function flushNow(tag, send) {
    const entry = speakers.get(tag)
    if (!entry || entry.packets.length === 0) {
      return
    }
    const packets = entry.packets
    entry.packets = []
    entry.samples = 0
    try {
      const audio = muxOpusPackets(packets)
      const result = await synaplan.transcribeOgg({
        meetingId,
        speaker: tag,
        audio,
        model,
        language,
      })
      if (result.text) {
        lines.push({ speaker: tag, text: result.text, language: result.language })
        send(transcriptionResult(tag, result.text, result.language))
      }
    } catch {
      if (!failureSent) {
        failureSent = true
        send(transcriptionResult(tag, STT_DOWN, language))
      }
    }
  }

  function enqueue(tag, send) {
    const entry = speaker(tag)
    entry.chain = entry.chain.then(() => flushNow(tag, send))
    return entry.chain
  }

  return {
    lines: () => lines.slice(),
    failed: () => failureSent,
    hadAudio: () => hadAudio,

    async handle(message, send) {
      if (!message || typeof message !== 'object') {
        return
      }
      if (message.event === 'ping') {
        send({ event: 'pong', id: message.id })
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
      const tag = mediaTag(message)
      const packet = Buffer.from(message.media.payload, 'base64')
      if (packet.length === 0) {
        return
      }
      hadAudio = true
      const entry = speaker(tag)
      entry.packets.push(packet)
      entry.samples += opusPacketSamples(packet)
      if (entry.samples >= commitSamples) {
        enqueue(tag, send)
      }
    },

    async finish(send) {
      await Promise.all([...speakers.values()].map((entry) => entry.chain))
      for (const tag of speakers.keys()) {
        await flushNow(tag, send)
      }
      return { lines: lines.slice(), failed: failureSent, hadAudio }
    },
  }
}
