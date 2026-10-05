import { onBeforeUnmount, type Ref } from 'vue'
import { nextTypeaheadTarget } from '@/utils/modelTypeahead'

const TYPEAHEAD_RESET_MS = 500

const focusableItems = (
  defaultRef: Ref<HTMLElement | null>,
  modelRefs: Ref<HTMLElement[]>
): HTMLElement[] =>
  [defaultRef.value, ...modelRefs.value].filter(
    (el): el is HTMLElement => el instanceof HTMLElement
  )

/**
 * Arrow keys and typeahead for the model list. Typing focuses the closest
 * matching name; it does not change the selection until the row is confirmed.
 */
export function useModelListKeyboard(options: {
  defaultRef: Ref<HTMLElement | null>
  modelRefs: Ref<HTMLElement[]>
  labels: () => string[]
}) {
  let query = ''
  let timer: ReturnType<typeof setTimeout> | null = null

  const items = () => focusableItems(options.defaultRef, options.modelRefs)

  const focusIndex = (index: number) => {
    const list = items()
    if (list.length === 0) return
    const wrapped = ((index % list.length) + list.length) % list.length
    const target = list[wrapped]
    target?.focus()
    target?.scrollIntoView({ block: 'nearest' })
  }

  const focusSelected = (modelValue: number | null, modelIds: number[]) => {
    const list = items()
    if (list.length === 0) return
    const modelIndex = modelIds.findIndex((id) => id === modelValue)
    const selected = modelValue === null || modelIndex < 0 ? 0 : modelIndex + 1
    focusIndex(selected < list.length ? selected : 0)
  }

  const focusNext = () => focusIndex(items().findIndex((el) => el === document.activeElement) + 1)

  const focusPrevious = () =>
    focusIndex(items().findIndex((el) => el === document.activeElement) - 1)

  const resetTypeahead = () => {
    query = ''
    if (timer === null) return
    clearTimeout(timer)
    timer = null
  }

  const onTypeaheadKeydown = (event: KeyboardEvent) => {
    if (event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return
    if (event.key.length !== 1) return
    event.preventDefault()

    const current = items().findIndex((el) => el === document.activeElement)
    const next = nextTypeaheadTarget(options.labels(), query, event.key, current)
    if (next.index < 0) return
    query = next.query
    focusIndex(next.index)
    if (timer !== null) clearTimeout(timer)
    timer = setTimeout(() => {
      query = ''
      timer = null
    }, TYPEAHEAD_RESET_MS)
  }

  onBeforeUnmount(resetTypeahead)

  return { focusSelected, focusNext, focusPrevious, onTypeaheadKeydown, resetTypeahead }
}
