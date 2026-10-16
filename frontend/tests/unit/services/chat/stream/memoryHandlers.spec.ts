import { describe, expect, it, vi } from 'vitest'
import { memoryHandlers } from '@/services/chat/stream/handlers/memory'
import { fakeMemberTurn } from './fakeTurn'

describe('memory handlers', () => {
  it('suggests a new memory and offers to delete one the model wants gone', () => {
    const turn = fakeMemberTurn()

    memoryHandlers.memory_suggested(
      { status: 'memory_suggested', metadata: { id: 1, key: 'city', value: 'Porto' } },
      turn
    )
    memoryHandlers.memory_suggested(
      { status: 'memory_suggested', metadata: { id: 2, action: 'delete' } },
      turn
    )

    expect(turn.memory.suggest).toHaveBeenCalledWith(
      expect.objectContaining({ id: 1, key: 'city', value: 'Porto', source: 'auto_detected' })
    )
    expect(turn.memory.offerDelete).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }))
  })

  it('ignores a suggestion without an id', () => {
    const turn = fakeMemberTurn()
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    memoryHandlers.memory_suggested({ status: 'memory_suggested', metadata: {} }, turn)

    expect(turn.memory.suggest).not.toHaveBeenCalled()
  })

  it('stores the loaded memories once and links them to the answer', () => {
    const turn = fakeMemberTurn()
    turn.memory.memories.memories.push({
      id: 1,
      category: 'travel',
      key: 'city',
      value: 'Porto',
      source: 'user_created',
      messageId: null,
      created: 1,
      updated: 1,
    })

    memoryHandlers.memories_loaded(
      {
        status: 'memories_loaded',
        metadata: { memories: [{ id: 1 }, { id: 2, value: 'spring' }] },
      },
      turn
    )

    expect(turn.memory.memories.memories.map((memory) => memory.id)).toEqual([1, 2])
    expect(turn.memory.memories.memories[1]).toMatchObject({
      category: 'personal',
      value: 'spring',
      source: 'auto_detected',
    })
    expect(turn.message()?.memoryIds).toEqual([1, 2])
  })

  it('stores the loaded feedback and links it to the answer', () => {
    const turn = fakeMemberTurn()

    memoryHandlers.feedback_loaded(
      {
        status: 'feedback_loaded',
        metadata: { feedbacks: [{ id: 5, type: 'positive', value: 'Short' }] },
      },
      turn
    )

    expect(turn.memory.feedbacks.feedbacks).toEqual([
      { id: 5, type: 'positive', value: 'Short', messageId: null, created: 0, updated: 0 },
    ])
    expect(turn.message()?.feedbackIds).toEqual([5])
  })

  it('registers deep-memory references with a message id only', () => {
    const turn = fakeMemberTurn()

    memoryHandlers.digests_loaded(
      {
        status: 'digests_loaded',
        metadata: { digests: [{ message_id: 8, chat_id: 3, title: 'Trip' }, { title: 'no id' }] },
      },
      turn
    )

    expect(turn.memory.digests.addReferences).toHaveBeenCalledWith([
      { messageId: 8, chatId: 3, title: 'Trip', channel: '', sourceDate: 0 },
    ])
  })

  it('drops a deleted memory from the local store', () => {
    const turn = fakeMemberTurn()
    turn.memory.memories.memories.push({
      id: 4,
      category: 'x',
      key: 'k',
      value: 'v',
      source: 'user_created',
      messageId: null,
      created: 1,
      updated: 1,
    })

    memoryHandlers.memory_deleted({ status: 'memory_deleted', metadata: { id: 4 } }, turn)

    expect(turn.memory.memories.memories).toEqual([])
  })
})
