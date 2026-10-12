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
 * OpenAI GPT-Live. Client delegation: the model asks, Synaplan answers,
 * and the spoken reply is commentary. store stays false.
 */
export function createOpenAiAdapter({
  apiKey,
  region = 'us',
  url,
  WebSocketImpl = WebSocket,
} = {}) {
  const host = region === 'eu' ? 'eu.api.openai.com' : 'api.openai.com'
  const socketUrl = url ?? `wss://${host}/v1/live/sessions`
  const listeners = new Map()
  const state = { heard: '' }
  let ws = null
  let lastInjectAt = 0
  let opened = false

  const send = (payload) => {
    if (ws && ws.readyState === WebSocketImpl.OPEN) ws.send(JSON.stringify(payload))
  }

  return {
    on: (event, fn) => listen(listeners, event, fn),
    async open(config) {
      await new Promise((resolve, reject) => {
        ws = new WebSocketImpl(socketUrl, {
          headers: { Authorization: `Bearer ${apiKey ?? ''}` },
        })
        ws.on('error', (error) => {
          if (!opened) reject(error)
          else emit(listeners, 'error', error)
        })
        ws.on('open', () => {
          send({
            type: 'session.start',
            session: {
              model: config.model,
              store: false,
              instructions: config.instructions,
              audio: {
                format: { type: 'audio/pcm', rate: config.audio?.inRate ?? 24000 },
                output: { voice: config.voice || 'marin' },
              },
              delegation: { type: 'client' },
            },
          })
        })
        ws.on('message', (raw) => {
          let event
          try {
            event = JSON.parse(raw.toString())
          } catch {
            return
          }
          if (event.type === 'session.started') {
            opened = true
            resolve()
          }
          applyLiveServerEvent(event, state, (name, payload) => emit(listeners, name, payload))
        })
        ws.on('close', () => {
          if (!opened) reject(new Error('provider_closed'))
          else emit(listeners, 'closed')
        })
      })
    },
    sendAudio(chunk) {
      send({ type: 'session.input_audio.append', audio: Buffer.from(chunk).toString('base64') })
    },
    interrupt() {
      send({ type: 'session.interrupt' })
    },
    inject(payload) {
      lastInjectAt = Date.now()
      if (payload.kind === 'thinking') {
        send({ type: 'session.thinking.append', delegation_id: payload.delegationId, content: payload.text })
        return
      }
      send({ type: 'session.commentary.append', delegation_id: payload.delegationId, content: payload.text })
    },
    async close() {
      const wait = Date.now() - lastInjectAt < 500 ? 500 - (Date.now() - lastInjectAt) : 0
      if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait))
      send({ type: 'session.close' })
      ws?.close()
    },
  }
}

/**
 * Sideband for a Live WebRTC call. The browser already carries the audio.
 * This socket only hears usage, delegation and transcripts.
 */
export function createLiveSideband({ apiKey, url, WebSocketImpl = WebSocket } = {}) {
  const listeners = new Map()
  const state = { heard: '' }
  let ws = null
  let opened = false
  let lastInjectAt = 0

  const send = (payload) => {
    if (ws && ws.readyState === WebSocketImpl.OPEN) ws.send(JSON.stringify(payload))
  }

  return {
    on: (event, fn) => listen(listeners, event, fn),
    async open() {
      await new Promise((resolve, reject) => {
        ws = new WebSocketImpl(url, {
          headers: { Authorization: `Bearer ${apiKey ?? ''}` },
        })
        ws.on('error', (error) => {
          if (!opened) reject(error)
          else emit(listeners, 'error', error)
        })
        ws.on('open', () => {
          opened = true
          resolve()
        })
        ws.on('message', (raw) => {
          let event
          try {
            event = JSON.parse(raw.toString())
          } catch {
            return
          }
          applyLiveServerEvent(event, state, (name, payload) => emit(listeners, name, payload))
        })
        ws.on('close', () => {
          if (!opened) reject(new Error('provider_closed'))
          else emit(listeners, 'closed')
        })
      })
    },
    sendAudio() {},
    interrupt() {
      send({ type: 'session.interrupt' })
    },
    mute() {
      send({ type: 'session.input_audio.mute' })
    },
    unmute() {
      send({ type: 'session.input_audio.unmute' })
    },
    inject(payload) {
      lastInjectAt = Date.now()
      if (payload.kind === 'thinking') {
        send({ type: 'session.thinking.append', delegation_id: payload.delegationId, content: payload.text })
        return
      }
      send({ type: 'session.commentary.append', delegation_id: payload.delegationId, content: payload.text })
    },
    async close() {
      const wait = Date.now() - lastInjectAt < 500 ? 500 - (Date.now() - lastInjectAt) : 0
      if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait))
      send({ type: 'session.close' })
      ws?.close()
    },
  }
}

export function applyLiveServerEvent(event, state, emit) {
  if (event.type === 'session.input_transcript.delta') remember(state, emit, 'user', event.delta)
  if (event.type === 'session.input_transcript.done') {
    if (event.transcript) state.heard = String(event.transcript)
    commit(state, emit, 'user')
  }
  if (event.type === 'session.output_audio.delta' && event.delta) {
    emit('audio', Buffer.from(event.delta, 'base64'))
  }
  if (event.type === 'session.output_transcript.delta') {
    remember(state, emit, 'assistant', event.delta ?? event.transcript)
  }
  if (event.type === 'session.output_transcript.done') {
    if (event.transcript) state.spoken = String(event.transcript)
    commit(state, emit, 'assistant')
  }
  if (event.type === 'session.delegation.created' && event.delegation?.target === 'client') {
    emit('delegate', { id: event.delegation.id, question: state.heard || String(event.delegation.task ?? '') })
  }
  if (event.type === 'session.usage.updated') emit('usage', mapOpenAiUsage(event.usage))
  if (event.type === 'session.interrupted') emit('interrupted')
  if (event.type === 'error') emit('error', new Error('provider_error'))
  if (event.type === 'session.closed') {
    commit(state, emit, 'user')
    commit(state, emit, 'assistant')
    if (event.usage) emit('usage', mapOpenAiUsage(event.usage))
    emit('closed')
  }
}

function remember(state, emit, role, piece) {
  const mine = role === 'user' ? 'heard' : 'spoken'
  const otherKey = role === 'user' ? 'spoken' : 'heard'
  const otherRole = role === 'user' ? 'assistant' : 'user'
  if (state[otherKey]) commit(state, emit, otherRole)
  state[mine] = `${state[mine] ?? ''}${piece ?? ''}`
  if (state[mine] !== '') emit('transcript', { role, text: state[mine], final: false })
}

function commit(state, emit, role) {
  const mine = role === 'user' ? 'heard' : 'spoken'
  const text = String(state[mine] ?? '')
  state[mine] = ''
  if (text !== '') emit('transcript', { role, text, final: true })
}

export function mapOpenAiUsage(usage = {}) {
  return {
    seconds: Number(usage.seconds ?? 0),
    audioInTokens: Number(usage.audio_input_tokens ?? 0),
    audioOutTokens: Number(usage.audio_output_tokens ?? 0),
    audioCacheTokens: Number(usage.audio_cached_tokens ?? 0),
    textInTokens: Number(usage.input_tokens ?? usage.text_input_tokens ?? 0),
    textOutTokens: Number(usage.output_tokens ?? usage.text_output_tokens ?? 0),
  }
}
