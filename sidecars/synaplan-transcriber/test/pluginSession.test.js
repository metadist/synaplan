import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { request } from 'node:http'
import test from 'node:test'
import { WebSocket } from 'ws'
import { createPluginClient, createPluginSession, refHash8 } from '../src/pluginSession.js'
import { createApp } from '../src/server.js'

const REF = 'Ab3dEf9hJkLmN0pQrStUvW'
const packet = Buffer.from([0xf8, 0x11, 0x22]).toString('base64')

function media(tag) {
  return { event: 'media', media: { tag, chunk: 1, timestamp: 1, payload: packet } }
}

function key(endpointId, seq) {
  return `${refHash8(REF)}:${endpointId}:${seq}`
}

function fakePlugin({ windowResult = { ended: false, text: 'Guten Morgen', dropped: null } } = {}) {
  const calls = { windows: [], events: [] }
  return {
    calls,
    async bind() {
      return { sessionId: 'ms_1', state: 'running', language: 'de' }
    },
    async window(ref, fields) {
      calls.windows.push({
        ref,
        endpointId: fields.endpointId,
        seq: fields.seq,
        t0Ms: fields.t0Ms,
        t1Ms: fields.t1Ms,
        cut: fields.cut,
        idempotencyKey: fields.idempotencyKey,
        ogg: fields.audio.subarray(0, 4).toString(),
      })
      return windowResult
    },
    async event(ref, payload) {
      calls.events.push({ ref, ...payload })
    },
  }
}

test('plugin mode sends each speaker window to the plugin and returns captions', async () => {
  const plugin = fakePlugin()
  const session = createPluginSession({
    ref: REF,
    language: 'de',
    sessionId: 'ms_1',
    speakers: { ada: { name: 'Ada' } },
    client: plugin,
    commitAfterMs: 1000,
    now: () => 1700000000000,
    refreshEveryMs: 0,
  })
  const out = []
  const send = (message) => out.push(message)

  await session.handle({ event: 'ping', id: 3 }, send)
  await session.handle({
    event: 'start',
    start: { tag: 'ada-1', customParameters: { endpointId: 'ada' } },
  }, send)
  await session.handle(media('ada-1'), send)
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  const result = await session.finish(send)

  assert.deepEqual(out[0], { event: 'pong', id: 3 })
  assert.equal(out[1].transcript[0].text, 'Guten Morgen')
  assert.equal(out[1].participant.id, 'ada')
  assert.equal(out[1].participant.name, 'Ada')
  assert.equal(out[1].message_id, 'ms_1:ada:1')
  assert.equal(out[1].language, 'de')
  assert.deepEqual(plugin.calls.windows, [{
    ref: REF,
    endpointId: 'ada',
    seq: 1,
    t0Ms: 0,
    t1Ms: 20,
    cut: 'stop',
    idempotencyKey: key('ada', 1),
    ogg: 'OggS',
  }])
  assert.deepEqual(plugin.calls.events.map((event) => event.type), [
    'speaker-start',
    'speaker-stop',
    'session-end',
    'finished',
  ])
  assert.equal(result.state, 'finished')
})

test('an ended session stops sending audio', async () => {
  const plugin = fakePlugin({ windowResult: { ended: true, text: '', dropped: null } })
  const session = createPluginSession({
    ref: REF,
    language: 'de',
    client: plugin,
    commitAfterMs: 1000,
    refreshEveryMs: 0,
  })
  await session.handle(media('ada-1'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, () => {})
  await session.handle(media('bea-2'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'bea-2' } }, () => {})

  assert.equal(session.ended(), true)
  assert.equal(plugin.calls.windows.length, 1)
})

