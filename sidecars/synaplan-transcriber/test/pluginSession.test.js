import assert from 'node:assert/strict'
import { request } from 'node:http'
import test from 'node:test'
import { createPluginClient, createPluginSession } from '../src/pluginSession.js'
import { createApp } from '../src/server.js'

const packet = Buffer.from([0xf8, 0x11, 0x22]).toString('base64')

function media(tag) {
  return { event: 'media', media: { tag, chunk: 1, timestamp: 1, payload: packet } }
}

function fakePlugin({ windowResult = { ended: false, text: 'Guten Morgen' } } = {}) {
  const calls = { windows: [], finish: 0 }
  return {
    calls,
    async bind() {
      return { id: 'a1b2c3d4e5f60718', state: 'running', language: 'de' }
    },
    async window(ref, { speaker, at, audio }) {
      calls.windows.push({ ref, speaker, at, ogg: audio.subarray(0, 4).toString() })
      return windowResult
    },
    async finish(ref) {
      calls.finish += 1
      return { id: ref, state: 'saved' }
    },
  }
}

test('plugin mode sends each speaker window to the plugin and returns captions', async () => {
  const plugin = fakePlugin()
  const session = createPluginSession({ ref: 'a1b2c3d4e5f60718', language: 'de', client: plugin, commitAfterMs: 1000, now: () => 1700000000000 })
  const out = []
  const send = (message) => out.push(message)

  await session.handle({ event: 'ping', id: 3 }, send)
  await session.handle(media('ada-1'), send)
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, send)
  const result = await session.finish(send)

  assert.deepEqual(out[0], { event: 'pong', id: 3 })
  assert.equal(out[1].transcript[0].text, 'Guten Morgen')
  assert.equal(out[1].participant.id, 'ada-1')
  assert.equal(out[1].language, 'de')
  assert.deepEqual(plugin.calls.windows, [{ ref: 'a1b2c3d4e5f60718', speaker: 'ada-1', at: 1700000000000, ogg: 'OggS' }])
  assert.equal(plugin.calls.finish, 1)
  assert.equal(result.state, 'saved')
})

test('an ended session stops sending audio', async () => {
  const plugin = fakePlugin({ windowResult: { ended: true, text: '' } })
  const session = createPluginSession({ ref: 'a1b2c3d4e5f60718', language: 'de', client: plugin, commitAfterMs: 1000 })
  await session.handle(media('ada-1'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'ada-1' } }, () => {})
  await session.handle(media('bea-2'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'bea-2' } }, () => {})

  assert.equal(session.ended(), true)
  assert.equal(plugin.calls.windows.length, 1)
})

test('the plugin client sends the API key and maps 410 to "ended"', async () => {
  const seen = []
  const fetchImpl = async (url, options) => {
    seen.push({ url, auth: options.headers.get('Authorization'), method: options.method || 'GET' })
    if (url.endsWith('/audio?speaker=ada-1&at=5')) {
      return new Response(JSON.stringify({ error: 'not_active' }), { status: 410 })
    }
    return new Response(JSON.stringify({ error: 'not_active' }), { status: 410 })
  }
  const client = createPluginClient({ baseUrl: 'https://synaplan.example/', apiKey: 'sk_test', fetchImpl })

  assert.equal(await client.bind('a1b2c3d4e5f60718'), null)
  assert.deepEqual(await client.window('a1b2c3d4e5f60718', { speaker: 'ada-1', at: 5, audio: Buffer.from('OggS') }), { ended: true, text: '' })
  assert.equal(seen[0].url, 'https://synaplan.example/api/v1/plugins/synascriber/transcriber/sessions/a1b2c3d4e5f60718')
  assert.equal(seen[0].auth, 'Bearer sk_test')
  assert.equal(seen[1].method, 'POST')
})

test('a bridge connection without an active session is refused', async () => {
  const config = { mode: 'jitsi', jitsi: true, element: false, authToken: 'tok', commitAfterMs: 8000, language: 'auto' }
  const plugin = { async bind() { return null } }
  const server = createApp(config, { synaplan: {}, plugin })
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address()

  const status = await new Promise((resolve, reject) => {
    const req = request({
      port,
      host: '127.0.0.1',
      path: '/transcribe?sessionId=m1&notes=a1b2c3d4e5f60718',
      headers: {
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        Authorization: 'Bearer tok',
        'Sec-WebSocket-Version': '13',
        'Sec-WebSocket-Key': Buffer.from('0123456789abcdef').toString('base64'),
      },
    })
    req.on('response', (res) => resolve(res.statusCode))
    req.on('upgrade', () => resolve(101))
    req.on('error', reject)
    req.end()
  })
  server.close()

  assert.equal(status, 410)
})
