import assert from 'node:assert/strict'
import http from 'node:http'
import test from 'node:test'
import { WebSocket, WebSocketServer } from 'ws'
import { mapOpenAiUsage } from '../src/adapters/openai.js'
import { createServer } from '../src/server.js'
import { exchangeSdp, LIVE_IDLE_TIMEOUT_S, liveCreateBody } from '../src/webrtc.js'

test('GPT-Live SDP exchange forbids every client event', async () => {
  let seen = null
  const result = await exchangeSdp({
    fetchImpl: async (_url, init) => {
      seen = JSON.parse(init.body)
      return {
        ok: true,
        json: async () => ({ session: { id: 'sess_1' }, transport: { sdp: 'answer-sdp' } }),
        headers: { get: () => null },
      }
    },
    apiKey: 'sk-test',
    config: { model: 'gpt-live-1', instructions: 'Be brief.', voice: 'marin' },
    sdp: 'offer-sdp',
  })

  assert.deepEqual(seen, liveCreateBody(
    { model: 'gpt-live-1', instructions: 'Be brief.', voice: 'marin' },
    'offer-sdp',
  ))
  assert.deepEqual(seen.session.client.data_channel.allowed_client_events, [])
  assert.equal(result.answer, 'answer-sdp')
  assert.equal(result.dialect, 'live')
  assert.equal(result.sidebandUrl, 'wss://api.openai.com/v1/live/sessions/sess_1/attach')
})

test('GPT-Realtime SDP exchange keeps the key on the server and skips PCM', async () => {
  let session = null
  const result = await exchangeSdp({
    fetchImpl: async (_url, init) => {
      session = JSON.parse(await init.body.get('session').text())
      return {
        ok: true,
        text: async () => 'answer-sdp',
        headers: { get: (name) => (name.toLowerCase() === 'location' ? '/v1/realtime/calls/rtc_test' : null) },
      }
    },
    apiKey: 'sk-test',
    config: { model: 'gpt-realtime-2.1', instructions: 'Be brief.', voice: 'marin' },
    sdp: 'offer-sdp',
  })

  assert.equal(session.tools[0].name, 'consult_synaplan')
  assert.equal(session.audio.input.format, undefined)
  assert.equal(session.audio.output.format, undefined)
  assert.equal(result.dialect, 'realtime')
  assert.equal(result.sidebandUrl, 'wss://api.openai.com/v1/realtime?call_id=rtc_test')
})

test('a WebRTC sideband bills the same meter as the relay mapper and closes on stop', async () => {
  const usage = {
    seconds: 4,
    audio_input_tokens: 11,
    audio_output_tokens: 6,
    input_tokens: 2,
    output_tokens: 3,
  }
  const run = await webrtcSession((ws) => {
    ws.send(JSON.stringify({ type: 'session.resumed' }))
    ws.send(JSON.stringify({ type: 'session.usage.updated', usage }))
  })

  assert.equal(run.answer.sdp, 'v=answer')
  assert.equal(run.usageBodies.length, 1)
  const posted = run.usageBodies[0].meter
  const expected = mapOpenAiUsage(usage)
  assert.equal(posted.seconds, expected.seconds)
  assert.equal(posted.audioInTokens, expected.audioInTokens)
  assert.equal(posted.audioOutTokens, expected.audioOutTokens)
  assert.equal(posted.audioCacheTokens, expected.audioCacheTokens)
  assert.equal(posted.textInTokens, expected.textInTokens)
  assert.equal(posted.textOutTokens, expected.textOutTokens)
  assert.equal(run.sidebandMessages.some((message) => message.type === 'session.close'), true)
})

test('GPT-Live waits for speech and returns to standby after an idle timeout', async () => {
  const run = await webrtcSession(async (ws) => {
    await new Promise((resolve) => setTimeout(resolve, 150))
    ws.send(JSON.stringify({ type: 'session.paused' }))
    await new Promise((resolve) => setTimeout(resolve, 150))
    ws.send(JSON.stringify({ type: 'session.resumed' }))
    ws.send(JSON.stringify({ type: 'session.usage.updated', usage: { seconds: 4 } }))
  })

  assert.deepEqual(
    [run.created.session.wake_policy, run.created.session.idle_timeout_s],
    [{ type: 'speech' }, LIVE_IDLE_TIMEOUT_S],
  )
  // Active until the pause, standby until the resume. A live session does
  // not start paused: OpenAI sends no resume when the person first speaks.
  const meter = run.usageBodies[0].meter
  assert.ok(meter.standbySeconds >= 0.13 && meter.standbySeconds < 0.3, `standby ${meter.standbySeconds}`)
  assert.equal(meter.seconds, 4)
})

