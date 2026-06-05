import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useChatsStore } from '@/stores/chats'

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
  },
}))

describe('Chats Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
  })

  describe('bumpChatActivity', () => {
    it('updates updatedAt to a fresh ISO timestamp so the chat sorts to the top', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Older chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 2,
        },
      ]

      const before = Date.now()
      store.bumpChatActivity(1)
      const after = Date.now()

      const updated = Date.parse(store.chats[0].updatedAt)
      expect(updated).toBeGreaterThanOrEqual(before)
      expect(updated).toBeLessThanOrEqual(after)
    })

    it('increments messageCount by default', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 3,
        },
      ]

      store.bumpChatActivity(1)

      expect(store.chats[0].messageCount).toBe(4)
    })

    it('initialises messageCount to 1 when missing', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
        },
      ]

      store.bumpChatActivity(1)

      expect(store.chats[0].messageCount).toBe(1)
    })

    it('does not increment messageCount when incrementMessageCount is false', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 5,
        },
      ]

      store.bumpChatActivity(1, { incrementMessageCount: false })

      expect(store.chats[0].messageCount).toBe(5)
    })

    it('sets firstMessagePreview only when missing', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          firstMessagePreview: 'Existing preview',
        },
        {
          id: 2,
          title: 'Other chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
        },
      ]

      store.bumpChatActivity(1, { firstMessagePreview: 'New preview' })
      store.bumpChatActivity(2, { firstMessagePreview: 'Hello world' })

      expect(store.chats[0].firstMessagePreview).toBe('Existing preview')
      expect(store.chats[1].firstMessagePreview).toBe('Hello world')
    })

    it('is a no-op for unknown chat ids', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
        },
      ]

      expect(() => store.bumpChatActivity(999)).not.toThrow()
      expect(store.chats[0].updatedAt).toBe('2026-01-01T00:00:00.000Z')
      expect(store.chats[0].messageCount).toBe(1)
    })

    it('moves the bumped chat to the most recent slot when sorting by updatedAt desc', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 1,
          title: 'Newest',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-05-01T12:00:00.000Z',
          messageCount: 1,
        },
        {
          id: 2,
          title: 'Stale WhatsApp chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-04-01T12:00:00.000Z',
          messageCount: 1,
          source: 'whatsapp',
        },
        {
          id: 3,
          title: 'Old chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-03-01T12:00:00.000Z',
          messageCount: 1,
        },
      ]

      store.bumpChatActivity(2)

      const sorted = [...store.chats].sort(
        (a, b) => Date.parse(b.updatedAt) - Date.parse(a.updatedAt)
      )
      expect(sorted[0].id).toBe(2)
    })
  })
})
