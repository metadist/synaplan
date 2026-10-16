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
    assert.equal(body.jitsi, true)
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
