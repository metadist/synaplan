/**
 * MOBILE-APP SEAM: the current store binary has no live-voice audio path.
 * The button stays hidden there until a binary exposes `liveVoice`.
 */
export function hasNativeLiveVoice(): boolean {
  return false
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
