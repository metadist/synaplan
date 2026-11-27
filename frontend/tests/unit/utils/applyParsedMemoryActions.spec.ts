import { describe, expect, it, vi } from 'vitest'
import { applyParsedMemoryActions } from '@/utils/applyParsedMemoryActions'

describe('applyParsedMemoryActions', () => {
  it('keeps applying actions after one of them fails', async () => {
    const store = {
      memories: [{ id: 42 }],
      addMemory: vi.fn(),
      editMemory: vi.fn().mockRejectedValueOnce(new Error('nope')).mockResolvedValue(undefined),
      removeMemory: vi.fn().mockResolvedValue(undefined),
    }

    const result = await applyParsedMemoryActions(
      [
        {
          action: 'update',
          existingId: 42,
          memory: { category: 'preferences', key: 'color', value: 'sea glass' },
        },
        { action: 'delete', existingId: 42 },
      ],
      store
    )

    expect(store.editMemory).toHaveBeenCalledWith(
      42,
      { value: 'sea glass', category: 'preferences', key: 'color' },
      { silent: true }
    )
    expect(result).toEqual({ saved: 1, failed: 1 })
    expect(store.removeMemory).toHaveBeenCalledWith(42, { silent: true })
  })
})
