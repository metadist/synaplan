import { computed, provide, ref, type ComputedRef, type InjectionKey, type Ref } from 'vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

export interface AnswerMemoryFocus {
  id: number
  token: number
}

/** Expands the used-memory row of the answer that owns the clicked pill. */
export const focusUsedMemoryKey: InjectionKey<(memoryId: number) => void> =
  Symbol('focusUsedMemory')

export const answerMemoryFocusKey: InjectionKey<Ref<AnswerMemoryFocus | null>> =
  Symbol('answerMemoryFocus')

export const memoryEditableKey: InjectionKey<ComputedRef<boolean>> = Symbol('memoryEditable')

export const editUsedMemoryKey: InjectionKey<(memory: UserMemory) => void> =
  Symbol('editUsedMemory')

export const deleteUsedMemoryKey: InjectionKey<(memory: UserMemory) => void> =
  Symbol('deleteUsedMemory')

/** One answer owns the pill click, the row highlight, and whether edit is allowed. */
export function useAnswerMemoryFocus(editable: () => boolean): void {
  const memoryFocus = ref<AnswerMemoryFocus | null>(null)

  function focusUsedMemory(memoryId: number): void {
    memoryFocus.value = { id: memoryId, token: (memoryFocus.value?.token ?? 0) + 1 }
  }

  provide(focusUsedMemoryKey, focusUsedMemory)
  provide(answerMemoryFocusKey, memoryFocus)
  provide(memoryEditableKey, computed(editable))
}

export function openClickedMemory(
  memoryId: number,
  listedOnMessage: boolean,
  memory: UserMemory | undefined,
  focus: ((memoryId: number) => void) | null
): void {
  if (listedOnMessage && focus) {
    focus(memoryId)
    return
  }
  if (memory && !listedOnMessage) {
    window.dispatchEvent(new CustomEvent('open-memory-dialog', { detail: { memory } }))
  }
}
