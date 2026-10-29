import { onUnmounted, ref, watch, type Ref } from 'vue'

/** How many frames the text that has arrived but is not shown yet takes to catch up. */
const CATCH_UP_FRAMES = 40

/**
 * The text a person reads while it is being written. The run's events
 * arrive in pieces a poll apart; this lets the words that arrived run in
 * at reading pace instead of jumping in as a block, and catches up faster
 * the more is waiting, so it never falls behind the run. Once `live` is
 * off (the row is no longer the one happening now) the whole text shows.
 * A text that changed rather than grew keeps the length already shown.
 * With reduced motion the text shows at once.
 */
export function useRevealText(source: () => string, live: () => boolean): Ref<string> {
  const shown = ref(live() ? '' : source())
  let frame: number | null = null
  const reduced =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches

  function stop(): void {
    if (frame === null) return
    cancelAnimationFrame(frame)
    frame = null
  }

  function step(): void {
    frame = null
    const target = source()
    if (shown.value.length >= target.length) {
      shown.value = target
      return
    }
    const waiting = target.length - shown.value.length
    const next = shown.value.length + Math.max(1, Math.ceil(waiting / CATCH_UP_FRAMES))
    shown.value = target.slice(0, next)
    frame = requestAnimationFrame(step)
  }

  watch(
    [source, live],
    ([target, isLive]) => {
      if (!isLive || reduced || typeof requestAnimationFrame !== 'function') {
        stop()
        shown.value = target
        return
      }
      if (!target.startsWith(shown.value)) shown.value = target.slice(0, shown.value.length)
      if (frame === null) frame = requestAnimationFrame(step)
    },
    { immediate: true }
  )

  onUnmounted(stop)

  return shown
}
