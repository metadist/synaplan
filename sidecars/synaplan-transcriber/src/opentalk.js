import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto'
import { WebSocket } from 'ws'

const SAMPLE_RATE = 16000
const FLUSH_SAMPLES = SAMPLE_RATE * 4
const SILENCE_RMS = 400
const SILENCE_FLUSH_SAMPLES = Math.round(SAMPLE_RATE * 0.8)

/**
 * HS256 token in the shape OpenTalk's service keys use: `kid` is the key
 * id, the body is only `iat` and `exp`, and it is valid for 60 seconds.
 */
export function signServiceToken(key, now = Date.now()) {
  const header = {
    alg: 'HS256',
    kid: key.id,
    nonce: randomBytes(12).toString('base64url').replace(/[^A-Za-z0-9]/g, 'a').slice(0, 16),
  }
  const issued = Math.floor(now / 1000)
  const body = { iat: issued, exp: issued + 60 }
  const data = `${b64url(JSON.stringify(header))}.${b64url(JSON.stringify(body))}`
  return `${data}.${createHmac('sha256', key.secret).update(data).digest('base64url')}`
}

/** True when the token was signed with this key and has not expired. */
export function tokenMatches(token, key, now = Date.now()) {
  const parts = String(token || '').split('.')
  if (parts.length !== 3) {
    return false
  }
  let header
  let body
  try {
    header = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'))
    body = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'))
  } catch {
    return false
  }
  if (header?.alg !== 'HS256' || header?.kid !== key.id) {
    return false
  }
  const expected = createHmac('sha256', key.secret).update(`${parts[0]}.${parts[1]}`).digest()
  const given = Buffer.from(parts[2], 'base64url')
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
    return false
  }
  return Number(body?.exp) * 1000 > now
}

export function wavFromPcm(samples, sampleRate = SAMPLE_RATE) {
  const data = Buffer.from(samples.buffer, samples.byteOffset, samples.byteLength)
  const header = Buffer.alloc(44)
  header.write('RIFF', 0)
  header.writeUInt32LE(36 + data.length, 4)
  header.write('WAVE', 8)
  header.write('fmt ', 12)
  header.writeUInt32LE(16, 16)
  header.writeUInt16LE(1, 20)
  header.writeUInt16LE(1, 22)
  header.writeUInt32LE(sampleRate, 24)
  header.writeUInt32LE(sampleRate * 2, 28)
  header.writeUInt16LE(2, 32)
  header.writeUInt16LE(16, 34)
  header.write('data', 36)
  header.writeUInt32LE(data.length, 40)
  return Buffer.concat([header, data])
}

function b64url(text) {
  return Buffer.from(text).toString('base64url')
}

function rms(samples) {
  if (samples.length === 0) {
    return 0
  }
  let sum = 0
  for (const sample of samples) {
    sum += sample * sample
  }
  return Math.sqrt(sum / samples.length)
}

function send(socket, message) {
  const body = JSON.stringify(message)
  const write = () => {
    if (socket.readyState === 1) {
      socket.send(body)
    }
  }
  if (socket.readyState === 1) {
    write()
    return
  }
  socket.once?.('open', write)
}

/**
 * One OpenTalk meeting. The room server calls `accept`; this joins the
 * meeting, subscribes to each microphone and posts short WAV windows to
 * the plugin. The plugin's text goes back as a subtitle segment.
 */
