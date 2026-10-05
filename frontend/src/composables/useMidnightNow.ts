import { onBeforeUnmount, readonly, ref, type Ref } from 'vue'

/**
 * A "now" that refreshes at the next local midnight (and every midnight
 * after). Date-grouped lists (sidebar chat history) must not freeze their
 * "Today" heading when the app stays mounted across midnight: time passing
 * invalidates no other reactive state, so a plain `new Date()` in a computed
 * goes stale until some unrelated mutation re-evaluates it.
 */
export function useMidnightNow(): Readonly<Ref<Date>> {
  const now = ref(new Date())
  let timer: ReturnType<typeof setTimeout> | null = null

  const arm = (): void => {
    if (timer !== null) clearTimeout(timer)
    const current = new Date()
    const nextMidnight = new Date(current.getFullYear(), current.getMonth(), current.getDate() + 1)
    timer = setTimeout(
      () => {
        now.value = new Date()
        arm()
      },
      Math.max(0, nextMidnight.getTime() - current.getTime())
    )
  }

  arm()

  onBeforeUnmount(() => {
    if (timer !== null) clearTimeout(timer)
  })

  return readonly(now)
}
