import { ref } from 'vue'

const hidden = ref(false)
const SCROLL_THRESHOLD = 8

/**
 * Shared reactive state for the mobile header visibility.
 * Scroll-aware containers call `onScroll(scrollTop)` on every scroll
 * event; the composable tracks direction and toggles visibility.
 * Header.vue reads `hidden` to drive its CSS transform.
 *
 * Module-level ref ensures all consumers share the same state without
 * a store or provide/inject ceremony.
 */
export function useHeaderVisibility() {
  let lastScrollTop = 0

  const onScroll = (scrollTop: number) => {
    const delta = scrollTop - lastScrollTop
    if (delta > SCROLL_THRESHOLD) {
      hidden.value = true
    } else if (delta < -SCROLL_THRESHOLD) {
      hidden.value = false
    }
    lastScrollTop = scrollTop
  }

  const show = () => {
    hidden.value = false
  }

  return { hidden, onScroll, show }
}
