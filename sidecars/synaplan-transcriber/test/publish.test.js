import assert from 'node:assert/strict'
import test from 'node:test'
import { loadConfig } from '../src/config.js'
import { notesSavedSentence } from '../src/notes.js'
import { publishMeeting } from '../src/publish.js'

test('the end sentence names what was saved and what was not', () => {
  assert.match(notesSavedSentence([
    { name: 'Synaplan', ok: true, detail: 'Notes saved in Synaplan.' },
    { name: 'Files', ok: false, detail: '' },
  ]), /Notes saved in Synaplan/)
  assert.match(notesSavedSentence([
    { name: 'Synaplan', ok: true, detail: 'Notes saved in Synaplan.' },
    { name: 'Files', ok: false, detail: '' },
  ]), /Files did not receive a copy/)
  assert.match(notesSavedSentence([
    { name: 'Synaplan', ok: false, detail: '' },
  ]), /The meeting was not recorded/)
})

test('openDesk mode requires a Synaplan key and can run Jitsi without Element', () => {
  assert.throws(() => loadConfig({ SYNAPLAN_URL: 'http://synaplan', SYNAPLAN_API_KEY: '' }), /SYNAPLAN_API_KEY/)
  assert.throws(
    () => loadConfig({ SYNAPLAN_URL: 'http://synaplan', SYNAPLAN_API_KEY: 'sk_test', TRANSCRIBER_MODE: 'jitsi' }),
    /TRANSCRIBER_AUTH_TOKEN/,
  )
  const config = loadConfig({
    SYNAPLAN_URL: 'http://synaplan/',
    SYNAPLAN_API_KEY: 'sk_test',
    TRANSCRIBER_MODE: 'opendesk',
    TRANSCRIBER_LANGUAGE: 'de',
    TRANSCRIBER_AUTH_TOKEN: 'socket-secret',
  })
  assert.equal(config.jitsi, true)
  assert.equal(config.element, false)
  assert.equal(config.language, 'de')
  assert.equal(config.synaplanUrl, 'http://synaplan')
})

test('a finished meeting is stored and the audio is not', async () => {
  const calls = []
  const fetchImpl = async (url, options) => {
    calls.push({ url, body: options.body })
    return { ok: true, status: 201 }
  }
  const result = await publishMeeting({
    when: new Date('2026-09-28T10:00:00Z'),
    meetingId: 'standup',
    lines: [{ speaker: 'Ada', text: 'Hello' }],
    synaplan: {
      async saveNote(note) {
        calls.push({ note })
        return { id: 'note_1' }
      },
    },
    fetchImpl,
    config: {
      language: 'en',
      saveToSynaplan: true,
      nextcloudUrl: 'https://files.example',
      nextcloudUser: 'notes',
      nextcloudPassword: 'app-secret',
      nextcloudFolder: '/Meetings',
      matrixReady: true,
      matrixRoom: '!room:example',
      matrixHomeserver: 'https://matrix.example',
      matrixToken: 'token',
    },
  })
  assert.equal(calls[0].note.source, 'jitsi')
  assert.equal(calls[0].note.text, 'Ada: Hello')
  assert.match(calls[1].url, /\/remote.php\/dav\/files\/notes\/Meetings\/2026-09-28-100000-standup\.md$/)
  assert.match(calls[2].url, /\/send\/m\.room\.message\//)
  assert.match(result.sentence, /Notes saved in Synaplan/)
  assert.match(result.sentence, /Files \/Meetings\/2026-09-28-100000-standup\.md/)
  assert.match(result.sentence, /!room:example/)
  assert.match(result.markdown, /Audio was not kept/)
  assert.doesNotMatch(result.markdown, /app-secret/)
})
