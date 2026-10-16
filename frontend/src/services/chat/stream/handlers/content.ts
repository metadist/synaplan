/**
 * What the answer is made of while it streams: the text, its reasoning, the
 * media and links it carries, and the spoken reply. While a task plan runs,
 * the task cards are the live surface for media and links; the answer text
 * still accumulates, because the reply node has no card of its own (#1057).
 */
import { consumeVisibleAnswer } from '@/utils/processingTimeline'
import { pushMediaPart } from '@/utils/mediaParts'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import {
  applyVoiceReplyFailed,
  attachVoiceReplyAudio,
  isVoiceReplyFailedReason,
  shouldAutoplayVoiceReply,
} from '@/utils/voiceReply'
import { appendReasoning, applyDocsToMessage, linksPart } from '../messageUpdates'
import type { MemberTurn, StreamHandlers, StreamStatusHandler, StreamTurn } from '../streamTurn'

const MEDIA_TYPES = ['image', 'video', 'audio'] as const

function planIsRunning(turn: StreamTurn): boolean {
  return turn.message()?.taskPlan?.active === true
}

/** Appends a chunk to the answer; the first visible token folds the live thinking panel away. */
function receiveText(turn: StreamTurn, chunk: string): void {
  const visible = consumeVisibleAnswer(chunk, turn.text.thinkOpen)
  turn.text.thinkOpen = visible.insideThink
  if (visible.text.trim() !== '') {
    if (turn.status.current()) {
      turn.status.clear()
    }
    turn.history.finishLiveThinking(turn.messageId)
  }
  turn.text.content += chunk
}

const data: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  if (!payload.chunk) return
  if (planIsRunning(turn)) {
    turn.text.content += payload.chunk
    return
  }
  receiveText(turn, payload.chunk)
  turn.smoother.push(turn.text.content)
}

/** A spoken reply hears every sentence as it arrives. */
const dataForMember: StreamStatusHandler<MemberTurn> = (payload, turn) => {
  if (!payload.chunk) return
  if (planIsRunning(turn)) {
    turn.text.content += payload.chunk
    return
  }
  receiveText(turn, payload.chunk)
  turn.voice.feed(payload.chunk)
  turn.smoother.push(turn.text.content)
}

const reasoning: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  const message = turn.message()
  if (message && payload.chunk) {
    appendReasoning(message, payload.chunk, 'first')
  }
}

/** Single-capability media outside a plan; the stable part id keeps it through text reconciles (#625). */
const file: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  if (planIsRunning(turn)) return
  const message = turn.message()
  const type = MEDIA_TYPES.find((candidate) => candidate === payload.type)
  if (message && payload.url && type) {
    pushMediaPart(message, type, normalizeMediaUrl(payload.url))
  }
}

const ttsGenerating: StreamStatusHandler<StreamTurn> = (_payload, turn) => {
  turn.message()?.parts.push({ type: 'tts_loading' })
}

const audio: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  const message = turn.message()
  if (message && payload.url) {
    attachVoiceReplyAudio(message, normalizeMediaUrl(payload.url))
  }
}

/**
 * The stored spoken reply plays unless sentence streaming already spoke the
 * answer. An incognito turn's audio is ephemeral: the session end deletes it.
 */
const audioForMember: StreamStatusHandler<MemberTurn> = (payload, turn) => {
  if (turn.incognito && typeof payload.file_id === 'number') {
    turn.registerIncognitoFile(payload.file_id)
  }
  const message = turn.message()
  if (message && payload.url) {
    const autoplay = shouldAutoplayVoiceReply(
      message.parts.some((part) => part.type === 'tts_loading'),
      turn.voice.heardText()
    )
    attachVoiceReplyAudio(message, normalizeMediaUrl(payload.url), { autoplay })
  }
}

const voiceReplyFailed: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  const message = turn.message()
  if (message && isVoiceReplyFailedReason(payload.reason)) {
    applyVoiceReplyFailed(message, payload.reason)
  }
}

const voiceReplyFailedForMember: StreamStatusHandler<MemberTurn> = (payload, turn) => {
  voiceReplyFailed(payload, turn)
  turn.voice.stop()
}

const links: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  if (planIsRunning(turn)) return
  const message = turn.message()
  if (message && payload.links) {
    message.parts.push(linksPart(payload.links))
  }
}

const docsLoaded: StreamStatusHandler<StreamTurn> = (payload, turn) => {
  const message = turn.message()
  if (message) {
    applyDocsToMessage(message, payload.metadata?.docs)
  }
}

const runStarted: StreamStatusHandler<StreamTurn> = (payload, turn) =>
  turn.run.noteStarted(payload.runId)

export const contentHandlers: StreamHandlers<StreamTurn> = {
  run_started: runStarted,
  data,
  reasoning,
  file,
  tts_generating: ttsGenerating,
  audio,
  voice_reply_failed: voiceReplyFailed,
  links,
  docs_loaded: docsLoaded,
}

export const memberContentHandlers: StreamHandlers<MemberTurn> = {
  data: dataForMember,
  audio: audioForMember,
  voice_reply_failed: voiceReplyFailedForMember,
}
