export interface ParsedMemoryAction {
  action: 'create' | 'update' | 'delete'
  memory?: { category: string; key: string; value: string }
  existingId?: number
}

interface MemoryActionStore {
  memories: ReadonlyArray<{ id: number }>
  addMemory: (
    request: { category: string; key: string; value: string },
    options?: { silent?: boolean }
  ) => Promise<unknown>
  editMemory: (
    id: number,
    request: { value: string; category?: string; key?: string },
    options?: { silent?: boolean }
  ) => Promise<unknown>
  removeMemory: (id: number, options?: { silent?: boolean }) => Promise<unknown>
}

/** Applies the Easy-mode preview. One failed action does not stop the rest. */
export async function applyParsedMemoryActions(
  actions: ParsedMemoryAction[],
  store: MemoryActionStore
): Promise<{ saved: number; failed: number }> {
  let saved = 0
  let failed = 0

  for (const actionItem of actions) {
    try {
      if (actionItem.action === 'create' && actionItem.memory) {
        await store.addMemory(actionItem.memory, { silent: true })
        saved++
        continue
      }

      if (actionItem.action === 'update' && actionItem.existingId && actionItem.memory) {
        const exists = store.memories.some((memory) => memory.id === actionItem.existingId)
        if (exists) {
          await store.editMemory(
            actionItem.existingId,
            {
              value: actionItem.memory.value,
              category: actionItem.memory.category,
              key: actionItem.memory.key,
            },
            { silent: true }
          )
        } else {
          await store.addMemory(actionItem.memory, { silent: true })
        }
        saved++
        continue
      }

      if (
        actionItem.action === 'delete' &&
        actionItem.existingId &&
        store.memories.some((memory) => memory.id === actionItem.existingId)
      ) {
        await store.removeMemory(actionItem.existingId, { silent: true })
        saved++
      }
    } catch {
      failed++
    }
  }

  return { saved, failed }
}
