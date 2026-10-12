import assert from 'node:assert/strict'
import test from 'node:test'
import { WebSocketServer } from 'ws'
import { compatibleHeaders, createCompatibleAdapter } from '../src/adapters/compatible.js'
import { createGeminiAdapter } from '../src/adapters/gemini.js'
import { adapterKind } from '../src/adapters/index.js'
import { createOpenAiAdapter, mapOpenAiUsage } from '../src/adapters/openai.js'
import { createOpenAiRealtimeAdapter } from '../src/adapters/realtime.js'
import { createXaiAdapter } from '../src/adapters/xai.js'
import { mapGeminiUsage } from '../src/adapters/gemini.js'

test('OpenAI adapter starts a stored-off client-delegation session', async () => {
  const seen = await oneShot(async (ws) => {
    const raw = await nextMessage(ws)
    const event = JSON.parse(raw.toString())
    assert.equal(event.type, 'session.start')
    assert.equal(event.session.store, false)
    assert.equal(event.session.delegation.type, 'client')
    assert.equal(event.session.model, 'gpt-live-1')
    assert.equal(event.session.audio.output.voice, 'marin')
    ws.send(JSON.stringify({ type: 'session.started', session: { id: 'sess_1' } }))
  })

  const adapter = createOpenAiAdapter({ url: seen.url, apiKey: 'sk-test' })
  await adapter.open({ model: 'gpt-live-1', instructions: 'Ask Synaplan.', voice: '', audio: { inRate: 24000 } })
  await adapter.close()
  seen.server.close()
})

test('OpenAI usage seconds are copied through', () => {
  assert.equal(mapOpenAiUsage({ seconds: 7 }).seconds, 7)
})

test('Gemini adapter resumes on GoAway with the new handle', async () => {
  let connections = 0
  let resumed = null
  const server = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await listen(server)
  server.on('connection', (ws) => {
    connections += 1
    ws.on('message', (raw) => {
      const event = JSON.parse(raw.toString())
      if (connections === 1) {
        assert.equal(event.setup.tools[0].functionDeclarations[0].name, 'consult_synaplan')
        assert.deepEqual(event.setup.contextWindowCompression, { slidingWindow: {} })
        ws.send(JSON.stringify({ setupComplete: {} }))
        ws.send(JSON.stringify({ sessionResumptionUpdate: { newHandle: 'resume-1' } }))
        ws.send(JSON.stringify({ goAway: { timeLeft: '0s' } }))
        return
      }
      resumed = event.setup.sessionResumption.handle
      ws.send(JSON.stringify({ setupComplete: {} }))
    })
  })

  const adapter = createGeminiAdapter({ url: `ws://127.0.0.1:${server.address().port}` })
  await adapter.open({ model: 'gemini-3.8-live', instructions: 'Ask Synaplan.' })
  await waitFor(() => resumed === 'resume-1')
  assert.equal(adapter.resumptionHandle(), 'resume-1')
  adapter.close()
  server.close()
})

test('Gemini audio tokens are split out of the text totals', () => {
  const meter = mapGeminiUsage({
    promptTokenCount: 110,
    responseTokenCount: 80,
    promptTokensDetails: [{ modality: 'AUDIO', tokenCount: 100 }],
    responseTokensDetails: [{ modality: 'AUDIO', tokenCount: 70 }],
  })
  assert.equal(meter.audioInTokens, 100)
  assert.equal(meter.audioOutTokens, 70)
  assert.equal(meter.textInTokens, 10)
  assert.equal(meter.textOutTokens, 10)
})

test('adapter kind follows the model, not only the provider', () => {
  assert.equal(adapterKind('openai', 'gpt-live-1'), 'openai-live')
  assert.equal(adapterKind('openai', 'gpt-realtime-2.1'), 'openai-realtime')
  assert.equal(adapterKind('openai', 'gpt-realtime-2.1-mini'), 'openai-realtime')
  assert.equal(adapterKind('google', 'gemini-3.8-live-extended-thinking'), 'gemini')
  assert.equal(adapterKind('xai', 'grok-voice-think-fast-2.0'), 'xai')
  assert.equal(adapterKind('compatible', 'qwen3-omni'), 'compatible')
})

test('a compatible endpoint speaks realtime and picks the auth header', async () => {
  assert.deepEqual(compatibleHeaders('none', 'secret'), {})
  assert.deepEqual(compatibleHeaders('bearer', ''), {})
  assert.deepEqual(compatibleHeaders('api-key', 'azure-key'), { 'api-key': 'azure-key' })
  assert.deepEqual(compatibleHeaders('bearer', 'sk-test'), { Authorization: 'Bearer sk-test' })

  const server = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await listen(server)
  let seen = null
  server.on('connection', (ws, request) => {
    assert.equal(request.headers['api-key'], 'azure-key')
    assert.equal(request.headers.authorization, undefined)
    ws.on('message', (raw) => {
      const event = JSON.parse(raw.toString())
      if (event.type !== 'session.update') return
      seen = event.session.model
      assert.equal(event.session.tools[0].name, 'consult_synaplan')
      ws.send(JSON.stringify({ type: 'session.updated' }))
    })
  })

  const adapter = createCompatibleAdapter({
    url: `ws://127.0.0.1:${server.address().port}`,
    apiKey: 'azure-key',
    auth: 'api-key',
    model: 'my-deployment',
  })
  await adapter.open({ model: 'qwen3-omni', instructions: 'Ask Synaplan.', audio: { inRate: 24000, outRate: 24000 } })
  assert.equal(seen, 'my-deployment')
  adapter.close()
  server.close()
})

