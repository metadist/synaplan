import { onMounted, onUnmounted, ref, type Ref } from 'vue'

/** Whether a CSS media query matches, kept current while the component is mounted. */
export function useMediaQuery(query: string): Ref<boolean> {
  const list = typeof window.matchMedia === 'function' ? window.matchMedia(query) : null
  const matches = ref(list?.matches ?? false)
  const sync = (): void => {
    matches.value = list?.matches ?? false
  }
  onMounted(() => {
    sync()
    list?.addEventListener('change', sync)
  })
  onUnmounted(() => list?.removeEventListener('change', sync))
  return matches
}
