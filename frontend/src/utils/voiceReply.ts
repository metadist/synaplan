import type { Message } from '@/stores/history'
import { pushMediaPart } from '@/utils/mediaParts'

/** Reason codes emitted by SSE `voice_reply_failed` / stored on OUT message meta (#2282). */
export type VoiceReplyFailedReason = 'provider_error' | 'empty_text' | 'rate_limited'

const VOICE_REPLY_FAILED_REASONS = new Set<string>([
  'provider_error',
  'empty_text',
  'rate_limited',
])

export function isVoiceReplyFailedReason(value: unknown): value is VoiceReplyFailedReason {
  return typeof value === 'string' && VOICE_REPLY_FAILED_REASONS.has(value)
}

/**
 * Apply a terminal voice-reply failure to the assistant message: clear the
 * TTS loading indicator and store the reason for the on-message sentence.
 */
export function applyVoiceReplyFailed(
  message: Message,
  reason: VoiceReplyFailedReason
): void {
  message.parts = message.parts.filter((p) => p.type !== 'tts_loading')
  message.voiceReplyFailed = reason
}

/**
 * Attach a successful voice-reply audio part, clearing any TTS loading
 * indicator. Used for both single-bubble and task-plan turns (#2282).
 */
export function attachVoiceReplyAudio(
  message: Message,
  absoluteUrl: string,
  options?: { autoplay?: boolean }
): void {
  const loadingIdx = message.parts.findIndex((p) => p.type === 'tts_loading')
  const isVoiceReply = loadingIdx !== -1
  if (isVoiceReply) {
    message.parts.splice(loadingIdx, 1)
  }
  // A successful audio delivery clears any prior failure notice on this turn.
  message.voiceReplyFailed = undefined
  pushMediaPart(message, 'audio', absoluteUrl, {
    autoplay: options?.autoplay ?? isVoiceReply,
  })
}

/**
 * Multitask turns suppress generic file/links events while task cards are the
 * live surface. Voice-reply audio and TTS status must still attach live (#2282).
 */
export function isTaskPlanSuppressedMediaStatus(status: string | undefined): boolean {
  return status === 'file' || status === 'links'
}
