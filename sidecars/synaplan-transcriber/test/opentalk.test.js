import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import test from 'node:test'
import { createOpenTalkBridge, signServiceToken, tokenMatches, wavFromPcm } from '../src/opentalk.js'

const key = { id: 'transcription', secret: 'secret' }
const serviceKey = { id: 'controller', secret: 'secret' }

test('a service token verifies only for its own key and only while it is fresh', () => {
  const now = 1_700_000_000_000
  const token = signServiceToken(key, now)
  assert.equal(tokenMatches(token, key, now + 1000), true)
  assert.equal(tokenMatches(token, key, now + 61_000), false)
  assert.equal(tokenMatches(token, { id: 'other', secret: 'secret' }, now + 1000), false)
  assert.equal(tokenMatches(`${token}x`, key, now + 1000), false)
})

test('pcm becomes a wav window a speech server accepts', () => {
  const wav = wavFromPcm(Int16Array.from([0, 1000, -1000]))
  assert.equal(wav.subarray(0, 4).toString('ascii'), 'RIFF')
  assert.equal(wav.subarray(8, 12).toString('ascii'), 'WAVE')
  assert.equal(wav.length, 44 + 6)
})

function socketPair() {
  const sockets = []
  return {
    sockets,
    openSocket() {
      const socket = new EventEmitter()
      socket.readyState = 1
      socket.sent = []
      socket.send = (data) => socket.sent.push(JSON.parse(data))
      socket.close = () => {
        socket.readyState = 3
      }
      socket.removeListener = EventEmitter.prototype.removeListener
      sockets.push(socket)
      queueMicrotask(() => {
        socket.emit('message', JSON.stringify({ namespace: 'core', payload: { message: 'joined_lobby' } }))
      })
      return socket
    },
  }
}

test('a signed init joins the meeting and a spoken window becomes a subtitle', async () => {
  const windows = []
  const events = []
  const media = []
  const { sockets, openSocket } = socketPair()
  const bridge = createOpenTalkBridge({
    controllerUrl: 'http://controller',
    serviceKey,
    initKey: key,
    livekitUrl: 'http://livekit:7880',
    now: () => 1_700_000_000_000,
    plugin: {
      async create(room, language) {
        assert.equal(room, 'room-1')
        assert.equal(language, 'de')
        return { ref: 'Ab3dEf9hJkLmN0pQrStUvW', language: 'de' }
      },
      async window(ref, fields) {
        windows.push({ ref, ...fields, audio: fields.audio.subarray(0, 4).toString('ascii') })
        return { text: 'Guten Tag' }
      },
      async event(ref, payload) {
        events.push({ ref, ...payload })
      },
    },
    async connectLiveKit(options) {
      media.push(options)
      return { async close() {} }
    },
    openSocket,
    async fetchImpl(url, options) {
      assert.equal(url, 'http://controller/internal/transcription/start')
      assert.match(options.headers.authorization, /^Bearer /)
      return { status: 200, async text() { return JSON.stringify({ token: 'admit', roomserver_address: 'http://room/' }) } }
    },
  })

  const denied = await bridge.accept('', { room_id: 'room-1', language: 'de' })
  assert.equal(denied.status, 401)

  const accepted = await bridge.accept(`Bearer ${signServiceToken(key, 1_700_000_000_000)}`, { room_id: 'room-1', language: 'de' })
  assert.equal(accepted.status, 204)
  const socket = sockets[0]
  assert.deepEqual(socket.sent.map((message) => message.payload.event?.kind || message.payload.action), [
    'enter_room',
    'request_popout_stream_access_token',
    'started',
  ])

  socket.emit('message', JSON.stringify({
    namespace: 'livekit',
    payload: { message: 'popout_stream_access_token', token: 'lk-token' },
  }))
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(media[0].url, 'http://livekit:7880')
  assert.equal(media[0].token, 'lk-token')

  const loud = new Int16Array(16000 * 4).fill(8000)
  media[0].onFrame('speaker-1', loud)
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(windows.length, 1)
  assert.equal(windows[0].audio, 'RIFF')
  assert.equal(windows[0].endpointId, 'speaker-1')
  assert.equal(socket.sent.at(-1).payload.event.text, 'Guten Tag')

  media[0].onFrame('ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10:connection', loud)
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(socket.sent.at(-1).payload.event.participant_id, 'ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10')

  socket.emit('message', JSON.stringify({
    namespace: 'transcription',
    payload: { message: 'service_command', command: { kind: 'stop' } },
  }))
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(events.at(-1).type, 'finished')
  assert.equal(socket.sent.at(-1).payload.event.kind, 'stopped')
})
