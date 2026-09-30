import assert from 'node:assert/strict'
import { once } from 'node:events'
import { createServer } from 'node:http'
import test from 'node:test'
import { createElementBot } from '../src/elementBot.js'

function json(response, body) {
  response.writeHead(200, { 'Content-Type': 'application/json' })
  response.end(JSON.stringify(body))
}

test('a voice message is downloaded, transcribed, and answered in its thread', async (t) => {
  const voice = Buffer.from([0x4f, 0x67, 0x67, 0x53, 0x00, 0xff, 0x0d, 0x0a])
  const voiceEvent = {
    type: 'm.room.message',
    sender: '@ada:example',
    event_id: '$voice',
    content: { msgtype: 'm.audio', url: 'mxc://example/voice1', 'org.matrix.msc3245.voice': {} },
  }
  const authorizations = []
  const replies = []
  let syncs = 0
  let bot

  const homeserver = createServer(async (request, response) => {
    const chunks = []
    for await (const chunk of request) {
      chunks.push(chunk)
    }
    authorizations.push(request.headers.authorization)
    const { pathname } = new URL(request.url, 'http://homeserver')
    if (pathname === '/_matrix/client/v3/sync') {
      syncs += 1
      // The second sync ends the run whether or not the first one worked.
      if (syncs > 1) {
        bot.stop()
      }
      const events = syncs === 1 ? [voiceEvent] : []
      json(response, { next_batch: `batch_${syncs}`, rooms: { join: { '!room:example': { timeline: { events } } } } })
      return
    }
    if (pathname === '/_matrix/client/v1/media/download/example/voice1') {
      response.writeHead(200, { 'Content-Type': 'audio/ogg' })
      response.end(voice)
      return
    }
    if (request.method === 'PUT' && pathname.startsWith('/_matrix/client/v3/rooms/!room%3Aexample/send/m.room.message/')) {
      replies.push(JSON.parse(Buffer.concat(chunks).toString()))
      json(response, { event_id: '$reply' })
      return
    }
    response.writeHead(404)
    response.end()
  })
  homeserver.listen(0, '127.0.0.1')
  await once(homeserver, 'listening')
  t.after(() => new Promise((resolve) => homeserver.close(resolve)))

  const heard = []
  const logs = []
  bot = createElementBot({
    homeserver: `http://127.0.0.1:${homeserver.address().port}`,
    accessToken: 'mx_token',
    userId: '@notes:example',
    language: 'en',
    synaplan: {
      async transcribeFile(input) {
        heard.push(input)
        return { text: 'Hello from Ada', language: 'en' }
      },
    },
    onLog: (line) => logs.push(line),
  })

  await bot.run()

  assert.deepEqual(logs, [])
  assert.equal(heard.length, 1)
  assert.ok(Buffer.isBuffer(heard[0].bytes))
  assert.deepEqual(heard[0].bytes, voice)
  assert.equal(heard[0].client, '!room:example:$voice')
  assert.equal(replies.length, 1)
  assert.equal(replies[0].body, 'Hello from Ada')
  assert.equal(replies[0]['m.relates_to'].event_id, '$voice')
  assert.ok(authorizations.every((value) => value === 'Bearer mx_token'))
})
