import { createLiveSideband } from './adapters/openai.js'
import { createOpenAiRealtimeAdapter } from './adapters/realtime.js'
import { sign } from './hmac.js'
import { exchangeSdp } from './webrtc.js'

export const AUDIO_BUFFER_LIMIT = 1_000_000

export const CARPLAY_RECONNECT_MS = 20_000

/** CarPlay holds the provider across a short tunnel drop. Every other client closes at once. */
export function disconnectPlan(client) {
  return client === 'carplay' ? 'park' : 'close'
}

const parkedSessions = new Map()

export function shouldDropAudio(bufferedAmount, limit = AUDIO_BUFFER_LIMIT) {
  return bufferedAmount > limit
}

/**
 * One client socket. Redeems the ticket, opens the provider, and relays PCM.
 * Logs carry ids and reasons only — never audio and never transcript text.
 */
export function handleConnection(socket, request, deps) {
  const url = new URL(request.url ?? '/', 'http://localhost')
  const ticket = url.searchParams.get('ticket') ?? ''
  const origin = request.headers?.origin ?? request.headers?.Origin
  if (!originAllowed(origin, deps.allowedOrigins)) {
    socket.close(4403)
    return
  }

  let adapter = null
  let direct = false
  let wantOffer = false
  let offerResolve = null
  const offerPromise = new Promise((resolve) => { offerResolve = resolve })
  let sessionId = null
  let provider = ''
  let clientName = ''
  let ended = false
  let muted = false
  let dropping = false
  let dropped = 0
  let meter = emptyMeter()
  let startedAt = Date.now()
  let lastAudioAt = Date.now()
  let tickTimer = null
  let idleTimer = null
  let wrapTimer = null
  let parkTimer = null
  let clientSocket = socket
  let lastConfig = null
  let adopted = false
  const tickMs = Number(deps.tickSeconds ?? 15) * 1000
  const idleMs = Number(deps.idleSeconds ?? 120) * 1000

  const log = (fields) => {
    deps.log?.({ sessionId, provider, ...fields })
  }

  const send = (payload) => {
    if (clientSocket && clientSocket.readyState === 1) clientSocket.send(JSON.stringify(payload))
  }

  const post = async (path, payload) => {
    const body = JSON.stringify(payload)
    const { timestamp, signature } = sign(deps.secret, body)
    const response = await deps.fetch(`${deps.synaplanUrl}${path}`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-voice-timestamp': timestamp,
        'x-voice-signature': signature,
      },
      body,
    })
    const text = await response.text()
    let json = {}
    if (text) {
      try {
        json = JSON.parse(text)
      } catch {
        json = {}
      }
    }
    return { status: response.status, json }
  }

  const finish = async (reason) => {
    if (ended) return
    ended = true
    clearInterval(tickTimer)
    clearInterval(idleTimer)
    clearTimeout(wrapTimer)
    clearTimeout(parkTimer)
    if (sessionId) parkedSessions.delete(sessionId)
    const latencyMs = Date.now() - startedAt
    try {
      adapter?.close()
    } catch {
      // The provider socket is already gone. The usage row still has to close.
    }
    try {
      await post(`/internal/voice/sessions/${sessionId}/closed`, { reason, meter, latencyMs })
    } catch {
      log({ event: 'close_callback_failed', reason })
    }
    log({ event: 'closed', reason, dropped })
    send({ type: 'ended', reason })
    if (clientSocket && clientSocket.readyState === 1) clientSocket.close(1000)
  }

  const applyDecision = (decision) => {
    if (!decision || typeof decision !== 'object') return
    if (decision.action === 'warn') send({ type: 'warn', secondsLeft: decision.allowedSeconds })
    if (decision.action === 'wrap_up') {
      dropping = true
      send({ type: 'wrap_up' })
      wrapTimer = setTimeout(() => { void finish(decision.reason || 'limit_budget') }, 8000)
    }
    if (decision.action === 'stop') void finish(decision.reason || 'limit_budget')
  }

  const reportUsage = async () => {
    if (!sessionId || ended) return
    const payload = { ...meter }
    if (!(payload.seconds > 0)) payload.seconds = (Date.now() - startedAt) / 1000
    try {
      const result = await post(`/internal/voice/sessions/${sessionId}/usage`, { meter: payload })
      send({ type: 'usage', seconds: payload.seconds, secondsLeft: result.json.allowedSeconds })
      applyDecision(result.json)
    } catch {
      log({ event: 'usage_callback_failed' })
    }
  }

  const ask = async (question, reply) => {
    try {
      const result = await post(`/internal/voice/sessions/${sessionId}/delegate`, { question })
      reply(String(result.json.text ?? ''))
    } catch {
      reply('')
      log({ event: 'delegate_failed' })
    }
  }

  void (async () => {
    let redeemed
    try {
      redeemed = await post('/internal/voice/tickets/redeem', { ticket })
    } catch {
      socket.close(1011)
      return
    }
    if (redeemed.status !== 200) {
      socket.close(4401)
      return
    }
    const config = redeemed.json
    sessionId = config.sessionId
    provider = String(config.provider ?? '')
    clientName = String(config.client ?? '')
    direct = config.transport === 'webrtc'
    lastConfig = config
    const waiting = parkedSessions.get(sessionId)
    if (waiting) {
      adopted = true
      waiting.adopt(socket)
      return
    }
    startedAt = Date.now()
    lastAudioAt = startedAt
    const attach = (next) => {
      adapter = next
      adapter.on('audio', (chunk) => {
        if (direct) return
        lastAudioAt = Date.now()
        if (dropping || shouldDropAudio(clientSocket ? clientSocket.bufferedAmount : 0)) {
          dropped += 1
          return
        }
        if (clientSocket && clientSocket.readyState === 1) clientSocket.send(chunk)
      })
      adapter.on('transcript', (turn) => {
        lastAudioAt = Date.now()
        if (!turn?.final) return
        send({ type: 'transcript', role: turn.role, text: turn.text, final: true })
        void post(`/internal/voice/sessions/${sessionId}/transcript`, {
          turns: [{ role: turn.role, text: turn.text, startMs: 0, endMs: 0 }],
        }).catch(() => log({ event: 'transcript_callback_failed' }))
      })
      adapter.on('delegate', (event) => {
        send({ type: 'state', state: 'thinking' })
        adapter.inject({ kind: 'thinking', text: 'One moment.', delegationId: event.id })
        void ask(event.question, (text) => {
          adapter.inject({ kind: 'commentary', text, delegationId: event.id })
        })
      })
      adapter.on('tool', (event) => {
        send({ type: 'state', state: 'thinking' })
        void ask(event.question, (text) => {
          adapter.inject({ kind: 'tool', id: event.id, name: event.name, text })
        })
      })
      adapter.on('usage', (next) => {
        lastAudioAt = Date.now()
        meter = { ...meter, ...next }
        void reportUsage()
      })
      adapter.on('interrupted', () => send({ type: 'interrupted' }))
      adapter.on('error', () => { void finish('provider_error') })
      adapter.on('closed', () => { void finish('provider_error') })
    }
    try {
      if (direct) {
        wantOffer = true
        send({ type: 'offer_required' })
        const sdp = await Promise.race([
          offerPromise,
          new Promise((_, reject) => setTimeout(() => reject(new Error('offer_timeout')), 15000)),
        ])
        const exchanged = await exchangeSdp({
          fetchImpl: deps.fetch,
          apiKey: deps.openaiApiKey,
          config,
          sdp,
          liveHttpUrl: deps.liveHttpUrl,
          realtimeHttpUrl: deps.realtimeHttpUrl,
          sidebandUrlFor: deps.sidebandUrlFor,
        })
        send({ type: 'answer', sdp: exchanged.answer })
        const sideband = exchanged.dialect === 'realtime'
          ? createOpenAiRealtimeAdapter({
            apiKey: deps.openaiApiKey,
            url: exchanged.sidebandUrl,
            WebSocketImpl: deps.WebSocketImpl,
            omitAudioFormat: true,
          })
          : createLiveSideband({
            apiKey: deps.openaiApiKey,
            url: exchanged.sidebandUrl,
            WebSocketImpl: deps.WebSocketImpl,
          })
        attach(sideband)
        await sideband.open(config)
      } else {
        const relay = deps.adapterFor(provider, String(config.model ?? ''))
        attach(relay)
        await relay.open(config)
      }
    } catch {
      await finish('provider_error')
      return
    }
    send({
      type: 'ready',
      audio: config.audio,
      allowedSeconds: config.allowedSeconds,
    })
    log({ event: 'ready' })
    if (tickMs > 0) tickTimer = setInterval(() => { void reportUsage() }, tickMs)
    if (idleMs > 0) {
      idleTimer = setInterval(() => {
        if (Date.now() - lastAudioAt >= idleMs) void finish('idle')
      }, Math.min(idleMs, 5000))
    }
  })()

  const onMessage = (raw, isBinary) => {
    if (!isBinary && Buffer.isBuffer(raw) && looksLikeJson(raw)) {
      let early
      try {
        early = JSON.parse(raw.toString())
      } catch {
        early = null
      }
      if (wantOffer && early?.type === 'offer') {
        wantOffer = false
        offerResolve(String(early.sdp ?? ''))
        return
      }
    }
    if (ended || !adapter) return
    if (isBinary || (Buffer.isBuffer(raw) && !looksLikeJson(raw))) {
      if (direct || muted || dropping) return
      lastAudioAt = Date.now()
      adapter.sendAudio(raw)
      return
    }
    let message
    try {
      message = JSON.parse(raw.toString())
    } catch {
      return
    }
    if (message.type === 'mute') {
      muted = true
      adapter.mute?.()
    }
    if (message.type === 'unmute') {
      muted = false
      adapter.unmute?.()
    }
    if (message.type === 'interrupt') adapter.interrupt()
    if (message.type === 'end') void finish('user_end')
  }
  socket.on('message', onMessage)

  const onClose = (mine) => {
    if (adopted || ended || clientSocket !== mine) return
    if (!sessionId || disconnectPlan(clientName) !== 'park') {
      if (sessionId) void finish('network_lost')
      return
    }
    clientSocket = null
    clearTimeout(parkTimer)
    parkTimer = setTimeout(() => {
      parkedSessions.delete(sessionId)
      void finish('network_lost')
    }, Number(deps.reconnectGraceMs ?? CARPLAY_RECONNECT_MS))
    parkedSessions.set(sessionId, {
      adopt(next) {
        clearTimeout(parkTimer)
        parkedSessions.delete(sessionId)
        clientSocket = next
        next.on('message', onMessage)
        next.on('close', () => onClose(next))
        send({
          type: 'ready',
          audio: lastConfig?.audio,
          allowedSeconds: lastConfig?.allowedSeconds,
        })
      },
    })
  }
  socket.on('close', () => onClose(socket))
}

function originAllowed(origin, allowed) {
  if (!allowed) return true
  const list = String(allowed).split(',').map((item) => item.trim()).filter(Boolean)
  if (list.length === 0) return true
  return list.includes(origin)
}

function looksLikeJson(raw) {
  const text = raw.toString().trimStart()
  return text.startsWith('{') || text.startsWith('[')
}

function emptyMeter() {
  return {
    seconds: 0,
    audioInTokens: 0,
    audioOutTokens: 0,
    audioCacheTokens: 0,
    textInTokens: 0,
    textOutTokens: 0,
    textItems: 0,
  }
}
