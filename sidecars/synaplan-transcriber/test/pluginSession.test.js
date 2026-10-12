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

function clock(start = 1700000000000) {
  let time = start
  return { now: () => time, tick: (ms) => { time += ms } }
}

/** Feeds `seconds` of 20 ms packets for one speaker, advancing the clock like real time. */
async function talk(session, tag, seconds, time, send) {
  for (let i = 0; i < Math.round(seconds * 50); i++) {
    time.tick(20)
    await session.handle(media(tag), send)
  }
}

function previewPlugin(answer) {
  const plugin = fakePlugin()
  plugin.calls.previews = []
  plugin.preview = async (ref, fields) => {
    plugin.calls.previews.push({ seq: fields.seq, t0Ms: fields.t0Ms, t1Ms: fields.t1Ms, endpointId: fields.endpointId, ogg: fields.audio.subarray(0, 4).toString() })
    return typeof answer === 'function' ? answer(fields) : answer
  }
  return plugin
}

function liveSession(plugin, time, options = {}) {
  return createPluginSession({
    ref: REF,
    language: 'de',
    sessionId: 'ms_1',
    speakers: { ada: { name: 'Ada' } },
    client: plugin,
    commitAfterMs: 8000,
    now: time.now,
    refreshEveryMs: 0,
    ...options,
  })
}

test('live captions: the window so far is previewed and shown as an interim caption', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: 'Guten', dropped: null, speechEndMs: null })
  const session = liveSession(plugin, time)
  const out = []
  const send = (message) => out.push(message)

  await talk(session, 'ada-1', 1.2, time, send)
  await session.settle()
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  await session.finish(send)

  assert.deepEqual(plugin.calls.previews[0], { seq: 1, t0Ms: 20, t1Ms: 1020, endpointId: 'ada', ogg: 'OggS' })
  const interim = out.find((message) => message.is_interim === true)
  const final = out.find((message) => message.is_interim === false)
  assert.equal(interim.transcript[0].text, 'Guten')
  assert.equal(interim.message_id, 'ms_1:ada:1')
  assert.equal(interim.participant.name, 'Ada')
  assert.equal(final.message_id, 'ms_1:ada:1')
  assert.ok(out.indexOf(interim) < out.indexOf(final))
})

test('a pause in the preview ends the window there, the rest starts the next one', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: 'Guten Morgen', dropped: null, speechEndMs: 200 })
  const session = liveSession(plugin, time)
  const send = () => {}

  await talk(session, 'ada-1', 1.5, time, send)
  await session.settle()

  assert.deepEqual(
    plugin.calls.windows.map(({ seq, t0Ms, t1Ms, cut }) => ({ seq, t0Ms, t1Ms, cut })),
    [{ seq: 1, t0Ms: 20, t1Ms: 1020, cut: 'pause' }],
  )
  assert.equal(session.stats().pauseCuts, 1)

  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  await session.settle()
  assert.equal(plugin.calls.windows[1].t0Ms, 1020)
  assert.equal(plugin.calls.windows[1].cut, 'stop')
})

test('stretches without speech are dropped before they become a window', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: '', dropped: 'no_speech', speechEndMs: null })
  const session = liveSession(plugin, time)

  await talk(session, 'ada-1', 9, time, () => {})
  await session.settle()

  assert.equal(plugin.calls.windows.length, 0, 'a listening participant sends no window, not even at the 8 s hard cut')
  assert.equal(session.stats().silentDrops, 1)
  assert.deepEqual(plugin.calls.previews.map((call) => call.t1Ms - call.t0Ms), [1000, 4000, 3000])
})

test('a preview that comes back after its window was sent shows nothing', async () => {
  const time = clock()
  let release
  const plugin = previewPlugin(() => new Promise((resolve) => { release = resolve }))
  const session = liveSession(plugin, time)
  const out = []
  const send = (message) => out.push(message)

  await talk(session, 'ada-1', 1.1, time, send)
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  release({ text: 'zu spät', dropped: null, speechEndMs: null })
  await session.settle()

  assert.equal(plugin.calls.windows.length, 1)
  assert.equal(out.filter((message) => message.is_interim === true).length, 0)
})

