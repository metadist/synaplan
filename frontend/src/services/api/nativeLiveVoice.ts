/**
 * MOBILE-APP SEAM: the store binary exposes `window.SynaplanLiveVoice` from
 * the app-owned bootstrap (`synaplan-apps`). That flag is true only when the
 * binary registers the live-voice plugin. Older binaries omit it, so the
 * button stays hidden. The plain web build never loads the bootstrap.
 */

interface NativeLiveVoiceApi {
  isAvailable: () => boolean
}

/** True when this binary can show the live-conversation button. */
export function hasNativeLiveVoice(): boolean {
  const api = (globalThis as { SynaplanLiveVoice?: unknown }).SynaplanLiveVoice
  if (
    !api ||
    'object' !== typeof api ||
    'function' !== typeof (api as NativeLiveVoiceApi).isAvailable
  ) {
    return false
  }
  try {
    return true === (api as NativeLiveVoiceApi).isAvailable()
  } catch {
    return false
  }
}
