import { roomNotice } from './matrix.js'
import { meetingMarkdown, notesSavedSentence, safeFilename } from './notes.js'
import { putNextcloudMarkdown } from './nextcloud.js'

function txn() {
  return `synaplan-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
}

async function postMatrixNotice({ homeserver, accessToken, roomId, body, fetchImpl }) {
  const response = await fetchImpl(
    `${homeserver}/_matrix/client/v3/rooms/${encodeURIComponent(roomId)}/send/m.room.message/${txn()}`,
    {
      method: 'PUT',
      headers: {
        Authorization: `Bearer ${accessToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(roomNotice(body)),
    },
  )
  if (!response.ok) {
    throw new Error(`The chat room refused the note (${response.status})`)
  }
}

/**
 * After Stop or hangup: keep the text, discard the audio, and say
 * exactly where the notes went.
 */
export async function publishMeeting({ config, synaplan, meetingId, lines, when = new Date(), fetchImpl = fetch }) {
  const markdown = meetingMarkdown({
    title: 'Meeting notes',
    when: when.toISOString(),
    language: config.language,
    lines,
  })
  const text = lines.length
    ? lines.map((line) => `${line.speaker}: ${line.text}`).join('\n')
    : 'No speech in this meeting.'
  const destinations = []
  let filePath = ''

  if (config.saveToSynaplan) {
    try {
      await synaplan.saveNote({
        source: 'jitsi',
        text,
        room: config.matrixRoom || meetingId,
        folder: config.nextcloudFolder,
        language: config.language,
        meeting_id: String(meetingId || ''),
        started_by: 'jitsi',
      })
      destinations.push({ name: 'Synaplan', ok: true, detail: 'Notes saved in Synaplan.' })
    } catch {
      destinations.push({ name: 'Synaplan', ok: false, detail: '' })
    }
  }

  if (config.nextcloudUrl && config.nextcloudUser && config.nextcloudPassword) {
    try {
      const saved = await putNextcloudMarkdown({
        baseUrl: config.nextcloudUrl,
        user: config.nextcloudUser,
        password: config.nextcloudPassword,
        folder: config.nextcloudFolder,
        filename: safeFilename(meetingId, when),
        markdown,
        fetchImpl,
      })
      filePath = saved.path
      destinations.push({ name: 'Files', ok: true, detail: `A copy is in Files ${saved.path}.` })
    } catch {
      destinations.push({ name: 'Files', ok: false, detail: '' })
    }
  }

  if (config.matrixReady && config.matrixRoom) {
    const clock = when.toISOString().slice(11, 16)
    const line = filePath
      ? `Notes from ${clock} — open in Files ${filePath}. Audio was not kept.`
      : `Notes from ${clock}. ${text.split('\n')[0] || 'No speech in this meeting.'} Audio was not kept.`
    try {
      await postMatrixNotice({
        homeserver: config.matrixHomeserver,
        accessToken: config.matrixToken,
        roomId: config.matrixRoom,
        body: line,
        fetchImpl,
      })
      destinations.push({ name: 'the chat room', ok: true, detail: `A line was posted in ${config.matrixRoom}.` })
    } catch {
      destinations.push({ name: 'the chat room', ok: false, detail: '' })
    }
  }

  return {
    markdown,
    filePath,
    sentence: notesSavedSentence(destinations, { hadSpeech: lines.length > 0 }),
  }
}
