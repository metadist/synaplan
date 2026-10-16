import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, ref } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { chatApi } from '@/services/api/chatApi'
import type { StreamUpdatePayload } from '@/types/chatStream'

const authUser = ref<{ id: number } | null>(null)
vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
    getUser: () => authUser,
  },
}))

const httpClientMock = vi.hoisted(() => vi.fn())
const isIamSharingEnabledMock = vi.hoisted(() => vi.fn(() => true))
vi.mock('@/services/api/httpClient', () => ({
  httpClient: httpClientMock,
}))
vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => isIamSharingEnabledMock(),
  isIamGroupsEnabled: () => false,
}))

// The incoming (shared-with-me) store only matters for ensureValidActiveChat;
// by default nothing is incoming, so the historical fallback behaviour holds.
const incomingOpenableMock = vi.hoisted(() => vi.fn<(id: number) => boolean>(() => false))
const incomingLoaded = ref(false)
vi.mock('@/stores/incoming', () => ({
  useIncomingStore: () => ({
    get loaded() {
      return incomingLoaded.value
    },
    isOpenable: (id: number) => incomingOpenableMock(id),
  }),
}))

function listedChat(id: number, title: string) {
  return {
    id,
    title,
    createdAt: '2026-01-01T00:00:00.000Z',
    updatedAt: '2026-01-01T00:00:00.000Z',
    messageCount: 1,
    source: 'web' as const,
  }
}

function chatPayload(id: number) {
  return {
    success: true,
    chat: {
      id,
      title: 'New Chat',
      createdAt: '2026-01-01T00:00:00.000Z',
      updatedAt: '2026-01-01T00:00:00.000Z',
      messageCount: 0,
    },
  }
}

