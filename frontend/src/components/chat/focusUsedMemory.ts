import type { InjectionKey } from 'vue'

/** Expands the used-memory row of the answer that owns the clicked pill. */
export const focusUsedMemoryKey: InjectionKey<(memoryId: number) => void> =
  Symbol('focusUsedMemory')
