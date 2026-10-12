import WebSocket from 'ws'

function listen(map, event, fn) {
  const list = map.get(event) ?? []
  list.push(fn)
  map.set(event, list)
}

function emit(map, event, payload) {
  for (const fn of map.get(event) ?? []) fn(payload)
}

/**
 * OpenAI Realtime dialect, shared by GPT-Realtime and xAI Grok Voice.
 * Facts go through consult_synaplan. Token usage is accumulated across
 * responses. xAI does not report tokens; it counts text items instead and
 * leaves the session length to the gateway clock.
 */
export function createRealtimeAdapter({
  apiKey,
  url,
  WebSocketImpl = WebSocket,
  countTextItems = false,
  omitAudioFormat = false,
} = {}) {
  const listeners = new Map()
  let ws = null
  let opened = false
  let textItems = 0
  const seenItems = new Set()
  const pendingInput = new Map()
  let totals = emptyUsage()

  const send = (payload) => {
    if (ws && ws.readyState === WebSocketImpl.OPEN) ws.send(JSON.stringify(payload))
  }

  const noteTextItem = (item) => {
    if (!countTextItems || !item || item.type !== 'message') return
    const id = String(item.id ?? '')
    if (id !== '' && seenItems.has(id)) return
    if (id !== '') seenItems.add(id)
    textItems += 1
    emit(listeners, 'usage', { textItems })
  }

  const addUsage = (usage) => {
    if (countTextItems || !usage) return
    const next = mapRealtimeUsage(usage)
    totals = {
      audioInTokens: totals.audioInTokens + next.audioInTokens,
      audioOutTokens: totals.audioOutTokens + next.audioOutTokens,
      audioCacheTokens: totals.audioCacheTokens + next.audioCacheTokens,
      textInTokens: totals.textInTokens + next.textInTokens,
      textOutTokens: totals.textOutTokens + next.textOutTokens,
    }
    emit(listeners, 'usage', { ...totals })
  }

  return {
    on: (event, fn) => listen(listeners, event, fn),
    async open(config) {
      const socketUrl = typeof url === 'function'
        ? url(config)
        : (url ?? `wss://api.openai.com/v1/realtime?model=${encodeURIComponent(config.model ?? '')}`)
      await new Promise((resolve, reject) => {
        ws = new WebSocketImpl(socketUrl, {
          headers: { Authorization: `Bearer ${apiKey ?? ''}` },
        })
        ws.on('error', (error) => {
          if (!opened) reject(error)
          else emit(listeners, 'error', error)
        })
        ws.on('open', () => send(sessionUpdate(config, { omitAudioFormat })))
        ws.on('message', (raw) => {
          let event
          try {
            event = JSON.parse(raw.toString())
          } catch {
            return
          }
          if (event.type === 'session.updated') {
            if (!opened) {
              opened = true
              resolve()
            }
          }
          const audio = event.delta && (event.type === 'response.output_audio.delta' || event.type === 'response.audio.delta')
          if (audio) emit(listeners, 'audio', Buffer.from(event.delta, 'base64'))
          if (event.type === 'conversation.item.input_audio_transcription.updated') {
            pendingInput.set(String(event.item_id ?? 'input'), String(event.transcript ?? ''))
          }
          if (event.type === 'conversation.item.input_audio_transcription.completed') {
            emit(listeners, 'transcript', { role: 'user', text: String(event.transcript ?? ''), final: true })
          }
          if (event.type === 'conversation.item.done' || event.type === 'conversation.item.added' || event.type === 'conversation.item.created') {
            const item = event.item ?? {}
            noteTextItem(item)
            const pending = pendingInput.get(String(item.id ?? event.item_id ?? ''))
            if (event.type === 'conversation.item.done' && pending) {
              emit(listeners, 'transcript', { role: 'user', text: pending, final: true })
            }
          }
          if (event.type === 'response.output_audio_transcript.done' || event.type === 'response.audio_transcript.done') {
            emit(listeners, 'transcript', { role: 'assistant', text: String(event.transcript ?? ''), final: true })
          }
          if (event.type === 'response.function_call_arguments.done' && event.name === 'consult_synaplan') {
            emit(listeners, 'tool', {
              id: String(event.call_id ?? ''),
              name: 'consult_synaplan',
              question: questionFrom(event.arguments),
            })
          }
          if (event.type === 'response.done') addUsage(event.response?.usage)
          if (event.type === 'error') emit(listeners, 'error', new Error('provider_error'))
        })
        ws.on('close', () => {
          if (!opened) reject(new Error('provider_closed'))
          else emit(listeners, 'closed')
        })
      })
    },
    sendAudio(chunk) {
      send({ type: 'input_audio_buffer.append', audio: Buffer.from(chunk).toString('base64') })
    },
    interrupt() {
      send({ type: 'response.cancel' })
    },
    inject(payload) {
      if (payload.kind !== 'tool') return
      send({
        type: 'conversation.item.create',
        item: {
          type: 'function_call_output',
          call_id: payload.id,
          output: String(payload.text ?? ''),
        },
      })
      send({ type: 'response.create' })
    },
    close() {
      ws?.close()
    },
  }
}

export function createOpenAiRealtimeAdapter(options = {}) {
  return createRealtimeAdapter(options)
}

export function mapRealtimeUsage(usage = {}) {
  const input = usage.input_token_details ?? {}
  const output = usage.output_token_details ?? {}
  const cached = input.cached_tokens_details ?? {}
  const audioCache = Number(cached.audio_tokens ?? 0)
  const audioIn = Number(input.audio_tokens ?? 0)
  return {
    audioInTokens: Math.max(0, audioIn - audioCache),
    audioOutTokens: Number(output.audio_tokens ?? 0),
    audioCacheTokens: audioCache,
    textInTokens: Number(input.text_tokens ?? 0),
    textOutTokens: Number(output.text_tokens ?? 0),
  }
}

export function realtimeSessionPayload(config, options = {}) {
  const omitAudioFormat = options.omitAudioFormat === true
  const output = {}
  if (!omitAudioFormat) output.format = { type: 'audio/pcm', rate: config.audio?.outRate ?? 24000 }
  if (config.voice) output.voice = config.voice
  const input = {
    transcription: { model: 'gpt-4o-mini-transcribe' },
    turn_detection: { type: 'server_vad' },
  }
  if (!omitAudioFormat) input.format = { type: 'audio/pcm', rate: config.audio?.inRate ?? 24000 }
  return {
    type: 'realtime',
    model: config.model,
    instructions: config.instructions,
    tools: [{
      type: 'function',
      name: 'consult_synaplan',
      description: 'Ask Synaplan for the answer. Required for every factual question.',
      parameters: {
        type: 'object',
        properties: { question: { type: 'string' } },
        required: ['question'],
      },
    }],
    tool_choice: 'auto',
    audio: { input, output },
  }
}

function sessionUpdate(config, options) {
  return { type: 'session.update', session: realtimeSessionPayload(config, options) }
}

function questionFrom(raw) {
  try {
    const parsed = JSON.parse(String(raw ?? ''))
    return String(parsed.question ?? '')
  } catch {
    return ''
  }
}

function emptyUsage() {
  return {
    audioInTokens: 0,
    audioOutTokens: 0,
    audioCacheTokens: 0,
    textInTokens: 0,
    textOutTokens: 0,
  }
}
