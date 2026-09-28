import assert from 'node:assert/strict'
import test from 'node:test'
import { createMeetingSession } from '../src/jitsiSession.js'
import { STT_DOWN } from '../src/matrix.js'

const packet = Buffer.from([0xf8, 0x11, 0x22]).toString('base64')

function media(tag) {
  return { event: 'media', media: { tag, chunk: 1, timestamp: 1, payload: packet } }
}

test('a Jitsi socket returns pong and a final caption', async () => {
  const seen = []
  const session = createMeetingSession({
    meetingId: 'standup',
    commitAfterMs: 1000,
    language: 'de',
    synaplan: {
      async transcribeOgg({ meetingId, speaker, audio }) {
        seen.push({ meetingId, speaker, ogg: audio.subarray(0, 4).toString() })
        return { text: 'Guten Morgen', language: 'de' }
      },
    },
  })
  const out = []
  const send = (message) => out.push(message)
  await session.handle({ event: 'ping', id: 7 }, send)
  await session.handle(media('ada'), send)
  await session.handle({ event: 'stop', stop: { tag: 'ada' } }, send)

  assert.deepEqual(out[0], { event: 'pong', id: 7 })
  assert.equal(out[1].type, 'transcription-result')
  assert.equal(out[1].is_interim, false)
  assert.equal(out[1].transcript[0].text, 'Guten Morgen')
  assert.equal(out[1].participant.id, 'ada')
  assert.equal(out[1].language, 'de')
  assert.equal(seen[0].meetingId, 'standup')
  assert.equal(seen[0].speaker, 'ada')
  assert.equal(seen[0].ogg, 'OggS')
})

test('a speech engine failure is one caption, then silence', async () => {
  let calls = 0
  const session = createMeetingSession({
    meetingId: 'standup',
    commitAfterMs: 1000,
    synaplan: {
      async transcribeOgg() {
        calls += 1
        throw new Error('down')
      },
    },
  })
  const out = []
  await session.handle(media('ada'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'ada' } }, (message) => out.push(message))
  await session.handle(media('bea'), () => {})
  await session.handle({ event: 'stop', stop: { tag: 'bea' } }, (message) => out.push(message))
  assert.equal(calls, 2)
  assert.equal(out.length, 1)
  assert.equal(out[0].transcript[0].text, STT_DOWN)
})
