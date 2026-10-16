import { hasNativeLiveVoice as binaryHasLiveVoice } from '@/services/api/nativeLiveVoice'

/**
 * MOBILE-APP SEAM: older store binaries have no live-voice path. The button
 * stays hidden until a binary exposes `window.SynaplanLiveVoice`.
 */
export function hasNativeLiveVoice(): boolean {
  return binaryHasLiveVoice()
}

export function liveVoiceButtonVisible(input: {
  enabled: boolean
  guest: boolean
  nativeApp: boolean
  nativeLiveVoice?: boolean
}): boolean {
  if (!input.enabled || input.guest) return false
  if (input.nativeApp && !(input.nativeLiveVoice ?? hasNativeLiveVoice())) return false
  return true
}

/**
 * Live speech takes the send button's place only while the composer has
 * nothing to send. Typed text, an attached file or a pasted block brings
 * the send button back, and a running reply keeps its stop button.
 */
export function liveVoiceReplacesSend(input: {
  available: boolean
  streaming: boolean
  hasText: boolean
  hasAttachment: boolean
}): boolean {
  return input.available && !input.streaming && !input.hasText && !input.hasAttachment
}
