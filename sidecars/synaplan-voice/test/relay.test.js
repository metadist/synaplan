import assert from 'node:assert/strict'
import http from 'node:http'
import test from 'node:test'
import { WebSocket } from 'ws'
import { createMockAdapter } from '../src/adapters/mock.js'
import { verify } from '../src/hmac.js'
import { createServer } from '../src/server.js'
import { shouldDropAudio, disconnectPlan, CARPLAY_RECONNECT_MS } from '../src/session.js'

const SECRET = 'test-secret'

test('audio is dropped once the client buffer is over a megabyte', () => {
  assert.equal(shouldDropAudio(1_000_000), false)
  assert.equal(shouldDropAudio(1_000_001), true)
})

test('only a CarPlay client parks the provider after the socket drops', () => {
  assert.equal(disconnectPlan('carplay'), 'park')
  assert.equal(disconnectPlan('web'), 'close')
  assert.equal(disconnectPlan(''), 'close')
  assert.equal(CARPLAY_RECONNECT_MS, 20_000)
})

for (const dialect of ['mock-openai', 'mock-google']) {
  test(`${dialect} closes the provider and stores the transcript when the client socket drops`, async () => {
    const calls = []
    const logs = []
    const backend = http.createServer((req, res) => {
      const chunks = []
      req.on('data', (chunk) => chunks.push(chunk))
      req.on('end', () => {
        const body = Buffer.concat(chunks).toString()
        calls.push({
          path: req.url,
          body,
          timestamp: req.headers['x-voice-timestamp'],
          signature: req.headers['x-voice-signature'],
        })
        let json = {}
        try {
          json = JSON.parse(body)
        } catch {
          json = {}
        }
        if (req.url === '/internal/voice/tickets/redeem') {
          res.writeHead(200, { 'content-type': 'application/json' })
          res.end(JSON.stringify({
            sessionId: 7,
            provider: dialect,
            model: dialect === 'mock-google' ? 'gemini-3.8-live' : 'gpt-live-1',
            voice: dialect === 'mock-google' ? '' : 'marin',
            instructions: 'Ask Synaplan.',
            allowedSeconds: 80,
            audio: dialect === 'mock-google'
              ? { format: 'pcm16', inRate: 16000, outRate: 24000 }
              : { format: 'pcm16', inRate: 24000, outRate: 24000 },
          }))
          return
        }
        if (String(req.url).endsWith('/delegate')) {
          assert.equal(json.question, 'What time is it?')
          res.writeHead(200, { 'content-type': 'application/json' })
          res.end(JSON.stringify({ text: 'It is four.', facts: [] }))
          return
        }
        res.writeHead(200, { 'content-type': 'application/json' })
        res.end(JSON.stringify({ action: 'continue', allowedSeconds: 80, reason: '', ok: true }))
      })
    })
    await listen(backend)

    let adapter
    const gateway = createServer({
      synaplanUrl: `http://127.0.0.1:${backend.address().port}`,
      secret: SECRET,
      tickSeconds: 0,
      idleSeconds: 0,
      adapterFor: (provider) => {
        adapter = createMockAdapter(provider)
        return adapter
      },
      log: (fields) => logs.push(fields),
    })
    await listen(gateway)

    const client = new WebSocket(`ws://127.0.0.1:${gateway.address().port}/voice/ws?ticket=one-time`)
    const frames = []
    client.on('message', (data, isBinary) => {
      frames.push(isBinary ? { binary: true, bytes: data.length } : JSON.parse(data.toString()))
    })
    await once(client, 'open')
    await waitFor(() => frames.some((frame) => frame.type === 'ready'))
    client.send(Buffer.from([0, 0, 1, 0]))
    await waitFor(() => frames.some((frame) => frame.binary))
    client.close()
    await waitFor(() => calls.some((call) => String(call.path).endsWith('/closed')) && calls.some((call) => String(call.path).endsWith('/transcript')))

    assert.equal(adapter.closed, true)
    assert.equal(adapter.dialect, dialect)
    const kind = dialect === 'mock-google' ? 'tool' : 'commentary'
    assert.equal(adapter.injects.some((item) => item.kind === kind && item.text === 'It is four.'), true)
    const closed = calls.find((call) => call.path.endsWith('/closed'))
    assert.equal(JSON.parse(closed.body).reason, 'network_lost')
    assert.equal(verify(SECRET, closed.timestamp, closed.signature, closed.body), true)
    const transcript = calls.find((call) => call.path.endsWith('/transcript'))
    assert.equal(JSON.parse(transcript.body).turns[0].text, 'What time is it?')
    assert.equal(logs.some((line) => JSON.stringify(line).includes('What time is it?')), false)
    assert.equal(logs.some((line) => JSON.stringify(line).includes('It is four.')), false)

    client.terminate()
    gateway.close()
    backend.close()
  })
}

test('a CarPlay drop keeps the provider until the reconnect window ends', async () => {
  const calls = []
  const backend = http.createServer((req, res) => {
    const chunks = []
    req.on('data', (chunk) => chunks.push(chunk))
    req.on('end', () => {
      calls.push(req.url)
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({
        sessionId: 9,
        provider: 'mock-openai',
        model: 'gpt-live-1',
        client: 'carplay',
        voice: 'marin',
        instructions: 'Ask Synaplan.',
        allowedSeconds: 80,
        transport: 'relay',
        audio: { format: 'pcm16', inRate: 24000, outRate: 24000 },
        action: 'continue',
        ok: true,
      }))
    })
  })
  await listen(backend)
  let adapter
  const gateway = createServer({
    synaplanUrl: `http://127.0.0.1:${backend.address().port}`,
    secret: SECRET,
    tickSeconds: 0,
    idleSeconds: 0,
    reconnectGraceMs: 200,
    adapterFor: (provider) => {
      adapter = createMockAdapter(provider)
      return adapter
    },
  })
  await listen(gateway)
  const url = `ws://127.0.0.1:${gateway.address().port}/voice/ws?ticket=one`

  const first = new WebSocket(url)
  const firstFrames = []
  first.on('message', (data, isBinary) => {
    if (!isBinary) firstFrames.push(JSON.parse(data.toString()))
  })
  await once(first, 'open')
  await waitFor(() => firstFrames.some((frame) => frame.type === 'ready'))
  first.close()
  await new Promise((resolve) => setTimeout(resolve, 40))
  assert.equal(adapter.closed, false)
  assert.equal(calls.some((path) => String(path).endsWith('/closed')), false)

  const second = new WebSocket(url)
  const secondFrames = []
  second.on('message', (data, isBinary) => {
    if (!isBinary) secondFrames.push(JSON.parse(data.toString()))
  })
  await once(second, 'open')
  await waitFor(() => secondFrames.some((frame) => frame.type === 'ready'))
  assert.equal(adapter.closed, false)

  second.close()
  await waitFor(() => calls.some((path) => String(path).endsWith('/closed')))
  assert.equal(adapter.closed, true)

  gateway.close()
  backend.close()
})

function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
}

function once(target, event) {
  return new Promise((resolve) => target.once(event, resolve))
}

async function waitFor(predicate) {
  const start = Date.now()
  while (!predicate()) {
    if (Date.now() - start > 3000) throw new Error('timed out')
    await new Promise((resolve) => setTimeout(resolve, 15))
  }
}
