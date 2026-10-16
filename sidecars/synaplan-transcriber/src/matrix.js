export const LOCKED_ROOM = 'This room is locked. Invite Meeting notes as a member, or turn captions off.'
export const NO_SPEECH = 'No speech in this note.'
export const STT_DOWN = 'Notes could not be saved. The meeting was not recorded. Try again or Disconnect.'
export const ELEMENT_CALL_LATER = 'Element Call live captions are not in this version. Meeting notes join a call only as a visible participant, and that join is not available yet. Voice messages in Element are transcribed when Element mode is on.'

/**
 * Decide what to do with one Matrix event. Encrypted events are refused:
 * we do not break Megolm, and we do not guess that a locked event was audio.
 *
 * @param {object} event
 */
export function classifyMatrixEvent(event) {
  if (!event || typeof event !== 'object') {
    return { action: 'ignore' }
  }
  if (event.type === 'm.room.encrypted' || event.type === 'm.room.encryption') {
    return { action: 'locked' }
  }
  if (event.type !== 'm.room.message') {
    return { action: 'ignore' }
  }
  const content = event.content && typeof event.content === 'object' ? event.content : {}
  if (content.msgtype !== 'm.audio') {
    return { action: 'ignore' }
  }
  if (content.file) {
    return { action: 'locked' }
  }
  if (typeof content.url !== 'string' || !content.url.startsWith('mxc://')) {
    return { action: 'ignore' }
  }
  return {
    action: 'transcribe',
    mxc: content.url,
    eventId: typeof event.event_id === 'string' ? event.event_id : '',
    voice: Boolean(content['org.matrix.msc3245.voice'] || content['org.matrix.msc1767.audio']),
  }
}

export function mxcPath(mxc) {
  const match = /^mxc:\/\/([^/]+)\/([^?#]+)$/.exec(mxc)
  if (!match) {
    return null
  }
  return `${encodeURIComponent(match[1])}/${encodeURIComponent(match[2])}`
}

export function threadReply(eventId, body) {
  return {
    msgtype: 'm.notice',
    body,
    'm.relates_to': {
      rel_type: 'm.thread',
      event_id: eventId,
      is_falling_back: true,
      'm.in_reply_to': { event_id: eventId },
    },
  }
}

export function roomNotice(body) {
  return { msgtype: 'm.notice', body }
}

/**
 * Walk one /sync body. Returns work items. One locked notice per room.
 *
 * @param {object} syncBody
 * @param {string} selfUserId
 * @param {Set<string>} lockedRooms rooms that already received the refusal
 */
export function planSync(syncBody, selfUserId, lockedRooms) {
  const jobs = []
  const joined = syncBody?.rooms?.join && typeof syncBody.rooms.join === 'object'
    ? syncBody.rooms.join
    : {}

  for (const [roomId, room] of Object.entries(joined)) {
    const events = [
      ...(room.state?.events || []),
      ...(room.timeline?.events || []),
    ]
    for (const event of events) {
      if (!event || event.sender === selfUserId) {
        continue
      }
      const decision = classifyMatrixEvent(event)
      if (decision.action === 'transcribe') {
        jobs.push({ type: 'transcribe', roomId, eventId: decision.eventId, mxc: decision.mxc })
        continue
      }
      if (decision.action === 'locked' && !lockedRooms.has(roomId)) {
        lockedRooms.add(roomId)
        jobs.push({ type: 'locked', roomId })
      }
    }
  }

  return jobs
}
