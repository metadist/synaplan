import { onUnmounted } from 'vue'

type StopFn = () => void

/**
 * Module-level pointer to the stop callback of the audio player that is
 * currently playing. Shared across every `useAudioPlayback()` consumer so only
 * one inline audio plays at a time (issue #1078) — the same behaviour as
 * WhatsApp, Telegram and other messaging apps with inline voice notes.
 */
let activeStop: StopFn | null = null

/**
 * Coordinates inline audio players so that starting one pauses any other that
 * is currently playing.
 */
export function useAudioPlayback() {
  let ownStop: StopFn | null = null

  /**
   * Mark this player as the active one, pausing whichever player was playing
   * before. Call this right after playback actually starts.
   */
  const setActive = (stop: StopFn): void => {
    if (activeStop && activeStop !== ownStop) {
      activeStop()
    }
    ownStop = stop
    activeStop = stop
  }

  /**
   * Release the active slot if this player currently holds it (on pause/end).
   */
  const clearActive = (): void => {
    if (activeStop === ownStop) {
      activeStop = null
    }
    ownStop = null
  }

  onUnmounted(() => {
    if (activeStop === ownStop) {
      activeStop = null
    }
    ownStop = null
  })

  return { setActive, clearActive }
}
