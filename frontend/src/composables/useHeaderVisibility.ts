import { ref } from 'vue'

const HEADER_HEIGHT = 36

/**
 * Shared reactive state for the mobile header visibility.
 * Exposes a `progress` value (0–1) driven by scroll deltas:
 *   0 = header bar fully visible, FAB invisible
 *   1 = header bar hidden, FAB fully visible
 *
 * Delta-based: works regardless of absolute scrollTop, so views
 * that start at the bottom (e.g. ChatView) behave correctly.
 *
 * Module-level ref ensures all consumers share the same state.
 */
const progress = ref(0)

export function useHeaderVisibility() {
  const isVirtualKeyboardOpen = () => {
    const tag = document.activeElement?.tagName
    return (
      tag === 'INPUT' ||
      tag === 'TEXTAREA' ||
      document.activeElement?.getAttribute('contenteditable') === 'true'
    )
  }

  const onScroll = (scrollTop: number) => {
    if (isVirtualKeyboardOpen()) return
    progress.value = Math.min(1, Math.max(0, scrollTop / HEADER_HEIGHT))
  }

  const show = () => {
    progress.value = 0
  }

  const sync = () => {
    progress.value = 1
  }

  return { progress, onScroll, show, sync }
}
