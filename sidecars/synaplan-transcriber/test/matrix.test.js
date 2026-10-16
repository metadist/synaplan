import assert from 'node:assert/strict'
import test from 'node:test'
import { classifyMatrixEvent, planSync, threadReply } from '../src/matrix.js'

test('a voice message is transcribed and an encrypted one is refused', () => {
  assert.equal(classifyMatrixEvent({
    type: 'm.room.message',
    event_id: '$abc',
    content: {
      msgtype: 'm.audio',
      url: 'mxc://example/file',
      'org.matrix.msc3245.voice': {},
    },
  }).action, 'transcribe')

  assert.equal(classifyMatrixEvent({
    type: 'm.room.message',
    content: { msgtype: 'm.audio', file: { url: 'mxc://example/file' } },
  }).action, 'locked')

  assert.equal(classifyMatrixEvent({ type: 'm.room.encrypted', content: {} }).action, 'locked')
  assert.equal(classifyMatrixEvent({
    type: 'm.room.message',
    content: { msgtype: 'm.text', body: 'hello' },
  }).action, 'ignore')
})

test('a locked room is announced once, and our own messages are skipped', () => {
  const locked = new Set()
  const body = {
    rooms: {
      join: {
        '!room:example': {
          timeline: {
            events: [
              { type: 'm.room.encrypted', sender: '@a:example', event_id: '$1' },
              { type: 'm.room.encrypted', sender: '@a:example', event_id: '$2' },
              { type: 'm.room.message', sender: '@notes:example', content: { msgtype: 'm.audio', url: 'mxc://example/x' } },
              {
                type: 'm.room.message',
                sender: '@a:example',
                event_id: '$3',
                content: { msgtype: 'm.audio', url: 'mxc://example/voice' },
              },
            ],
          },
        },
      },
    },
  }
  const jobs = planSync(body, '@notes:example', locked)
  assert.deepEqual(jobs.map((job) => job.type), ['locked', 'transcribe'])
  assert.equal(planSync(body, '@notes:example', locked).length, 1)
  assert.equal(planSync(body, '@notes:example', locked)[0].type, 'transcribe')
})

test('a thread reply points at the voice message', () => {
  const reply = threadReply('$abc', 'Hello')
  assert.equal(reply.body, 'Hello')
  assert.equal(reply['m.relates_to'].event_id, '$abc')
  assert.equal(reply['m.relates_to']['m.in_reply_to'].event_id, '$abc')
})