test('a busy speech engine is retried, then the window is a timed gap', async () => {
  const events = []
  let attempts = 0
  const keys = []
  const client = {
    async window(_ref, fields) {
      attempts += 1
      keys.push(fields.idempotencyKey)
      const error = new Error('busy')
      error.status = 503
      error.retryAfterMs = 1000
      throw error
    },
    async event(_ref, payload) {
      events.push(payload)
    },
  }
  const session = createPluginSession({
    ref: REF,
    language: 'de',
    client,
    commitAfterMs: 1000,
    now: () => 1700000000000,
    sleep: async () => {},
    retryForMs: 2500,
    refreshEveryMs: 0,
  })
  await session.handle(media('ada-1'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, () => {})
  const result = await session.finish(() => {})

  assert.equal(attempts, 3)
  assert.deepEqual(keys, [key('ada', 1), key('ada', 1), key('ada', 1)])
  assert.equal(events[0].type, 'stt-failure')
  assert.equal(events[0].detail, 'gap 0-20 speech_unavailable')
  assert.equal(events[1].type, 'speaker-stop')
  assert.deepEqual(events.slice(2).map((event) => event.type), ['session-end', 'finished'])
  assert.equal(result.state, 'saved_with_gaps')
  assert.equal(session.stats().gaps, 1)
})

test('one 503 is retried and the caption is kept', async () => {
  let attempts = 0
  const client = {
    async window() {
      attempts += 1
      if (attempts === 1) {
        const error = new Error('busy')
        error.status = 429
        error.retryAfterMs = 500
        throw error
      }
      return { ended: false, text: 'Weiter', dropped: null }
    },
    async event() {},
  }
  const session = createPluginSession({
    ref: REF,
    language: 'de',
    sessionId: 'ms_1',
    client,
    commitAfterMs: 1000,
    now: () => 1700000000000,
    sleep: async () => {},
    retryForMs: 60_000,
    refreshEveryMs: 0,
  })
  const out = []
  await session.handle(media('ada-1'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, (message) => out.push(message))

  assert.equal(attempts, 2)
  assert.equal(out[0].transcript[0].text, 'Weiter')
  assert.equal(out[0].participant.id, 'ada')
})

test('the plugin client uses the public route, meeting id, and multipart audio', async () => {
  const seen = []
  const fetchImpl = async (url, options) => {
    const form = options.body instanceof FormData ? options.body : null
    seen.push({
      url,
      auth: options.headers.get('Authorization'),
      method: options.method || 'GET',
      idempotencyKey: options.headers.get('Idempotency-Key'),
      contentType: options.headers.get('Content-Type'),
      endpointId: form?.get('endpointId') || '',
      seq: form?.get('seq') || '',
      cut: form?.get('cut') || '',
    })
    if (String(url).includes('/audio')) {
      return new Response(JSON.stringify({ error: 'not_active' }), { status: 409 })
    }
    if (String(url).includes('/events')) {
      return new Response(null, { status: 204 })
    }
    return new Response(JSON.stringify({ error: 'not_active' }), { status: 404 })
  }
  const client = createPluginClient({ baseUrl: 'https://synaplan.example/', apiKey: 'sk_test', fetchImpl })

  assert.equal(await client.bind(REF, 'meet-1'), null)
  assert.deepEqual(
    await client.window(REF, {
      endpointId: 'ada',
      seq: 4,
      t0Ms: 0,
      t1Ms: 20,
      cut: 'hard',
      prompt: 'vorher',
      audio: Buffer.from('OggS'),
    }),
    { ended: true, text: '', dropped: null },
  )
  await client.event(REF, { type: 'connected', at: 5 })

  assert.equal(seen[0].url, `https://synaplan.example/api/v1/plugins/synascriber/public/transcriber/sessions/${REF}?meetingId=meet-1`)
  assert.equal(seen[0].auth, 'Bearer sk_test')
  assert.equal(seen[1].method, 'POST')
  assert.equal(seen[1].endpointId, 'ada')
  assert.equal(seen[1].seq, '4')
  assert.equal(seen[1].cut, 'hard')
  assert.equal(seen[1].idempotencyKey, `${refHash8(REF)}:ada:4`)
  assert.equal(seen[1].contentType, null)
  assert.equal(seen[2].url, `https://synaplan.example/api/v1/plugins/synascriber/public/transcriber/sessions/${REF}/events`)
  assert.equal(createHash('sha256').update(REF).digest('hex').slice(0, 8), refHash8(REF))
})

test('503 answers carry Retry-After onto the error', async () => {
  const fetchImpl = async () => new Response(JSON.stringify({ message: 'busy' }), {
    status: 503,
    headers: { 'Retry-After': '2' },
  })
  const client = createPluginClient({ baseUrl: 'https://synaplan.example', apiKey: 'sk_test', fetchImpl })
  await assert.rejects(
    () => client.window(REF, {
      endpointId: 'ada',
      seq: 1,
      t0Ms: 0,
      t1Ms: 20,
      cut: 'hard',
      prompt: '',
      audio: Buffer.from('OggS'),
    }),
    (error) => error.status === 503 && error.retryAfterMs === 2000,
  )
})

function upgrade(path, plugin) {
  const config = {
    mode: 'jitsi',
    jitsi: true,
    element: false,
    authToken: 'tok',
    commitAfterMs: 8000,
    language: 'auto',
  }
  const server = createApp(config, { synaplan: {}, plugin })
  return new Promise((resolve, reject) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address()
      const req = request({
        port,
        host: '127.0.0.1',
        path,
        headers: {
          Connection: 'Upgrade',
          Upgrade: 'websocket',
          Authorization: 'Bearer tok',
          'Sec-WebSocket-Version': '13',
          'Sec-WebSocket-Key': Buffer.from('0123456789abcdef').toString('base64'),
        },
      })
      req.on('response', (res) => {
        server.close()
        resolve(res.statusCode)
      })
      req.on('upgrade', () => {
        server.close()
        resolve(101)
      })
      req.on('error', (error) => {
        server.close()
        reject(error)
      })
      req.end()
    })
  })
}