test('Gemini extended thinking uses the same live setup', async () => {
  const seen = await oneShot(async (ws) => {
    const event = JSON.parse((await nextMessage(ws)).toString())
    assert.equal(event.setup.model, 'models/gemini-3.8-live-extended-thinking')
    assert.equal(event.setup.tools[0].functionDeclarations[0].name, 'consult_synaplan')
    ws.send(JSON.stringify({ setupComplete: {} }))
  })
  const adapter = createGeminiAdapter({ url: seen.url })
  await adapter.open({ model: 'gemini-3.8-live-extended-thinking', instructions: 'Ask Synaplan.' })
  adapter.close()
  seen.server.close()
})

test('Realtime adapter asks Synaplan and accumulates token usage', async () => {
  let socket = null
  const server = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await listen(server)
  server.on('connection', (ws) => {
    socket = ws
    ws.on('message', (raw) => {
      const event = JSON.parse(raw.toString())
      if (event.type !== 'session.update') return
      assert.equal(event.session.model, 'gpt-realtime-2.1')
      assert.equal(event.session.tools[0].name, 'consult_synaplan')
      assert.equal(event.session.audio.output.voice, 'marin')
      assert.equal(event.session.audio.input.turn_detection.type, 'server_vad')
      ws.send(JSON.stringify({ type: 'session.updated' }))
    })
  })

  const adapter = createOpenAiRealtimeAdapter({ url: `ws://127.0.0.1:${server.address().port}`, apiKey: 'sk-test' })
  const tools = []
  const usages = []
  adapter.on('tool', (event) => tools.push(event))
  adapter.on('usage', (meter) => usages.push({ ...meter }))
  await adapter.open({
    model: 'gpt-realtime-2.1',
    instructions: 'Ask Synaplan.',
    voice: 'marin',
    audio: { inRate: 24000, outRate: 24000 },
  })
  socket.send(JSON.stringify({
    type: 'response.done',
    response: { usage: { input_token_details: { audio_tokens: 12, text_tokens: 2, cached_tokens_details: { audio_tokens: 2 } }, output_token_details: { audio_tokens: 4, text_tokens: 1 } } },
  }))
  socket.send(JSON.stringify({
    type: 'response.done',
    response: { usage: { input_token_details: { audio_tokens: 3, text_tokens: 1 }, output_token_details: { audio_tokens: 1, text_tokens: 1 } } },
  }))
  socket.send(JSON.stringify({
    type: 'response.function_call_arguments.done',
    name: 'consult_synaplan',
    call_id: 'call-9',
    arguments: JSON.stringify({ question: 'What time is it?' }),
  }))
  await waitFor(() => tools.length === 1 && usages.length === 2)
  assert.equal(tools[0].question, 'What time is it?')
  assert.equal(usages[1].audioInTokens, 13)
  assert.equal(usages[1].audioCacheTokens, 2)
  assert.equal(usages[0].audioInTokens, 10)
  adapter.close()
  server.close()
})

test('xAI counts text items and does not invent token usage', async () => {
  let socket = null
  const server = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await listen(server)
  server.on('connection', (ws) => {
    socket = ws
    ws.on('message', (raw) => {
      const event = JSON.parse(raw.toString())
      if (event.type === 'session.update') ws.send(JSON.stringify({ type: 'session.updated' }))
    })
  })

  const adapter = createXaiAdapter({ url: `ws://127.0.0.1:${server.address().port}`, apiKey: 'xai-test' })
  const usages = []
  let finals = 0
  adapter.on('usage', (meter) => usages.push({ ...meter }))
  adapter.on('transcript', (turn) => {
    if (turn.final) finals += 1
  })
  await adapter.open({ model: 'grok-voice-think-fast-2.0', instructions: 'Ask Synaplan.', audio: { inRate: 24000 } })
  socket.send(JSON.stringify({ type: 'conversation.item.added', item: { id: 'msg-1', type: 'message' } }))
  socket.send(JSON.stringify({ type: 'conversation.item.added', item: { id: 'msg-1', type: 'message' } }))
  socket.send(JSON.stringify({ type: 'conversation.item.added', item: { id: 'out-1', type: 'function_call_output' } }))
  socket.send(JSON.stringify({
    type: 'conversation.item.input_audio_transcription.updated',
    item_id: 'msg-1',
    transcript: 'What time is it?',
  }))
  socket.send(JSON.stringify({ type: 'conversation.item.done', item: { id: 'msg-1', type: 'message' } }))
  await waitFor(() => finals === 1 && usages.length >= 1)
  assert.equal(usages.at(-1).textItems, 1)
  assert.equal(usages.at(-1).audioInTokens, undefined)
  adapter.close()
  server.close()
})

function nextMessage(ws) {
  return new Promise((resolve) => ws.once('message', resolve))
}

async function oneShot(onConnection) {
  const server = new WebSocketServer({ port: 0, host: '127.0.0.1' })
  await listen(server)
  server.on('connection', (ws) => { void onConnection(ws) })
  return { server, url: `ws://127.0.0.1:${server.address().port}` }
}

function listen(server) {
  return new Promise((resolve) => {
    if (server.address()) resolve()
    else server.once('listening', resolve)
  })
}

async function waitFor(predicate) {
  const start = Date.now()
  while (!predicate()) {
    if (Date.now() - start > 3000) throw new Error('timed out')
    await new Promise((resolve) => setTimeout(resolve, 15))
  }
}
