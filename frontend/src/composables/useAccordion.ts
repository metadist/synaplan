import { computed, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'

export type AccordionDefaultOpen = 'first' | 'all' | 'none'

/**
 * Independent accordion state (several panels may be open at once).
 * A fresh page starts with every panel closed. A deep link or jump-nav
 * call to `open()` is what unfolds one. Replaces the panel set when `ids`
 * changes (tab switch) so a leftover open id from the previous page never
 * silently hides the new one.
 */
export function useAccordion(
  ids: MaybeRefOrGetter<string[]>,
  options?: { defaultOpen?: AccordionDefaultOpen }
) {
  const defaultOpen = options?.defaultOpen ?? 'none'
  const openIds = ref<Set<string>>(new Set())

  function applyDefault(list: string[]) {
    if (defaultOpen === 'all') {
      openIds.value = new Set(list)
      return
    }
    if (defaultOpen === 'first' && list[0]) {
      openIds.value = new Set([list[0]])
      return
    }
    openIds.value = new Set()
  }

  watch(
    () => toValue(ids).join('\0'),
    () => {
      applyDefault(toValue(ids))
    },
    { immediate: true }
  )

  const isOpen = (id: string): boolean => openIds.value.has(id)

  function toggle(id: string): void {
    const next = new Set(openIds.value)
    if (next.has(id)) {
      next.delete(id)
    } else {
      next.add(id)
    }
    openIds.value = next
  }

  function open(id: string): void {
    if (openIds.value.has(id)) return
    const next = new Set(openIds.value)
    next.add(id)
    openIds.value = next
  }

  function expandAll(): void {
    openIds.value = new Set(toValue(ids))
  }

  function collapseAll(): void {
    openIds.value = new Set()
  }

  const allOpen = computed(() => {
    const list = toValue(ids)
    return list.length > 0 && list.every((id) => openIds.value.has(id))
  })

  return { isOpen, toggle, open, expandAll, collapseAll, allOpen }
}