test('a bridge connection without an active session is refused', async () => {
  const status = await upgrade(`/transcribe?sessionId=m1&notes=${REF}`, {
    async bind() {
      return null
    },
  })
  assert.equal(status, 410)
})

test('a 16-character hex reference is refused', async () => {
  let bound = false
  const status = await upgrade('/transcribe?sessionId=m1&notes=a1b2c3d4e5f60718', {
    async bind() {
      bound = true
      return { language: 'de' }
    },
  })
  assert.equal(status, 400)
  assert.equal(bound, false)
})

test('an accepted bridge reports connected before audio', async () => {
  const seen = []
  let onEvent = () => {}
  const plugin = {
    async bind(ref, meetingId) {
      seen.push({ op: 'bind', ref, meetingId })
      return {
        sessionId: 'ms_1',
        state: 'starting',
        language: 'de',
        captions: true,
        speakers: { ada: { name: 'Ada' } },
      }
    },
    async event(_ref, body) {
      seen.push({ op: 'event', type: body.type })
      onEvent()
    },
    async window() {
      return { ended: false, text: '', dropped: null }
    },
  }
  const config = {
    mode: 'jitsi',
    jitsi: true,
    element: false,
    authToken: 'tok',
    commitAfterMs: 8000,
    language: 'auto',
  }
  const server = createApp(config, { synaplan: {}, plugin })
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address()
  try {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('finished event did not arrive')), 3000)
      const maybeDone = () => {
        if (seen.some((entry) => entry.type === 'finished')) {
          clearTimeout(timer)
          resolve()
        }
      }
      onEvent = maybeDone
      const ws = new WebSocket(`ws://127.0.0.1:${port}/transcribe?sessionId=m1&notes=${REF}`, {
        headers: { Authorization: 'Bearer tok' },
      })
      ws.on('open', () => {
        ws.send(JSON.stringify({ event: 'ping', id: 7 }))
      })
      ws.on('message', () => {
        ws.close()
      })
      ws.on('error', reject)
      ws.on('unexpected-response', (_req, response) => {
        clearTimeout(timer)
        reject(new Error(`upgrade refused with ${response.statusCode}`))
      })
    })
    assert.deepEqual(seen[0], { op: 'bind', ref: REF, meetingId: 'm1' })
    assert.deepEqual(seen[1], { op: 'event', type: 'connected' })
    assert.deepEqual(seen.slice(-2).map((entry) => entry.type), ['session-end', 'finished'])
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
})
