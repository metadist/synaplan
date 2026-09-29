import { LOCKED_ROOM, NO_SPEECH, mxcPath, planSync, roomNotice, threadReply } from './matrix.js'
import { publishMeeting } from './publish.js'

function txn() {
  return `synaplan-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
}

/**
 * Matrix client for Element voice messages. It does not join calls.
 */
export function createElementBot({
  homeserver,
  accessToken,
  userId,
  synaplan,
  model,
  language,
  config = null,
  fetchImpl = fetch,
  onLog = () => {},
}) {
  const root = homeserver.replace(/\/$/, '')
  const lockedRooms = new Set()
  let since = null
  let stopped = false

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {})
    headers.set('Authorization', `Bearer ${accessToken}`)
    const response = await fetchImpl(`${root}${path}`, { ...options, headers })
    if (!response.ok) {
      const error = new Error(`Matrix returned ${response.status} for ${path}`)
      error.status = response.status
      throw error
    }
    if (response.status === 204) {
      return null
    }
    const type = response.headers.get('content-type') || ''
    if (type.includes('application/json') || type.includes('+json')) {
      return response.json()
    }
    return Buffer.from(await response.arrayBuffer())
  }

  async function send(roomId, content) {
    await api(`/_matrix/client/v3/rooms/${encodeURIComponent(roomId)}/send/m.room.message/${txn()}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(content),
    })
  }

  async function handleJob(job) {
    if (job.type === 'locked') {
      await send(job.roomId, roomNotice(LOCKED_ROOM))
      return
    }
    const path = mxcPath(job.mxc)
    if (!path) {
      return
    }
    let bytes
    try {
      bytes = await api(`/_matrix/client/v1/media/download/${path}`)
    } catch (error) {
      onLog(`Could not download a voice message: ${error.message}`)
      return
    }
    if (!Buffer.isBuffer(bytes)) {
      bytes = Buffer.from(bytes)
    }
    let text = ''
    try {
      const result = await synaplan.transcribeFile({
        client: `${job.roomId}:${job.eventId || 'audio'}`,
        filename: 'voice.ogg',
        bytes,
        contentType: 'audio/ogg',
        model,
        language,
      })
      text = result.text
    } catch (error) {
      onLog(`Voice message was not transcribed: ${error.message}`)
      if (job.eventId) {
        await send(job.roomId, threadReply(job.eventId, 'Notes could not be saved. The voice message was not transcribed. Try again or Disconnect.'))
      }
      return
    }
    let filePath = ''
    if (config) {
      try {
        const published = await publishMeeting({
          config,
          synaplan,
          meetingId: job.eventId || 'voice',
          lines: [{ speaker: 'Voice message', text: text || NO_SPEECH }],
          source: 'element',
          room: job.roomId,
          postToMatrixRoom: false,
          fetchImpl,
        })
        filePath = published.filePath || ''
      } catch (error) {
        onLog(`Voice message was transcribed but not saved: ${error.message}`)
      }
    }
    const saved = filePath ? `\n\nSaved to Files ${filePath}. Audio was not kept.` : ''
    const body = `${text || NO_SPEECH}${saved}`
    if (job.eventId) {
      await send(job.roomId, threadReply(job.eventId, body))
    } else {
      await send(job.roomId, roomNotice(body))
    }
  }

  async function tick() {
    const params = new URLSearchParams({ timeout: '30000' })
    if (since) {
      params.set('since', since)
    }
    const body = await api(`/_matrix/client/v3/sync?${params}`)
    since = body.next_batch
    const jobs = planSync(body, userId, lockedRooms)
    for (const job of jobs) {
      await handleJob(job)
    }
  }

  return {
    async run() {
      while (!stopped) {
        try {
          await tick()
        } catch (error) {
          onLog(`Element sync paused: ${error.message}`)
          await new Promise((resolve) => {
            setTimeout(resolve, 5000)
          })
        }
      }
    },
    stop() {
      stopped = true
    },
  }
}
