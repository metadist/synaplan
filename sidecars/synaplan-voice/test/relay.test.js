import assert from 'node:assert/strict'
import http from 'node:http'
import test from 'node:test'
import { WebSocket } from 'ws'
import { createMockAdapter } from '../src/adapters/mock.js'
import { verify } from '../src/hmac.js'
import { createServer } from '../src/server.js'
import { shouldDropAudio, disconnectPlan, replyFrame, storedFrame, artifactFrame, postUntilStored, CARPLAY_RECONNECT_MS } from '../src/session.js'

const SECRET = 'test-secret'

test('audio is dropped once the client buffer is over a megabyte', () => {
  assert.equal(shouldDropAudio(1_000_000), false)
  assert.equal(shouldDropAudio(1_000_001), true)
})

test('a rejected transcript is stored on the next attempt', async () => {
  const statuses = [500, 200]
  let calls = 0
  const result = await postUntilStored(async () => {
    calls += 1
    return { status: statuses[calls - 1], json: { ok: true } }
  }, { delay: async () => {} })
  assert.equal(calls, 2)
  assert.equal(result.status, 200)
})

test('a stored assistant reply carries its models back to the client', () => {
  const details = {
    role: 'assistant',
    topic: 'general',
    aiModels: { voice: { provider: 'OpenAI', model: 'GPT Live', model_id: 15 } },
  }
  assert.deepEqual(replyFrame('assistant', { details }), { type: 'reply', details })
  assert.equal(replyFrame('user', { details }), null)
  assert.equal(replyFrame('assistant', { ok: true }), null)
})

test('a stored turn tells the client which chat row it became', () => {
  assert.deepEqual(storedFrame('user', { messageId: 41 }, 'Hello'), {
    type: 'stored',
    role: 'user',
    messageId: 41,
    text: 'Hello',
  })
  assert.deepEqual(storedFrame('assistant', { messageId: 42, details: {} }, 'Hi'), {
    type: 'stored',
    role: 'assistant',
    messageId: 42,
    text: 'Hi',
  })
  assert.equal(storedFrame('user', { ok: true }, 'Hello'), null)
  assert.equal(storedFrame('assistant', { messageId: 42 }, '  '), null)
})

test('a made picture is handed to the client so the chat can show it', () => {
  const artifact = { type: 'image', state: 'running', messageId: 43 }
  assert.deepEqual(artifactFrame({ text: 'I am creating the image now.', artifact }), { type: 'artifact', artifact })
  assert.equal(artifactFrame({ text: 'It is four.', artifact: null }), null)
  assert.equal(artifactFrame({ text: 'It is four.' }), null)
  assert.equal(artifactFrame({ artifact: { type: 'image' } }), null)
  assert.equal(artifactFrame({ artifact: [] }), null)
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

test('an unfinished sentence is stored before the session is marked closed', async () => {
  let releaseTranscript
  const gate = new Promise((resolve) => {
    releaseTranscript = resolve
  })
  const order = []
  const backend = http.createServer((req, res) => {
    const chunks = []
    req.on('data', (chunk) => chunks.push(chunk))
    req.on('end', () => {
      const path = String(req.url)
      if (path === '/internal/voice/tickets/redeem') {
        res.writeHead(200, { 'content-type': 'application/json' })
        res.end(JSON.stringify({
          sessionId: 4,
          provider: 'mock-openai',
          model: 'gpt-live-1',
          voice: 'marin',
          instructions: 'Ask Synaplan.',
          allowedSeconds: 80,
          audio: { format: 'pcm16', inRate: 24000, outRate: 24000 },
        }))
        return
      }
      if (path.endsWith('/transcript')) {
        order.push('transcript')
        void gate.then(() => {
          order.push('transcript-done')
          res.writeHead(200, { 'content-type': 'application/json' })
          res.end(JSON.stringify({ ok: true }))
        })
        return
      }
      order.push(path.endsWith('/closed') ? 'closed' : path)
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({ ok: true }))
    })
  })
  await listen(backend)

  const listeners = new Map()
  const gateway = createServer({
    synaplanUrl: `http://127.0.0.1:${backend.address().port}`,
    secret: SECRET,
    tickSeconds: 0,
    idleSeconds: 0,
    adapterFor: () => ({
      on: (event, fn) => {
        const list = listeners.get(event) ?? []
        list.push(fn)
        listeners.set(event, list)
      },
      async open() {},
      sendAudio() {
        for (const fn of listeners.get('transcript') ?? []) {
          fn({ role: 'user', text: 'Hallo', final: false })
        }
      },
      close() {},
    }),
  })
  await listen(gateway)

  const client = new WebSocket(`ws://127.0.0.1:${gateway.address().port}/voice/ws?ticket=one-time`)
  const frames = []
  client.on('message', (data, isBinary) => {
    if (!isBinary) frames.push(JSON.parse(data.toString()))
  })
  await once(client, 'open')
  await waitFor(() => frames.some((frame) => frame.type === 'ready'))
  client.send(Buffer.from([0, 0, 1, 0]))
  await new Promise((resolve) => setTimeout(resolve, 30))
  client.close()
  await waitFor(() => order.includes('transcript'))
  assert.equal(order.includes('closed'), false)
  releaseTranscript()
  await waitFor(() => order.includes('closed'))
  assert.deepEqual(order.filter((item) => item === 'transcript' || item === 'transcript-done' || item === 'closed'), [
    'transcript',
    'transcript-done',
    'closed',
  ])

  client.terminate()
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