describe('Chats Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    authUser.value = null
    vi.clearAllMocks()
    incomingOpenableMock.mockReturnValue(false)
    incomingLoaded.value = false
  })

  describe('createChat', () => {
    it('selects the newly created chat when the selection did not change mid-flight', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce(chatPayload(7))

      const chat = await store.createChat()

      expect(chat?.id).toBe(7)
      expect(store.activeChatId).toBe(7)
      expect(store.chats.map((c) => c.id)).toEqual([7])
    })

    it('does not steal the selection when the active chat changed while the request was in flight', async () => {
      const store = useChatsStore()

      let resolveSlow: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveSlow = resolve
        })
      )

      const pending = store.createChat()

      // While the request is pending the user switches to another chat
      // (e.g. starts streaming there) …
      store.setActiveChat(11)

      // … then the slow response arrives.
      resolveSlow(chatPayload(12))
      const chat = await pending

      expect(chat?.id).toBe(12)
      expect(store.activeChatId).toBe(11)
      // The chat is still added to the list, just not selected.
      expect(store.chats.map((c) => c.id)).toContain(12)
    })

    it('lets the faster of two concurrent creates keep the selection (boot auto-create race)', async () => {
      const store = useChatsStore()

      // Slow request: ChatView boot auto-create for a user without chats.
      let resolveSlow: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveSlow = resolve
        })
      )
      const slowCreate = store.createChat('New Chat')

      // Fast request: the user clicks "New Chat" meanwhile.
      httpClientMock.mockResolvedValueOnce(chatPayload(11))
      await store.createChat()
      expect(store.activeChatId).toBe(11)

      // The slow boot response must not switch the view away from chat 11.
      resolveSlow(chatPayload(12))
      await slowCreate

      expect(store.activeChatId).toBe(11)
      expect(store.chats.map((c) => c.id)).toEqual([12, 11])
    })
  })

  describe('findOrCreateEmptyChat', () => {
    function deferredCreate(): (id: number | null) => void {
      let settle: (id: number | null) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve, reject) => {
          settle = (id) => (id === null ? reject(new Error('offline')) : resolve(chatPayload(id)))
        })
      )
      return settle
    }

    it('reuses the chat a pending boot create is adding instead of creating a second one', async () => {
      const store = useChatsStore()
      // ChatView boots the full list before any create race can start.
      httpClientMock.mockResolvedValueOnce({ chats: [], activeRunChatIds: [] })
      await store.loadChats()
      const settleBoot = deferredCreate()
      const bootCreate = store.createChat('New Chat')

      const clicked = store.findOrCreateEmptyChat()
      settleBoot(12)
      await bootCreate

      expect((await clicked)?.id).toBe(12)
      expect(httpClientMock).toHaveBeenCalledTimes(2)
      expect(store.chats.map((c) => c.id)).toEqual([12])
      expect(store.activeChatId).toBe(12)
    })

    it('shares one create between two New Chat clicks while the first is pending', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [], activeRunChatIds: [] })
      await store.loadChats()
      const settle = deferredCreate()

      const first = store.findOrCreateEmptyChat()
      const second = store.findOrCreateEmptyChat()
      settle(7)

      expect((await first)?.id).toBe(7)
      expect((await second)?.id).toBe(7)
      expect(httpClientMock).toHaveBeenCalledTimes(2)
      expect(store.chats.map((c) => c.id)).toEqual([7])
    })

    it('creates its own chat when the pending create fails', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [], activeRunChatIds: [] })
      await store.loadChats()
      const settleBoot = deferredCreate()
      const bootCreate = store.createChat('New Chat')

      httpClientMock.mockResolvedValueOnce(chatPayload(9))
      const clicked = store.findOrCreateEmptyChat()
      settleBoot(null)
      await bootCreate

      expect((await clicked)?.id).toBe(9)
      expect(httpClientMock).toHaveBeenCalledTimes(3)
      expect(store.activeChatId).toBe(9)
    })

    it('waits for an in-flight chat list and reuses an empty chat instead of creating one', async () => {
      const store = useChatsStore()
      let resolveList: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveList = resolve
        })
      )
      const loading = store.loadChats()

      const clicked = store.findOrCreateEmptyChat()
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(httpClientMock).toHaveBeenCalledTimes(1)

      resolveList({
        chats: [
          {
            id: 3,
            title: 'Regular chat',
            createdAt: '2026-01-01T00:00:00.000Z',
            updatedAt: '2026-01-02T00:00:00.000Z',
            messageCount: 2,
          },
          {
            id: 4,
            title: 'New Chat',
            createdAt: '2026-01-01T00:00:00.000Z',
            updatedAt: '2026-01-01T00:00:00.000Z',
            messageCount: 0,
            firstMessagePreview: null,
          },
        ],
      })
      await loading

      expect((await clicked)?.id).toBe(4)
      expect(store.activeChatId).toBe(4)
      expect(httpClientMock).toHaveBeenCalledTimes(1)
    })

    it('loads the complete list first when the menu only paged, reusing a distant empty chat', async () => {
      // A non-chat landing (e.g. Settings) leaves `chats` with just the
      // first merged rail page. The reusable empty chat may sit beyond it.
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        success: true,
        chats: [
          {
            id: 9,
            title: 'New Chat',
            createdAt: '2026-01-01T00:00:00.000Z',
            updatedAt: '2026-01-01T00:00:00.000Z',
            messageCount: 0,
            firstMessagePreview: null,
          },
        ],
        activeRunChatIds: [],
      })

      const chat = await store.findOrCreateEmptyChat()

      expect(chat?.id).toBe(9)
      expect(store.activeChatId).toBe(9)
      // One full-list GET, no duplicate-creating POST.
      expect(httpClientMock).toHaveBeenCalledTimes(1)
      expect(httpClientMock.mock.calls[0][0]).toBe('/api/v1/chats?archived=0')
    })
  })

  describe('loadChats / ensureValidActiveChat', () => {
    function widgetChat(id: number) {
      return {
        id,
        title: 'Widget session',
        createdAt: '2026-01-01T00:00:00.000Z',
        updatedAt: '2026-01-01T00:00:00.000Z',
        source: 'widget',
        widgetSession: {
          widgetId: 'w1',
          widgetName: 'Demo',
          sessionId: 's1',
          messageCount: 1,
          lastMessage: null,
          created: 0,
          expires: 0,
        },
      }
    }

    function regularChat(id: number) {
      return {
        id,
        title: 'Regular chat',
        createdAt: '2026-01-01T00:00:00.000Z',
        updatedAt: '2026-01-01T00:00:00.000Z',
        messageCount: 1,
      }
    }

    it('does not restore a stored widget session as the active chat', async () => {
      localStorage.setItem('synaplan_active_chat_id', '5')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [widgetChat(5), regularChat(9)] })

      await store.loadChats()

      // Falls back to the first regular chat instead of the widget session.
      expect(store.activeChatId).toBe(9)
    })

    it('selects null when only widget sessions exist', async () => {
      localStorage.setItem('synaplan_active_chat_id', '5')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [widgetChat(5)] })

      await store.loadChats()

      expect(store.activeChatId).toBeNull()
    })

    it('keeps a valid regular chat selection', async () => {
      localStorage.setItem('synaplan_active_chat_id', '9')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9), widgetChat(5)] })

      await store.loadChats()

      expect(store.activeChatId).toBe(9)
    })

    it('keeps an incoming (shared-with-me) chat that is not in my own list', async () => {
      incomingOpenableMock.mockImplementation((id: number) => id === 13)
      localStorage.setItem('synaplan_active_chat_id', '13')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9)] })

      await store.loadChats()

      expect(store.activeChatId).toBe(13)
    })

    it('falls back to my first chat when the stored id is neither mine nor incoming', async () => {
      incomingOpenableMock.mockReturnValue(false)
      localStorage.setItem('synaplan_active_chat_id', '13')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9)] })

      await store.loadChats()

      expect(store.activeChatId).toBe(9)
    })

    it('ignores a stale loadChats response when a newer load has already landed', async () => {
      const store = useChatsStore()
      let resolveFirst: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        })
      )
      const first = store.loadChats()

      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9)] })
      await store.loadChats()
      expect(store.chats.map((c) => c.id)).toEqual([9])

      resolveFirst({ chats: [regularChat(1)] })
      await first

      expect(store.chats.map((c) => c.id)).toEqual([9])
    })

    it('a superseded loadChats resolves only once the newer load has applied its list', async () => {
      const store = useChatsStore()
      let resolveFirst: (value: unknown) => void = () => {}
      let resolveSecond: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        })
      )
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveSecond = resolve
        })
      )
      const first = store.loadChats()
      const second = store.loadChats()
      let firstSettled = false
      void first.then(() => {
        firstSettled = true
      })

      resolveFirst({ chats: [regularChat(1)] })
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(firstSettled).toBe(false)
      expect(store.activeChatId).toBeNull()

      resolveSecond({ chats: [regularChat(9)] })
      await first

      expect(store.chats.map((c) => c.id)).toEqual([9])
      expect(store.activeChatId).toBe(9)
      await second
    })

    it('does not let a late loadChats overwrite a title that was just saved', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1)] })
      await store.loadChats()

      let resolveSlow: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveSlow = resolve
        })
      )
      const pendingLoad = store.loadChats()

      httpClientMock.mockResolvedValueOnce({})
      await store.updateChatTitle(1, 'Renamed while refresh was in flight')
      expect(store.chats[0].title).toBe('Renamed while refresh was in flight')

      resolveSlow({ chats: [regularChat(1)] })
      await pendingLoad

      expect(store.chats[0].title).toBe('Renamed while refresh was in flight')
    })

    it('does not let a load started during PATCH overwrite the saved title', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1)] })
      await store.loadChats()

      let resolvePatch: (value: unknown) => void = () => {}
      let resolveLoad: (value: unknown) => void = () => {}
      httpClientMock
        .mockReturnValueOnce(
          new Promise((resolve) => {
            resolvePatch = resolve
          })
        )
        .mockReturnValueOnce(
          new Promise((resolve) => {
            resolveLoad = resolve
          })
        )

      const pendingRename = store.updateChatTitle(1, 'Renamed during refresh')
      const pendingLoad = store.loadChats()

      resolvePatch({})
      await pendingRename
      expect(store.chats[0].title).toBe('Renamed during refresh')

      resolveLoad({ chats: [regularChat(1)] })
      await pendingLoad

      expect(store.chats[0].title).toBe('Renamed during refresh')
    })

    it('does not resurrect a chat the server no longer returns', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1), regularChat(2)] })
      await store.loadChats()

      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1)] })
      await store.loadChats()

      expect(store.chats.map((c) => c.id)).toEqual([1])
    })

    it('keeps a chat created while a list refresh is in flight', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1)] })
      await store.loadChats()

      let resolveLoad: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveLoad = resolve
        })
      )
      const pendingLoad = store.loadChats()

      httpClientMock.mockResolvedValueOnce(chatPayload(7))
      await store.createChat()

      resolveLoad({ chats: [regularChat(1)] })
      await pendingLoad

      expect(store.chats.map((c) => c.id)).toEqual(expect.arrayContaining([7, 1]))
    })

    it('keeps a local activity bump when a stale list refresh lands', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(1)] })
      await store.loadChats()

      let resolveLoad: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveLoad = resolve
        })
      )
      const pendingLoad = store.loadChats()

      store.bumpChatActivity(1, { firstMessagePreview: 'hello' })
      const bumpedAt = store.chats[0].updatedAt

      resolveLoad({ chats: [regularChat(1)] })
      await pendingLoad

      expect(store.chats[0].updatedAt).toBe(bumpedAt)
      expect(store.chats[0].messageCount).toBe(2)
      expect(store.chats[0].firstMessagePreview).toBe('hello')
    })

    it('does not apply a pre-logout load after $reset', async () => {
      const store = useChatsStore()
      let resolveFirst: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        })
      )
      const first = store.loadChats()

      store.$reset()

      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9)] })
      await store.loadChats()
      expect(store.chats.map((c) => c.id)).toEqual([9])

      resolveFirst({ chats: [regularChat(1)] })
      await first

      expect(store.chats.map((c) => c.id)).toEqual([9])
    })

    it('clears local completion dedupe state on $reset', async () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 2,
          title: 'Chat 2',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'web',
        },
      ]
      store.markLocalTurnFinished(2)

      store.$reset()
      store.chats = [
        {
          id: 2,
          title: 'Chat 2',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'web',
        },
      ]

      await store.noteExternalActivity(2)

      expect(store.chats[0].messageCount).toBe(2)
    })

    it('re-validates a kept foreign id once the incoming list has loaded without it', async () => {
      incomingOpenableMock.mockReturnValue(true)
      localStorage.setItem('synaplan_active_chat_id', '13')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [regularChat(9)] })
      await store.loadChats()
      expect(store.activeChatId).toBe(13)

      incomingOpenableMock.mockReturnValue(false)
      incomingLoaded.value = true
      await nextTick()

      expect(store.activeChatId).toBe(9)
    })

    it('drops a stored id once incoming has loaded and the own list is empty', async () => {
      incomingOpenableMock.mockReturnValue(true)
      localStorage.setItem('synaplan_active_chat_id', '13')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [] })
      await store.loadChats()
      expect(store.activeChatId).toBe(13)

      incomingOpenableMock.mockReturnValue(false)
      incomingLoaded.value = true
      await nextTick()

      expect(store.activeChatId).toBeNull()
      expect(localStorage.getItem('synaplan_active_chat_id')).toBeNull()
    })

    it('opens an owned chat after a stale id is dropped, so a send has somewhere to go', async () => {
      incomingOpenableMock.mockReturnValue(true)
      localStorage.setItem('synaplan_active_chat_id', '13')
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chats: [] })
      await store.loadChats()

      incomingOpenableMock.mockReturnValue(false)
      incomingLoaded.value = true
      await nextTick()
      expect(store.activeChatId).toBeNull()

      httpClientMock.mockResolvedValueOnce(chatPayload(21))
      const chat = await store.findOrCreateEmptyChat()

      expect(chat?.id).toBe(21)
      expect(store.activeChatId).toBe(21)
    })
  })

  describe('applyChatTitle', () => {
    const untitled = () => ({
      id: 1,
      title: 'New Chat',
      createdAt: '2026-01-01T00:00:00.000Z',
      updatedAt: '2026-01-01T00:00:00.000Z',
      messageCount: 2,
    })

    it('shows the title the server generated for the chat', () => {
      const store = useChatsStore()
      store.chats = [untitled()]

      store.applyChatTitle(1, 'Invoice import problem')

      expect(store.chats[0].title).toBe('Invoice import problem')
    })

    it('does not PATCH — the server already persisted the title', () => {
      const store = useChatsStore()
      store.chats = [untitled()]

      store.applyChatTitle(1, 'Invoice import problem')

      expect(httpClientMock).not.toHaveBeenCalled()
    })

    it('ignores a title for a chat that is not in the list', () => {
      const store = useChatsStore()
      store.chats = [untitled()]

      expect(() => store.applyChatTitle(999, 'Somewhere else')).not.toThrow()
      expect(store.chats[0].title).toBe('New Chat')
    })
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

  describe('loadConversationAccess', () => {
    it('skips the request and treats the chat as owned when sharing is off', async () => {
      isIamSharingEnabledMock.mockReturnValueOnce(false)
      const store = useChatsStore()

      await store.loadConversationAccess(3)

      expect(httpClientMock).not.toHaveBeenCalled()
      expect(store.conversationAccess).toBe('owner')
    })

    it('treats a missing access field as owner', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chat: { id: 3 } })

      await store.loadConversationAccess(3)

      expect(store.conversationAccess).toBe('owner')
    })

    it('records a shared read-only chat', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({ chat: { id: 4, access: 'read' } })

      await store.loadConversationAccess(4)

      expect(store.conversationAccess).toBe('read')
    })

    it('records who owns an incoming chat and how it reached the viewer', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chat: {
          id: 13,
          access: 'use',
          owner: { id: 2, name: 'Alice' },
          sharedVia: { type: 'group', name: 'Sales' },
        },
      })

      await store.loadConversationAccess(13)

      expect(store.conversationAccess).toBe('use')
      expect(store.conversationSource).toEqual({
        owner: { id: 2, name: 'Alice' },
        sharedVia: { type: 'group', name: 'Sales' },
      })
    })

    it('clears the source when sharing is off', async () => {
      isIamSharingEnabledMock.mockReturnValueOnce(false)
      const store = useChatsStore()

      await store.loadConversationAccess(3)

      expect(store.conversationSource).toBeNull()
    })

    it('clears access while loading and does not fall back to owner on error', async () => {
      const store = useChatsStore()
      httpClientMock.mockRejectedValueOnce(new Error('network'))

      await store.loadConversationAccess(5)

      expect(store.conversationAccess).toBeNull()
    })

    it('flags a failed probe so the view can explain the missing composer', async () => {
      const store = useChatsStore()
      httpClientMock.mockRejectedValueOnce(new Error('network'))

      await store.loadConversationAccess(5)

      expect(store.conversationAccessFailed).toBe(true)
    })

    it('clears the failure once a retry for the open chat succeeds', async () => {
      const store = useChatsStore()
      store.activeChatId = 5
      httpClientMock.mockRejectedValueOnce(new Error('network'))
      await store.loadConversationAccess(5)

      httpClientMock.mockResolvedValueOnce({ chat: { id: 5, access: 'use' } })
      await store.retryConversationAccess()

      expect(httpClientMock).toHaveBeenLastCalledWith('/api/v1/chats/5')
      expect(store.conversationAccessFailed).toBe(false)
      expect(store.conversationAccess).toBe('use')
    })

    it('settles a retry as owned when no chat is open', async () => {
      const store = useChatsStore()
      httpClientMock.mockRejectedValueOnce(new Error('network'))
      await store.loadConversationAccess(5)
      store.activeChatId = null

      await store.retryConversationAccess()

      expect(store.conversationAccessFailed).toBe(false)
      expect(store.conversationAccess).toBe('owner')
    })

    it("treats a chat from the viewer's own list as owned without asking", async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce(chatPayload(9))
      await store.createChat()
      httpClientMock.mockClear()

      await store.loadConversationAccess(9)

      expect(httpClientMock).not.toHaveBeenCalled()
      expect(store.conversationAccess).toBe('owner')
    })

    it('settles as owned when nothing is open, so the composer is not withheld', () => {
      const store = useChatsStore()

      store.resolveConversationAccessAsOwn()

      expect(store.conversationAccess).toBe('owner')
      expect(store.conversationSource).toBeNull()
    })

    it('settles a blank chat as owned after $reset so the composer is not withheld', () => {
      const store = useChatsStore()
      store.resolveConversationAccessAsOwn()

      store.$reset()

      expect(store.activeChatId).toBeNull()
      expect(store.conversationAccess).toBe('owner')
    })

    it('drops an in-flight probe once the answer is known to be owned', async () => {
      const store = useChatsStore()
      let resolveProbe: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveProbe = resolve
        })
      )
      const probe = store.loadConversationAccess(4)
      store.resolveConversationAccessAsOwn()

      resolveProbe({ chat: { id: 4, access: 'read' } })
      await probe

      expect(store.conversationAccess).toBe('owner')
    })

    it('ignores a stale response after a newer load started', async () => {
      const store = useChatsStore()
      let resolveFirst: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        })
      )
      const first = store.loadConversationAccess(1)
      httpClientMock.mockResolvedValueOnce({ chat: { id: 2, access: 'use' } })
      await store.loadConversationAccess(2)

      resolveFirst({ chat: { id: 1, access: 'owner' } })
      await first

      expect(store.conversationAccess).toBe('use')
    })
  })

  describe('noteExternalActivity', () => {
    it('bumps an already-loaded chat instead of reloading', async () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 2,
          title: 'WhatsApp chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'whatsapp',
        },
      ]

      const before = Date.now()
      await store.noteExternalActivity(2, { firstMessagePreview: 'Hi from WhatsApp' })

      expect(httpClientMock).not.toHaveBeenCalled()
      expect(Date.parse(store.chats[0].updatedAt)).toBeGreaterThanOrEqual(before)
      expect(store.chats[0].messageCount).toBe(2)
      expect(store.chats[0].firstMessagePreview).toBe('Hi from WhatsApp')
    })

    it('reloads the chat list when the chat is not loaded yet', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chats: [{ ...chatPayload(42).chat, messageCount: 1 }],
      })

      await store.noteExternalActivity(42)

      expect(httpClientMock).toHaveBeenCalledWith('/api/v1/chats?archived=0')
      expect(store.chats.map((c) => c.id)).toContain(42)
    })

    it('consumes all queued local completions for the same chat', async () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 2,
          title: 'Web chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 4,
          source: 'web',
        },
      ]

      store.markLocalTurnFinished(2)
      store.markLocalTurnFinished(2)

      await store.noteExternalActivity(2)
      await store.noteExternalActivity(2)

      expect(store.chats[0].messageCount).toBe(4)
      expect(httpClientMock).not.toHaveBeenCalled()
    })

    it('keeps local completion dedupe scoped per chat', async () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 2,
          title: 'Chat 2',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'web',
        },
        {
          id: 3,
          title: 'Chat 3',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 2,
          source: 'web',
        },
      ]

      store.markLocalTurnFinished(2)
      store.markLocalTurnFinished(3)

      await store.noteExternalActivity(2)
      await store.noteExternalActivity(3)

      expect(store.chats.find((chat) => chat.id === 2)?.messageCount).toBe(1)
      expect(store.chats.find((chat) => chat.id === 3)?.messageCount).toBe(2)
      expect(httpClientMock).not.toHaveBeenCalled()
    })

    it('clears queued local completions for a chat', async () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 2,
          title: 'Chat 2',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'web',
        },
      ]

      store.markLocalTurnFinished(2)
      store.clearLocalTurnFinished(2)
      await store.noteExternalActivity(2)

      expect(store.chats[0].messageCount).toBe(2)
    })
  })

  /**
   * A turn survives the client that started it, so the list marks the chats
   * where an answer is still being written after the user moved on.
   */
  describe('loadChats — chats with a generating turn', () => {
    it('tracks the chats the server reports as still generating', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chats: [chatPayload(1).chat, chatPayload(2).chat],
        activeRunChatIds: [2],
      })

      await store.loadChats()

      expect(store.activeRunChatIds.has(2)).toBe(true)
      expect(store.activeRunChatIds.has(1)).toBe(false)
    })

    it('keeps a live generating mark when a stale list snapshot has none', async () => {
      const store = useChatsStore()
      let resolveLoad: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveLoad = resolve
        })
      )
      const pending = store.loadChats()

      store.markChatGenerating(1, true)
      resolveLoad({ chats: [chatPayload(1).chat], activeRunChatIds: [] })
      await pending

      expect(store.activeRunChatIds.has(1)).toBe(true)
    })

    it('lets a later list load clear a walked-away generating mark', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chats: [chatPayload(1).chat],
        activeRunChatIds: [],
      })
      await store.loadChats()

      store.markChatGenerating(1, true)
      expect(store.activeRunChatIds.has(1)).toBe(true)

      httpClientMock.mockResolvedValueOnce({
        chats: [chatPayload(1).chat],
        activeRunChatIds: [],
      })
      await store.loadChats()

      expect(store.activeRunChatIds.has(1)).toBe(false)
    })

    it('keeps a live clear when a stale in-flight snapshot still lists the run', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chats: [chatPayload(1).chat],
        activeRunChatIds: [1],
      })
      await store.loadChats()

      let resolveLoad: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveLoad = resolve
        })
      )
      const pending = store.loadChats()

      store.markChatGenerating(1, false)
      resolveLoad({ chats: [chatPayload(1).chat], activeRunChatIds: [1] })
      await pending

      expect(store.activeRunChatIds.has(1)).toBe(false)
    })

    it('clears the marker once the turn finished', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce({
        chats: [chatPayload(1).chat],
        activeRunChatIds: [1],
      })
      await store.loadChats()

      httpClientMock.mockResolvedValueOnce({ chats: [chatPayload(1).chat] })
      await store.loadChats()

      expect(store.activeRunChatIds.size).toBe(0)
    })

    /**
     * The server's answer only arrives with a chat-list fetch, which happens on
     * entry. Without the live updates below the marker would be a snapshot from
     * app start: never lighting up when the user walks away from a running turn,
     * never going out when it finishes.
     */
    it('marks and unmarks a chat live, replacing the Set so templates re-render', async () => {
      const store = useChatsStore()
      const initial = store.activeRunChatIds

      store.markChatGenerating(7, true)

      expect(store.activeRunChatIds.has(7)).toBe(true)
      expect(store.activeRunChatIds).not.toBe(initial)

      const marked = store.activeRunChatIds
      store.markChatGenerating(7, false)

      expect(store.activeRunChatIds.has(7)).toBe(false)
      expect(store.activeRunChatIds).not.toBe(marked)
    })

    it('updates the sidebar when a detached run finishes', () => {
      const store = useChatsStore()
      store.chats = [
        {
          id: 4,
          title: 'New Chat',
          createdAt: '2026-01-01T00:00:00.000Z',
          updatedAt: '2026-01-01T00:00:00.000Z',
          messageCount: 1,
          source: 'web',
        },
      ]
      store.markChatGenerating(4, true)
      const updates: Array<(data: StreamUpdatePayload) => void> = []
      vi.spyOn(chatApi, 'attachStream').mockImplementation((opts) => {
        updates.push(opts.onUpdate)
        return () => {}
      })

      store.watchDetachedRun(4, 'run-4')
      updates[0]?.({ status: 'complete', chatTitle: 'Weather in Düsseldorf' })

      expect(store.activeRunChatIds.has(4)).toBe(false)
      expect(store.readyChatIds.has(4)).toBe(true)
      expect(store.chats[0]?.title).toBe('Weather in Düsseldorf')
      expect(store.chats[0]?.messageCount).toBe(2)

      store.setActiveChat(4)
      expect(store.readyChatIds.has(4)).toBe(false)
    })

    it('keeps the finished dot across a reload until that chat is opened', async () => {
      authUser.value = { id: 9 }
      const store = useChatsStore()
      store.setActiveChat(1)
      store.markChatGenerating(4, true)
      const updates: Array<(data: StreamUpdatePayload) => void> = []
      vi.spyOn(chatApi, 'attachStream').mockImplementation((opts) => {
        updates.push(opts.onUpdate)
        return () => {}
      })
      store.watchDetachedRun(4, 'run-4')
      updates[0]?.({ status: 'complete', chatTitle: 'Weather in Düsseldorf' })

      expect(JSON.parse(localStorage.getItem('synaplan_ready_chat_ids_9') ?? '[]')).toEqual([4])

      setActivePinia(createPinia())
      const reloaded = useChatsStore()
      httpClientMock.mockResolvedValue({
        chats: [listedChat(1, 'Open'), listedChat(4, 'Weather in Düsseldorf')],
        activeRunChatIds: [],
      })
      await reloaded.loadChats()

      expect(reloaded.activeChatId).toBe(1)
      expect(reloaded.readyChatIds.has(4)).toBe(true)

      reloaded.setActiveChat(4)
      expect(reloaded.readyChatIds.has(4)).toBe(false)
      expect(localStorage.getItem('synaplan_ready_chat_ids_9')).toBeNull()
    })

    it('marks a chat the user left when the answer finished while the app was closed', async () => {
      authUser.value = { id: 9 }
      const store = useChatsStore()
      store.setActiveChat(1)
      store.markChatGenerating(4, true)
      vi.spyOn(chatApi, 'attachStream').mockImplementation(() => () => {})
      store.watchDetachedRun(4, 'run-4')

      expect(JSON.parse(localStorage.getItem('synaplan_departed_run_chat_ids_9') ?? '[]')).toEqual([
        4,
      ])

      setActivePinia(createPinia())
      const reloaded = useChatsStore()
      httpClientMock.mockResolvedValue({
        chats: [listedChat(1, 'Open'), listedChat(4, 'Weather in Düsseldorf')],
        activeRunChatIds: [],
      })
      await reloaded.loadChats()

      expect(reloaded.readyChatIds.has(4)).toBe(true)
      expect(reloaded.activeRunChatIds.has(4)).toBe(false)
      expect(localStorage.getItem('synaplan_departed_run_chat_ids_9')).toBeNull()
    })

    it('does not paint the finished dot on the chat the user is already reading', () => {
      const store = useChatsStore()
      store.setActiveChat(4)
      store.markChatGenerating(4, true)
      const updates: Array<(data: StreamUpdatePayload) => void> = []
      vi.spyOn(chatApi, 'attachStream').mockImplementation((opts) => {
        updates.push(opts.onUpdate)
        return () => {}
      })

      store.watchDetachedRun(4, 'run-4')
      updates[0]?.({ status: 'complete', chatTitle: 'Weather in Düsseldorf' })

      expect(store.readyChatIds.has(4)).toBe(false)
      expect(store.activeRunChatIds.has(4)).toBe(false)
    })

    it('keeps following a dropped background run until the server says it finished', async () => {
      vi.useFakeTimers()
      const store = useChatsStore()
      try {
        store.setActiveChat(1)
        store.markChatGenerating(4, true)
        httpClientMock.mockResolvedValue({
          chats: [listedChat(1, 'Open'), listedChat(4, 'New Chat')],
          activeRunChatIds: [4],
        })
        vi.spyOn(chatApi, 'attachStream').mockImplementation((opts) => {
          opts.onUpdate({ status: 'error', error: 'Connection interrupted' })
          return () => {}
        })

        store.watchDetachedRun(4, 'run-4')
        for (let i = 0; i < 15; i++) {
          await vi.advanceTimersByTimeAsync(2000)
        }

        expect(store.readyChatIds.has(4)).toBe(false)
        expect(store.activeRunChatIds.has(4)).toBe(true)
        expect(httpClientMock).toHaveBeenCalled()
        const callsWhileRunning = httpClientMock.mock.calls.length
        expect(callsWhileRunning).toBeGreaterThanOrEqual(15)

        httpClientMock.mockResolvedValue({
          chats: [listedChat(1, 'Open'), listedChat(4, 'Weather in Düsseldorf')],
          activeRunChatIds: [],
        })
        await vi.advanceTimersByTimeAsync(8000)

        expect(store.readyChatIds.has(4)).toBe(true)
        expect(store.activeRunChatIds.has(4)).toBe(false)
      } finally {
        store.$reset()
        vi.clearAllTimers()
        vi.useRealTimers()
      }
    })

    it('keeps the other chats when one turn ends', async () => {
      const store = useChatsStore()
      store.markChatGenerating(1, true)
      store.markChatGenerating(2, true)

      store.markChatGenerating(1, false)

      expect([...store.activeRunChatIds]).toEqual([2])
    })
  })

  describe('toggleChatPin', () => {
    const pinnable = () => ({
      id: 3,
      title: 'Chat 3',
      createdAt: '2026-01-01T00:00:00.000Z',
      updatedAt: '2026-01-01T00:00:00.000Z',
      messageCount: 1,
      source: 'web' as const,
      pinned: false,
    })

    it('ignores a second toggle while the first request is in flight', async () => {
      const store = useChatsStore()
      store.chats = [pinnable()]
      let settle: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          settle = resolve
        })
      )

      const first = store.toggleChatPin(3)
      expect(store.pinPendingChatIds.has(3)).toBe(true)
      await store.toggleChatPin(3)

      expect(httpClientMock).toHaveBeenCalledTimes(1)
      expect(store.chats[0].pinned).toBe(true)

      settle({ success: true })
      await first

      expect(store.pinPendingChatIds.has(3)).toBe(false)
      expect(store.chats[0].pinned).toBe(true)
    })

    it('restores the previous state and frees the button when the request fails', async () => {
      const store = useChatsStore()
      store.chats = [pinnable()]
      httpClientMock.mockRejectedValueOnce(new Error('offline'))

      await store.toggleChatPin(3)

      expect(store.chats[0].pinned).toBe(false)
      expect(store.pinPendingChatIds.has(3)).toBe(false)
    })
  })

  describe('loadRailChats', () => {
    const menuChat = (id: number, title: string) => ({
      id,
      title,
      createdAt: '2026-01-01T00:00:00.000Z',
      updatedAt: '2026-01-02T00:00:00.000Z',
      messageCount: 2,
      isShared: false,
    })

    const menuPage = (chats: ReturnType<typeof menuChat>[], hasMore = false) => ({
      success: true,
      chats,
      total: chats.length,
      offset: 0,
      limit: 30,
      hasMore,
      activeRunChatIds: [],
    })

    it('asks the server for one page and appends the next one', async () => {
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce(menuPage([menuChat(1, 'One')], true))
      httpClientMock.mockResolvedValueOnce(menuPage([menuChat(2, 'Two')]))

      await store.loadRailChats(true)
      await store.loadRailChats(false)

      expect(httpClientMock.mock.calls.map(([url]) => url)).toEqual([
        '/api/v1/chats?limit=30&offset=0',
        '/api/v1/chats?limit=30&offset=1',
      ])
      expect(store.railChats.map((c) => c.id)).toEqual([1, 2])
      expect(store.railHasMore).toBe(false)
    })

    it('shows a title that changed elsewhere instead of the stale one', async () => {
      const store = useChatsStore()
      store.chats = [{ ...menuChat(5, 'New Chat') }]
      httpClientMock.mockResolvedValueOnce(menuPage([menuChat(5, 'Renamed on the server')]))

      await store.loadRailChats(true)

      expect(store.chats.find((c) => c.id === 5)?.title).toBe('Renamed on the server')
    })

    it('keeps a title the user saved while the page was loading', async () => {
      const store = useChatsStore()
      store.chats = [{ ...menuChat(5, 'Old') }]
      let resolvePage: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolvePage = resolve
        })
      )
      const loading = store.loadRailChats(true)

      httpClientMock.mockResolvedValueOnce({ success: true })
      await store.updateChatTitle(5, 'Saved locally')
      resolvePage(menuPage([menuChat(5, 'Old')]))
      await loading

      expect(store.chats.find((c) => c.id === 5)?.title).toBe('Saved locally')
    })

    it('does not bring back a chat deleted while the page was loading', async () => {
      const store = useChatsStore()
      store.chats = [{ ...menuChat(5, 'Doomed') }, { ...menuChat(6, 'Kept') }]
      let resolvePage: (value: unknown) => void = () => {}
      httpClientMock.mockReturnValueOnce(
        new Promise((resolve) => {
          resolvePage = resolve
        })
      )
      const loading = store.loadRailChats(true)

      httpClientMock.mockResolvedValueOnce({ success: true })
      await store.deleteChat(5)
      resolvePage(menuPage([menuChat(5, 'Doomed'), menuChat(6, 'Kept')]))
      await loading

      expect(store.railChats.map((c) => c.id)).toEqual([6])
      expect(store.chats.map((c) => c.id)).toEqual([6])
    })

    it('starts the next page one row earlier after a loaded chat is deleted', async () => {
      // Offset pagination counts server rows: deleting a loaded row moves
      // every later row forward, so the next request must shift with it or
      // it permanently skips the first chat of the next page.
      const store = useChatsStore()
      httpClientMock.mockResolvedValueOnce(
        menuPage([menuChat(5, 'Five'), menuChat(6, 'Six')], true)
      )
      await store.loadRailChats(true)

      httpClientMock.mockResolvedValueOnce({ success: true })
      await store.deleteChat(5)

      httpClientMock.mockResolvedValueOnce(menuPage([menuChat(7, 'Seven')]))
      await store.loadRailChats(false)

      expect(httpClientMock.mock.calls.map(([url]) => url)).toEqual([
        '/api/v1/chats?limit=30&offset=0',
        '/api/v1/chats/5',
        '/api/v1/chats?limit=30&offset=1',
      ])
      expect(store.railChats.map((c) => c.id)).toEqual([6, 7])
    })
  })
})
