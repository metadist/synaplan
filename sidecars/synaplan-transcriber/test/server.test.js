import assert from 'node:assert/strict'
import test from 'node:test'
import { WebSocket } from 'ws'
import { loadConfig } from '../src/config.js'
import { createApp } from '../src/server.js'

function config(overrides = {}) {
  return loadConfig({
    SYNAPLAN_URL: 'http://synaplan',
    SYNAPLAN_API_KEY: 'sk_test',
    TRANSCRIBER_MODE: 'jitsi',
    TRANSCRIBER_AUTH_TOKEN: 'secret',
    ...overrides,
  })
}

async function listen(server) {
  await new Promise((resolve) => {
    server.listen(0, '127.0.0.1', resolve)
  })
  const address = server.address()
  return `127.0.0.1:${address.port}`
}

test('health, Element Call, and the Jitsi socket', async () => {
  const server = createApp(config(), {
    synaplan: { async saveNote() { return { id: 'note_1' } } },
  })
  const host = await listen(server)
  try {
    const health = await fetch(`http://${host}/health`)
    assert.equal(health.status, 200)
    const body = await health.json()
    assert.equal(body.product, 'Meeting notes')
    assert.equal(body.mode, 'jitsi')
    assert.equal(body.jitsi, true)
    assert.equal(body.plugin, false)
    assert.equal(body.captionsWithoutPlugin, true)
    assert.equal(body.opentalk, false)
    assert.match(body.elementCall, /not in this version/)

    const later = await fetch(`http://${host}/element-call`)
    assert.equal(later.status, 501)

    const refused = await new Promise((resolve, reject) => {
      const ws = new WebSocket(`ws://${host}/transcribe?sessionId=standup`)
      ws.on('open', () => reject(new Error('unauthenticated socket opened')))
      ws.on('unexpected-response', (_request, response) => resolve(response.statusCode))
      ws.on('error', () => {})
    })
    assert.equal(refused, 401)

    const opened = await new Promise((resolve, reject) => {
      const ws = new WebSocket(`ws://${host}/transcribe?sessionId=standup`, {
        headers: { Authorization: 'Bearer secret' },
      })
      ws.on('open', () => {
        ws.send(JSON.stringify({ event: 'ping', id: 4 }))
      })
      ws.on('message', (data) => {
        ws.close()
        resolve(JSON.parse(data.toString()))
      })
      ws.on('error', reject)
    })
    assert.deepEqual(opened, { event: 'pong', id: 4 })
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
})

test('health names an OpenTalk process and hides the Jitsi socket', async () => {
  const server = createApp(loadConfig({
    SYNAPLAN_URL: 'http://synaplan',
    SYNAPLAN_TRANSCRIBER_SECRET: 'plugin-secret',
    TRANSCRIBER_MODE: 'opentalk',
    OPENTALK_CONTROLLER_URL: 'https://controller.example',
    OPENTALK_SERVICE_KEY: 'controller:secret',
    OPENTALK_INIT_KEY: 'transcription:secret',
    OPENTALK_LIVEKIT_URL: 'http://livekit:7880',
  }), {
    plugin: {},
    opentalk: { async accept() { return { status: 204, body: {} } } },
  })
  const host = await listen(server)
  try {
    const health = await fetch(`http://${host}/health`)
    const body = await health.json()
    assert.equal(body.mode, 'opentalk')
    assert.equal(body.opentalk, true)
    assert.equal(body.jitsi, false)
    assert.equal(body.plugin, true)
    assert.equal(body.captionsWithoutPlugin, false)
    const home = await (await fetch(`http://${host}/`)).json()
    assert.equal(home.opentalk, '/v1/init')
    assert.equal(home.transcribe, null)
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
})

test('element mode does not open the Jitsi socket', async () => {
  const server = createApp(config({ TRANSCRIBER_MODE: 'element', TRANSCRIBER_AUTH_TOKEN: '' }), { synaplan: {} })
  const host = await listen(server)
  try {
    const status = await new Promise((resolve) => {
      const ws = new WebSocket(`ws://${host}/transcribe?sessionId=standup`)
      ws.on('unexpected-response', (_request, response) => resolve(response.statusCode))
      ws.on('error', () => {})
    })
    assert.equal(status, 404)
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
})

function upgradeStatus(host, path) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://${host}${path}`, { headers: { Authorization: 'Bearer secret' } })
    ws.on('open', () => reject(new Error('socket opened')))
    ws.on('unexpected-response', (_request, response) => resolve(response.statusCode))
    ws.on('error', () => {})
  })
}

test('a missing credential refuses the Jitsi path that needs it, with a log line', async () => {
  const lines = []
  const server = createApp(config(), { synaplan: null, plugin: null, log: (line) => lines.push(line) })
  const host = await listen(server)
  try {
    assert.equal(await upgradeStatus(host, '/transcribe?notes=Ab3dEf9hJkLmN0pQrStUvW&sessionId=m1'), 503)
    assert.equal(await upgradeStatus(host, '/transcribe?sessionId=standup'), 503)
    assert.equal(await upgradeStatus(host, '/transcribe?notes=too-short'), 400)
    assert.ok(lines.some((line) => line.includes('SYNAPLAN_TRANSCRIBER_SECRET is not set')))
    assert.ok(lines.some((line) => line.includes('need SYNAPLAN_API_KEY')))
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
})