test('standby comes on top of the active seconds OpenAI reports', async () => {
  const run = await webrtcSession(async (ws) => {
    ws.send(JSON.stringify({ type: 'session.paused' }))
    await new Promise((resolve) => setTimeout(resolve, 200))
    ws.send(JSON.stringify({ type: 'session.usage.updated', usage: { seconds: 0.05 } }))
  })

  const meter = run.usageBodies[0].meter
  assert.equal(meter.seconds, 0.05)
  assert.ok(meter.standbySeconds >= 0.18, `standby ${meter.standbySeconds}`)
})

test('the last seconds OpenAI reports after the close reach the closing bill', async () => {
  const run = await webrtcSession((ws) => {
    ws.on('message', (raw) => {
      if (JSON.parse(raw.toString()).type !== 'session.close') return
      // OpenAI answers the close with the final usage before its close frame.
      ws.send(JSON.stringify({ type: 'session.closed', usage: { seconds: 14 } }))
    })
    ws.send(JSON.stringify({ type: 'session.usage.updated', usage: { seconds: 4 } }))
  })

  assert.equal(run.usageBodies[0].meter.seconds, 4)
  assert.equal(run.closedBodies.length, 1)
  assert.equal(run.closedBodies[0].meter.seconds, 14)
})

/**
 * One GPT-Live WebRTC session through the gateway: the backend stops it on
 * the first usage callback. onSideband scripts the provider sideband.
 */
async function webrtcSession(onSideband) {
  const sidebandMessages = []
  const usageBodies = []
  const closedBodies = []
  let created = null
  const sideband = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await once(sideband, 'listening')
  sideband.on('connection', (ws) => {
    ws.on('message', (raw) => sidebandMessages.push(JSON.parse(raw.toString())))
    void onSideband(ws)
  })

  const backend = http.createServer((req, res) => {
    const chunks = []
    req.on('data', (chunk) => chunks.push(chunk))
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString()
      if (req.url === '/internal/voice/tickets/redeem') {
        res.writeHead(200, { 'content-type': 'application/json' })
        res.end(JSON.stringify({
          sessionId: 9,
          provider: 'openai',
          model: 'gpt-live-1',
          voice: 'marin',
          instructions: 'Ask Synaplan.',
          allowedSeconds: 80,
          transport: 'webrtc',
          audio: { format: 'pcm16', inRate: 24000, outRate: 24000 },
        }))
        return
      }
      if (String(req.url).endsWith('/closed')) closedBodies.push(JSON.parse(body))
      if (String(req.url).endsWith('/usage')) {
        usageBodies.push(JSON.parse(body))
        res.writeHead(200, { 'content-type': 'application/json' })
        res.end(JSON.stringify({ action: 'stop', allowedSeconds: 0, reason: 'limit_budget' }))
        return
      }
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({ action: 'continue', allowedSeconds: 80, reason: '' }))
    })
  })
  await listen(backend)

  const gateway = createServer({
    synaplanUrl: `http://127.0.0.1:${backend.address().port}`,
    secret: 'test-secret',
    tickSeconds: 0,
    idleSeconds: 0,
    finalUsageMs: 500,
    openaiApiKey: 'sk-test',
    sidebandUrlFor: () => `ws://127.0.0.1:${sideband.address().port}`,
    fetch: async (url, init) => {
      if (String(url).includes('/v1/live/sessions')) {
        created = JSON.parse(init.body)
        assert.deepEqual(created.session.client.data_channel.allowed_client_events, [])
        return {
          ok: true,
          json: async () => ({ session: { id: 'sess_9' }, transport: { sdp: 'v=answer' } }),
          headers: { get: () => null },
        }
      }
      return fetch(url, init)
    },
    log: () => {},
    adapterFor: () => {
      throw new Error('relay adapter')
    },
  })
  await listen(gateway)

  const client = new WebSocket(`ws://127.0.0.1:${gateway.address().port}/voice/ws?ticket=eyJhbGciOiJIUzI1NiJ9.eyJzaWQiOjd9.c2lnbmF0dXJl`)
  const frames = []
  client.on('message', (raw) => {
    const text = raw.toString()
    if (text.startsWith('{')) frames.push(JSON.parse(text))
  })
  try {
    await once(client, 'open')
    await waitFor(() => frames.some((frame) => frame.type === 'offer_required'))
    client.send(JSON.stringify({ type: 'offer', sdp: 'v=offer' }))
    await waitFor(() => frames.some((frame) => frame.type === 'ended'))
  } finally {
    client.terminate()
    gateway.close()
    backend.close()
    sideband.close()
  }

  return { answer: frames.find((frame) => frame.type === 'answer'), created, usageBodies, closedBodies, sidebandMessages }
}

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