test('no previews when live captions are off', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: 'Guten', dropped: null, speechEndMs: null })
  const session = liveSession(plugin, time, { captions: false })

  await talk(session, 'ada-1', 2, time, () => {})
  await session.settle()

  assert.equal(plugin.calls.previews.length, 0)
})

test('the plugin client previews with multipart audio; anything but 200 is no caption', async () => {
  const seen = []
  let status = 200
  const fetchImpl = async (url, options) => {
    seen.push({ url, method: options.method, seq: options.body.get('seq'), endpointId: options.body.get('endpointId') })
    return status === 200
      ? new Response(JSON.stringify({ seq: 2, text: ' Hallo ', dropped: null, speechEndMs: 640, segmentEndsMs: [320, 640, 'x'] }), { status })
      : new Response(JSON.stringify({ error: 'captions_off' }), { status })
  }
  const client = createPluginClient({ baseUrl: 'https://synaplan.example', apiKey: 'sk_test', fetchImpl })
  const fields = { endpointId: 'ada', seq: 2, t0Ms: 0, t1Ms: 1000, audio: Buffer.from('OggS') }

  assert.deepEqual(await client.preview(REF, fields), { text: 'Hallo', dropped: null, speechEndMs: 640, segmentEndsMs: [320, 640] })
  status = 403
  assert.equal(await client.preview(REF, fields), null)
  status = 409
  assert.equal(await client.preview(REF, fields), null)
  assert.equal(seen[0].url, `https://synaplan.example/api/v1/plugins/synascriber/public/transcriber/sessions/${REF}/preview`)
  assert.equal(seen[0].method, 'POST')
  assert.equal(seen[0].seq, '2')
})

test('an interim whose window ends without text is cleared under the same message id', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: 'Guten', dropped: null, speechEndMs: null })
  plugin.window = async () => ({ ended: false, text: '', dropped: 'no_speech' })
  const session = liveSession(plugin, time)
  const out = []
  const send = (message) => out.push(message)

  await talk(session, 'ada-1', 1.2, time, send)
  await session.settle()
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  await session.settle()

  const forWindow = out.filter((message) => message.message_id === 'ms_1:ada:1')
  assert.deepEqual(forWindow.map((message) => [message.is_interim, message.transcript[0].text]), [[true, 'Guten'], [false, '']])
})

test('a window that fails clears its interim and the gap is reported', async () => {
  const time = clock()
  const plugin = previewPlugin({ text: 'Guten', dropped: null, speechEndMs: null })
  plugin.window = async () => {
    const error = new Error('bad window')
    error.status = 422
    throw error
  }
  const session = liveSession(plugin, time)
  const out = []
  const send = (message) => out.push(message)

  await talk(session, 'ada-1', 1.2, time, send)
  await session.settle()
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  await session.settle()

  const last = out.filter((message) => message.message_id === 'ms_1:ada:1').at(-1)
  assert.equal(last.is_interim, false)
  assert.equal(last.transcript[0].text, '')
  assert.ok(plugin.calls.events.some((event) => event.type === 'stt-failure'))
})

test('captions switched off while a preview is in flight: the late answer shows nothing', async () => {
  const time = clock()
  let release
  const plugin = previewPlugin(() => new Promise((resolve) => { release = resolve }))
  plugin.bind = async () => ({ sessionId: 'ms_1', state: 'running', language: 'de', captions: false })
  const session = liveSession(plugin, time, { refreshEveryMs: 5 })
  const out = []
  const send = (message) => out.push(message)

  await session.connect()
  await talk(session, 'ada-1', 1.1, time, send)
  await new Promise((resolve) => setTimeout(resolve, 30))
  release({ text: 'zu spät', dropped: null, speechEndMs: 100 })
  await session.settle()
  await session.finish(send)

  assert.equal(out.filter((message) => message.type === 'transcription-result' && message.is_interim).length, 0)
  assert.equal(plugin.calls.windows.filter((call) => call.cut === 'pause').length, 0)
})

