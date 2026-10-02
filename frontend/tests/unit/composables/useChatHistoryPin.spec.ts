import { describe, expect, it } from 'vitest'
import { splitPinnedChats } from '@/composables/useChatHistory'

describe('splitPinnedChats', () => {
  it('keeps pinned chats only in the pinned list, newest pin first', () => {
    const chats = [
      { id: 1, pinned: false, pinnedAt: null, title: 'Open' },
      { id: 2, pinned: true, pinnedAt: '2026-10-02T10:00:00.000Z', title: 'Older pin' },
      { id: 3, pinned: true, pinnedAt: '2026-10-02T12:00:00.000Z', title: 'Newer pin' },
      { id: 4, title: 'Unset' },
    ]

    const { pinned, unpinned } = splitPinnedChats(chats)

    expect(pinned.map((chat) => chat.id)).toEqual([3, 2])
    expect(unpinned.map((chat) => chat.id)).toEqual([1, 4])
    expect(unpinned.some((chat) => chat.pinned === true)).toBe(false)
  })
})