export function createOpenTalkBridge({
  controllerUrl,
  serviceKey,
  initKey,
  plugin,
  livekitUrl = '',
  connectLiveKit,
  openSocket = (url) => new WebSocket(url),
  fetchImpl = fetch,
  log = () => {},
  now = Date.now,
}) {
  const meetings = new Map()

  async function accept(authorization, body) {
    const token = authorization.startsWith('Bearer ') ? authorization.slice(7) : ''
    if (!tokenMatches(token, initKey, now())) {
      return { status: 401, body: { error: 'not_allowed', message: 'The transcription key was not accepted.' } }
    }
    const room = String(body?.room_id || '')
    if (!/^[A-Za-z0-9_-]{1,80}$/.test(room)) {
      return { status: 422, body: { error: 'invalid_room', message: 'This is not a meeting this service can join.' } }
    }
    if (meetings.has(room)) {
      return { status: 204, body: {} }
    }
    let session
    try {
      session = await plugin.create(room, body?.language || '')
    } catch (error) {
      log(`OpenTalk ${room}: the plugin did not open a session (${error.message}).`)
      return { status: 503, body: { error: 'not_ready', message: 'Subtitles could not be started.' } }
    }
    const meeting = startMeeting({ room, language: session.language || body?.language || 'de', ref: session.ref })
    meetings.set(room, meeting)
    try {
      await meeting.join()
    } catch (error) {
      meetings.delete(room)
      log(`OpenTalk ${room}: ${error.message}`)
      return { status: 502, body: { error: 'not_joined', message: 'The meeting could not be joined.' } }
    }
    return { status: 204, body: {} }
  }

  function startMeeting({ room, language, ref }) {
    const speakers = new Map()
    let socket
    let media = null
    let stopped = false
    let sequence = 0
    const startedAt = now()

    function speaker(id) {
      let entry = speakers.get(id)
      if (!entry) {
        entry = { samples: [], seq: 0, from: now() }
        speakers.set(id, entry)
      }
      return entry
    }

    async function flush(id, cut) {
      const entry = speakers.get(id)
      if (!entry || entry.samples.length === 0) {
        return
      }
      const samples = Int16Array.from(entry.samples)
      entry.samples = []
      log(`OpenTalk ${room}: ${cut} window from ${id}, ${(samples.length / SAMPLE_RATE).toFixed(1)}s.`)
      const from = entry.from
      entry.from = now()
      entry.seq += 1
      const t0 = Math.max(0, from - startedAt)
      const t1 = Math.max(t0 + 1, now() - startedAt)
      let text = ''
      try {
        const result = await plugin.window(ref, {
          endpointId: id,
          seq: entry.seq,
          t0Ms: t0,
          t1Ms: t1,
          cut,
          audio: wavFromPcm(samples),
        })
        text = result.text || ''
        log(text ? `OpenTalk ${room}: ${text}` : `OpenTalk ${room}: the window had no words.`)
      } catch (error) {
        log(`OpenTalk ${room}: a window was not recognised (${error.message}).`)
      }
      if (text && socket) {
        sequence += 1
        send(socket, {
          namespace: 'transcription',
          payload: {
            action: 'transcription_service_event',
            event: {
              kind: 'segment',
              participant_id: id,
              track_id: `mic-${id}`,
              starts_at: new Date(from).toISOString(),
              ends_at: new Date(entry.from).toISOString(),
              text,
            },
          },
        })
      }
    }

    function hear(id, frame) {
      // LiveKit names an OpenTalk participant "<id>:<connection>".
      const speakerId = String(id || '').split(':')[0]
      if (stopped || !/^[A-Za-z0-9_-]{1,80}$/.test(speakerId)) {
        return
      }
      const entry = speaker(speakerId)
      if (rms(frame) < SILENCE_RMS) {
        if (entry.samples.length >= SILENCE_FLUSH_SAMPLES) {
          flush(speakerId, 'pause').catch(() => {})
        }
        return
      }
      for (const sample of frame) {
        entry.samples.push(sample)
      }
      if (entry.samples.length >= FLUSH_SAMPLES) {
        flush(speakerId, 'hard').catch(() => {})
      }
    }

    async function finish(reason) {
      if (stopped) {
        return
      }
      stopped = true
      for (const id of speakers.keys()) {
        await flush(id, 'stop')
      }
      if (socket) {
        send(socket, {
          namespace: 'transcription',
          payload: { action: 'transcription_service_event', event: { kind: 'stopped' } },
        })
        socket.close()
      }
      if (media) {
        await media.close().catch(() => {})
      }
      await plugin.event(ref, { type: 'finished', at: now() }).catch(() => {})
      meetings.delete(room)
      log(`OpenTalk ${room}: subtitles stopped (${reason}) after ${sequence} lines.`)
    }

    async function onMessage(raw) {
      let message
      try {
        message = JSON.parse(raw.toString())
      } catch {
        return
      }
      const payload = message?.payload || {}
      if (message?.namespace === 'core' && payload.message === 'error') {
        log(`OpenTalk ${room}: the meeting refused a step (${payload.error || 'unknown'}).`)
      }
      if (message?.namespace === 'core' && payload.message === 'join_success') {
        log(`OpenTalk ${room}: in the meeting.`)
      }
      if (message?.namespace === 'transcription' && payload.message === 'service_command') {
        if (payload.command?.kind === 'stop') {
          await finish('stopped')
        }
        if (payload.command?.kind === 'change_language' && payload.command.language) {
          language = String(payload.command.language)
          send(socket, {
            namespace: 'transcription',
            payload: { action: 'transcription_service_event', event: { kind: 'updated', language } },
          })
        }
      }
      if (message?.namespace === 'livekit' && payload.message === 'popout_stream_access_token' && payload.token) {
        media = await connectLiveKit({ url: payload.service_url || payload.public_url || livekitUrl, token: payload.token, onFrame: hear, log })
      }
      if (message?.namespace === 'livekit' && payload.message === 'credentials' && payload.token && !media) {
        media = await connectLiveKit({
          url: payload.service_url || payload.public_url,
          token: payload.token,
          onFrame: hear,
          log,
        })
      }
    }

    return {
      hear,
      async join() {
        const start = await fetchImpl(`${controllerUrl}/internal/transcription/start`, {
          method: 'POST',
          headers: {
            authorization: `Bearer ${signServiceToken(serviceKey, now())}`,
            'content-type': 'application/json',
          },
          body: JSON.stringify({ room_id: room, breakout_room: null }),
        })
        const text = await start.text()
        if (start.status < 200 || start.status >= 300) {
          throw new Error(`the meeting did not admit subtitles (${start.status})`)
        }
        const admitted = JSON.parse(text)
        const address = String(admitted.roomserver_address || '').replace(/^http/, 'ws')
        socket = openSocket(new URL(`v1/signaling/${admitted.token}`, address).toString())
        await new Promise((resolve, reject) => {
          const timer = setTimeout(() => reject(new Error('the meeting did not answer')), 5000)
          socket.on('message', (data) => {
            onMessage(data).catch((error) => log(`OpenTalk ${room}: ${error.message}`))
          })
          socket.on('close', () => finish('disconnected').catch(() => {}))
          socket.on('error', () => {})
          const wait = (data) => {
            let message
            try {
              message = JSON.parse(data.toString())
            } catch {
              return
            }
            if (message?.payload?.message === 'joined_lobby') {
              clearTimeout(timer)
              socket.removeListener('message', wait)
              const canEnter = message.payload.can_enter !== false
              log(`OpenTalk ${room}: in the lobby (can enter: ${canEnter}, socket ${socket.readyState}).`)
              if (canEnter) {
                send(socket, { namespace: 'core', payload: { action: 'enter_room', display_name: 'Synaplan' } })
                send(socket, { namespace: 'livekit', payload: { action: 'request_popout_stream_access_token' } })
                send(socket, {
                  namespace: 'transcription',
                  payload: { action: 'transcription_service_event', event: { kind: 'started', language } },
                })
              }
              resolve()
            }
          }
          socket.on('message', wait)
        })
      },
    }
  }

  return { accept }
}