test('a window that already showed text is not dropped as silence by a later empty preview', async () => {
  const time = clock()
  let answers = 0
  const plugin = previewPlugin(() => (answers++ === 0
    ? { text: 'Guten', dropped: null, speechEndMs: null }
    : { text: '', dropped: 'no_speech', speechEndMs: null }))
  const session = liveSession(plugin, time, { idlePreviewEveryMs: 1500 })

  await talk(session, 'ada-1', 5, time, () => {})
  await session.settle()

  assert.equal(session.stats().silentDrops, 0)
})

test('a blank PREVIEW_EVERY_MS keeps the default; only an explicit 0 turns previews off', async () => {
  const { loadConfig } = await import('../src/config.js')
  const base = { SYNAPLAN_URL: 'http://synaplan', SYNAPLAN_API_KEY: 'sk_test', TRANSCRIBER_MODE: 'jitsi', TRANSCRIBER_AUTH_TOKEN: 'secret' }
  assert.equal(loadConfig({ ...base, PREVIEW_EVERY_MS: '' }).previewEveryMs, 1500)
  assert.equal(loadConfig({ ...base, PREVIEW_EVERY_MS: '  ' }).previewEveryMs, 1500)
  assert.equal(loadConfig(base).previewEveryMs, 1500)
  assert.equal(loadConfig({ ...base, PREVIEW_EVERY_MS: '0' }).previewEveryMs, 0)
  assert.throws(() => loadConfig({ ...base, PREVIEW_EVERY_MS: '200' }), /PREVIEW_EVERY_MS/)
})

test('at the length limit the window ends at the last phrase boundary of the latest preview', async () => {
  const time = clock()
  const plugin = previewPlugin((fields) => {
    const ms = fields.t1Ms - fields.t0Ms
    return { text: 'ein langer Satz ohne Pause', dropped: null, speechEndMs: ms, segmentEndsMs: ms >= 2500 ? [1200, ms] : [ms] }
  })
  const session = liveSession(plugin, time, { commitAfterMs: 3000 })

  await talk(session, 'ada-1', 3.2, time, () => {})
  await session.settle()

  const first = plugin.calls.windows[0]
  assert.equal(first.cut, 'soft')
  assert.equal(first.t1Ms - first.t0Ms, 1260, 'cut at the 1200 ms boundary plus a 60 ms pad, not at 3000 ms')
  assert.deepEqual([session.stats().softCuts, session.stats().hardCuts], [1, 0])
})

test('without a phrase boundary the limit is a hard cut', async () => {
  const time = clock()
  const plugin = previewPlugin((fields) => ({ text: 'ohne Grenze', dropped: null, speechEndMs: fields.t1Ms - fields.t0Ms, segmentEndsMs: [fields.t1Ms - fields.t0Ms] }))
  const session = liveSession(plugin, time, { commitAfterMs: 3000 })

  await talk(session, 'ada-1', 3.1, time, () => {})
  await session.settle()

  assert.equal(plugin.calls.windows[0].cut, 'hard')
  assert.deepEqual([session.stats().softCuts, session.stats().hardCuts], [0, 1])
})

test('while a cut waits for a busy speech server, further packets do not queue more cuts', async () => {
  const time = clock()
  const plugin = fakePlugin()
  let releaseFirst
  const first = new Promise((resolve) => { releaseFirst = resolve })
  let calls = 0
  plugin.window = async (ref, fields) => {
    plugin.calls.windows.push({ seq: fields.seq, ms: fields.t1Ms - fields.t0Ms, cut: fields.cut })
    calls += 1
    if (calls === 1) {
      await first
    }
    return { ended: false, text: 'ok', dropped: null }
  }
  const session = liveSession(plugin, time, { commitAfterMs: 1000, previewEveryMs: 0 })

  await talk(session, 'ada-1', 1.0, time, () => {})
  await talk(session, 'ada-1', 1.5, time, () => {})
  releaseFirst()
  await session.settle()

  assert.deepEqual(plugin.calls.windows.map((call) => [call.seq, call.cut]), [[1, 'hard'], [2, 'hard']])
  assert.ok(plugin.calls.windows.every((call) => call.ms >= 500), 'no tiny windows from queued cuts')
})
