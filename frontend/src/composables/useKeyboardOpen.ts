import { ref, onMounted, onBeforeUnmount, type Ref } from 'vue'

/**
 * Track whether the on-screen / soft keyboard is currently open.
 *
 * iOS Safari and most Android browsers shrink `window.visualViewport.height`
 * when the keyboard appears, while `window.innerHeight` (the layout viewport)
 * stays the same. We treat a meaningful negative delta as "keyboard open".
 *
 * The threshold (`MIN_DELTA_PX`) is intentionally generous: the URL bar / tab
 * bar can collapse by ~60 px during scroll, but the keyboard always eats at
 * least ~150 px on every device we care about. Anything below that is browser
 * chrome and should NOT trigger the keyboard-open state.
 *
 * Why this exists:
 * - `position: sticky; bottom: 0` already follows the visual viewport, so the
 *   chat input bar floats with the keyboard for free.
 * - But `pb-[env(safe-area-inset-bottom)]` (~34 px on iPhones with home
 *   indicator) does NOT zero out when the keyboard is open in iOS Safari, so
 *   the bar carries a permanent ~34 px gap on top of any inner padding.
 * - A consumer that wants to hide that gap only while the keyboard is open
 *   can read `useKeyboardOpen()` and toggle a class accordingly.
 *
 * Defensive notes:
 * - Browsers without `window.visualViewport` (very old Safari, jsdom, some
 *   WebViews) keep the value `false` indefinitely, so styling falls back to
 *   the safe iPhone-home-indicator inset — better than incorrectly flipping
 *   on a viewport we don't trust.
 * - `resize` and `scroll` events both fire on iOS during keyboard show/hide;
 *   we listen to both to avoid lag.
 * - **Pinch-zoom guard**: `visualViewport.height` also shrinks during a
 *   pinch-zoom (the visible viewport gets smaller because each CSS pixel
 *   covers a larger physical area). Without a guard we'd falsely report
 *   "keyboard open" and rip the safe-area inset out from under a user
 *   that's just zooming in to inspect a chart. Only treat the height
 *   delta as a keyboard signal when `scale` is at-or-near 1 (we leave a
 *   ±0.05 tolerance so subpixel rounding on Android Chrome doesn't
 *   create a near-1.0 false negative for short bursts).
 */
export function useKeyboardOpen(): Ref<boolean> {
  const isOpen = ref(false)

  // Empirically: keyboards eat ≥ 150 px even on the smallest iPhones. URL bar
  // collapses are typically ≤ 100 px and must NOT trigger this.
  const MIN_DELTA_PX = 150
  // Tolerance around scale = 1.0 below which a viewport-height delta is
  // attributed to the keyboard, not pinch-zoom. Anything outside this band
  // is almost certainly user zoom — the soft keyboard does not change the
  // visual viewport's `scale`.
  const SCALE_TOLERANCE = 0.05

  const update = (): void => {
    if (typeof window === 'undefined' || !window.visualViewport) {
      return
    }
    // Pinch-zoom shrinks `visualViewport.height` by the same factor as
    // `scale`. We must NOT treat that as keyboard-open or we'll yank the
    // home-indicator inset away while the user is just zooming in.
    if (Math.abs(window.visualViewport.scale - 1) > SCALE_TOLERANCE) {
      isOpen.value = false

      return
    }
    const delta = window.innerHeight - window.visualViewport.height
    isOpen.value = delta >= MIN_DELTA_PX
  }

  onMounted(() => {
    if (typeof window === 'undefined' || !window.visualViewport) {
      return
    }
    update()
    window.visualViewport.addEventListener('resize', update)
    window.visualViewport.addEventListener('scroll', update)
  })

  onBeforeUnmount(() => {
    if (typeof window === 'undefined' || !window.visualViewport) {
      return
    }
    window.visualViewport.removeEventListener('resize', update)
    window.visualViewport.removeEventListener('scroll', update)
  })

  return isOpen
}
