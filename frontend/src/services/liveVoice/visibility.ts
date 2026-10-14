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
