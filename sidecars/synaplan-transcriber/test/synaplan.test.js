import assert from 'node:assert/strict'
import { once } from 'node:events'
import { createServer } from 'node:http'
import test from 'node:test'
import { createSynaplanClient } from '../src/synaplan.js'

// A real HTTP server instead of a stubbed fetch, so the runtime's own fetch,
// FormData and Blob are what put the bytes on the wire.
async function fakeSynaplan(t, respond) {
  const requests = []
  const server = createServer(async (request, response) => {
    const chunks = []
    for await (const chunk of request) {
      chunks.push(chunk)
    }
    const seen = { method: request.method, url: request.url, headers: request.headers, body: Buffer.concat(chunks) }
    requests.push(seen)
    const [status, body] = respond(seen)
    response.writeHead(status, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify(body))
  })
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  t.after(() => new Promise((resolve) => server.close(resolve)))
  return { baseUrl: `http://127.0.0.1:${server.address().port}`, requests }
}

function sessionFlow(appendResponse) {
  return ({ method, url }) => {
    if (method === 'POST' && url === '/v1/audio/transcriptions/sessions') {
      return [201, { id: 'stt_1', status: 'open' }]
    }
    if (method === 'POST') {
      return appendResponse
    }
    return [200, { id: 'stt_1', status: 'closed' }]
  }
}

test('a meeting window is sent as Ogg and the session is closed', async (t) => {
  const { baseUrl, requests } = await fakeSynaplan(t, sessionFlow([200, {
    id: 'stt_1',
    language: 'de',
    segments: [{ text: 'Hallo' }, { text: ' Guten Morgen ' }],
  }]))
  const audio = Buffer.from([0x4f, 0x67, 0x67, 0x53, 0x00, 0xff, 0x80])
  const client = createSynaplanClient({ baseUrl, apiKey: 'sk_test' })

  const result = await client.transcribeOgg({ meetingId: 'standup', speaker: 'ada', audio, language: 'de' })

  assert.deepEqual(result, { text: 'Guten Morgen', language: 'de' })
  assert.deepEqual(requests.map(({ method, url }) => `${method} ${url}`), [
    'POST /v1/audio/transcriptions/sessions',
    'POST /v1/audio/transcriptions/sessions/stt_1/audio?commit=true',
    'DELETE /v1/audio/transcriptions/sessions/stt_1',
  ])
  assert.ok(requests.every(({ headers }) => headers.authorization === 'Bearer sk_test'))
  const created = JSON.parse(requests[0].body.toString())
  assert.equal(created.client_id, 'jitsi:standup:ada')
  assert.equal(created.encoding, 'ogg')
  assert.equal(created.language, 'de')
  assert.equal(requests[1].headers['content-type'], 'audio/ogg')
  assert.deepEqual(requests[1].body, audio)
})

test('a voice message is uploaded as a file with its model and language', async (t) => {
  const { baseUrl, requests } = await fakeSynaplan(t, () => [200, { id: 'stt_2', text: ' Hello there ', language: 'en' }])
  const bytes = Buffer.from([0x4f, 0x67, 0x67, 0x53, 0x00, 0xff, 0x0d, 0x0a])
  const client = createSynaplanClient({ baseUrl, apiKey: 'sk_test' })

  const result = await client.transcribeFile({
    client: '!room:example:$voice',
    filename: 'voice.ogg',
    bytes,
    contentType: 'audio/ogg',
    model: 'whisper',
    language: 'en',
  })

  assert.deepEqual(result, { text: 'Hello there', language: 'en' })
  assert.equal(requests[0].url, '/v1/audio/transcriptions')
  assert.equal(requests[0].headers.authorization, 'Bearer sk_test')
  const form = await new Response(requests[0].body, {
    headers: { 'Content-Type': requests[0].headers['content-type'] },
  }).formData()
  const file = form.get('file')
  assert.equal(file.name, 'voice.ogg')
  assert.equal(file.type, 'audio/ogg')
  assert.deepEqual(Buffer.from(await file.arrayBuffer()), bytes)
  assert.equal(form.get('client_id'), 'matrix:_room:example:_voice')
  assert.equal(form.get('model'), 'whisper')
  assert.equal(form.get('language'), 'en')
})

test('a Synaplan error keeps its message and still closes the session', async (t) => {
  const { baseUrl, requests } = await fakeSynaplan(t, sessionFlow([409, {
    error: { message: 'Session is closed', type: 'invalid_request_error', param: null, code: 'session_closed' },
  }]))
  const client = createSynaplanClient({ baseUrl, apiKey: 'sk_test' })

  await assert.rejects(
    client.transcribeOgg({ meetingId: 'standup', speaker: 'ada', audio: Buffer.from('OggS') }),
    { message: 'Session is closed', status: 409 },
  )
  assert.equal(requests.at(-1).method, 'DELETE')
  assert.equal(requests.at(-1).url, '/v1/audio/transcriptions/sessions/stt_1')
})
