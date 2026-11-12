import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useChatsStore } from '@/stores/chats'
import { forgetPagedChat, useChatArchiveStore } from '@/stores/chatArchive'
import { useChatHistory } from '@/composables/useChatHistory'
import { useNotification } from '@/composables/useNotification'

const authUser = ref<{ id: number } | null>({ id: 1 })
vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
    getUser: () => authUser,
  },
}))

const httpClientMock = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/httpClient', () => ({
  httpClient: httpClientMock,
  getConfigSync: () => ({ features: {} }),
}))

vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => false,
  isIamGroupsEnabled: () => false,
}))

vi.mock('@/stores/incoming', () => ({
  useIncomingStore: () => ({
    loaded: false,
    chats: [],
    isOpenable: () => false,
    load: vi.fn(),
    drop: vi.fn(),
    markChatOpened: vi.fn(),
  }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ path: '/', query: {} }),
  useRouter: () => ({ push: vi.fn() }),
}))

function listed(id: number, title: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    title,
    createdAt: '2026-01-01T00:00:00.000Z',
    updatedAt: '2026-01-02T00:00:00.000Z',
    messageCount: 2,
    isShared: false,
    ...extra,
  }
}

function page(chats: ReturnType<typeof listed>[], hasMore = false) {
  return {
    success: true,
    chats,
    total: chats.length,
    offset: 0,
    limit: 30,
    hasMore,
    activeRunChatIds: [],
  }
}

describe('chat archive store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
    useNotification().notifications.value = []
  })

  it('pages archived chats without touching the open list or the active chat', async () => {
    const chats = useChatsStore()
    const archive = useChatArchiveStore()
    chats.chats = [listed(1, 'Today')]
    chats.activeChatId = 1
    httpClientMock.mockResolvedValueOnce(page([listed(8, 'Old archived')], true))
    httpClientMock.mockResolvedValueOnce(page([listed(9, 'Older archived')]))

    await archive.toggleViewingArchived()
    await archive.loadArchivedChats(false)

    expect(httpClientMock.mock.calls[0][0]).toBe('/api/v1/chats?archived=1&limit=30&offset=0')
    expect(httpClientMock.mock.calls[1][0]).toBe('/api/v1/chats?archived=1&limit=30&offset=1')
    expect(archive.viewingArchived).toBe(true)
    expect(archive.chats.map((chat) => chat.id)).toEqual([8, 9])
    expect(archive.chats.every((chat) => chat.archived === true)).toBe(true)
    expect(chats.chats.map((chat) => chat.id)).toEqual([1])
    expect(chats.activeChatId).toBe(1)

    await archive.toggleViewingArchived()
    expect(archive.viewingArchived).toBe(false)
    expect(chats.activeChatId).toBe(1)
  })

  it('archives the active chat onto the next open chat and never a widget session', async () => {
    const chats = useChatsStore()
    const archive = useChatArchiveStore()
    chats.chats = [
      listed(1, 'Widget', { widgetSession: { widgetId: 'w' } }),
      listed(2, 'Keep'),
      listed(3, 'Move'),
    ]
    chats.railChats = [listed(3, 'Move')]
    chats.railOffset = 1
    chats.historyChats = [listed(3, 'Move')]
    chats.activeChatId = 3
    httpClientMock.mockResolvedValueOnce({ success: true, archived: true })

    expect(await chats.setChatArchived(3, true)).toBe(true)

    expect(httpClientMock).toHaveBeenCalledWith('/api/v1/chats/3/archive', { method: 'POST' })
    expect(chats.chats.map((chat) => chat.id)).toEqual([1, 2])
    expect(chats.activeChatId).toBe(2)
    expect(chats.railChats).toEqual([])
    expect(chats.historyChats).toEqual([])
    expect(archive.chats.map((chat) => chat.id)).toEqual([3])
    expect(archive.isOpenable(3)).toBe(true)
  })

  it('starts a fresh chat when archiving leaves only a widget session', async () => {
    const chats = useChatsStore()
    chats.chats = [listed(1, 'Widget', { widgetSession: { widgetId: 'w' } }), listed(3, 'Move')]
    chats.activeChatId = 3
    httpClientMock.mockResolvedValueOnce({ success: true, archived: true })
    httpClientMock.mockResolvedValueOnce({
      success: true,
      chat: listed(9, 'New Chat', { messageCount: 0 }),
    })

    expect(await chats.setChatArchived(3, true)).toBe(true)

    expect(chats.activeChatId).toBe(9)
    expect(chats.chats.map((chat) => chat.id)).toEqual([9, 1])
    expect(chats.chats[0]?.widgetSession).toBeFalsy()
  })

  it('restores an archived chat into the open list', async () => {
    const chats = useChatsStore()
    const archive = useChatArchiveStore()
    const restored = listed(4, 'Restored', { archived: true })
    archive.prependArchived(restored)
    chats.activeChatId = 4
    httpClientMock.mockResolvedValueOnce({ success: true, archived: false })
    httpClientMock.mockResolvedValueOnce({ chats: [listed(4, 'Restored')], activeRunChatIds: [] })
    httpClientMock.mockResolvedValueOnce(page([listed(4, 'Restored')]))

    expect(await chats.setChatArchived(4, false)).toBe(true)

    expect(httpClientMock.mock.calls[0][0]).toBe('/api/v1/chats/4/unarchive')
    expect(httpClientMock.mock.calls[1][0]).toBe('/api/v1/chats?archived=0')
    expect(String(httpClientMock.mock.calls[2][0])).toContain('/api/v1/chats?limit=30&offset=0')
    expect(archive.chats).toEqual([])
    expect(chats.chats.map((chat) => chat.id)).toEqual([4])
    expect(chats.activeChatId).toBe(4)
  })

  it('drops an archived response that arrives after logout', async () => {
    const archive = useChatArchiveStore()
    let resolvePage: (value: ReturnType<typeof page>) => void = () => {}
    httpClientMock.mockReturnValueOnce(
      new Promise((resolve) => {
        resolvePage = resolve
      })
    )

    const loading = archive.loadArchivedChats(true)
    archive.$reset()
    resolvePage(page([listed(9, 'Previous person')]))
    await loading

    expect(archive.chats).toEqual([])
    expect(archive.viewingArchived).toBe(false)
    expect(archive.loading).toBe(false)
  })

  it('clears a loaded archive when the open-chat store resets', async () => {
    const chats = useChatsStore()
    const archive = useChatArchiveStore()
    httpClientMock.mockResolvedValueOnce(page([listed(8, 'Old archived')]))
    await archive.loadArchivedChats(true)
    archive.viewingArchived = true

    chats.$reset()

    expect(archive.chats).toEqual([])
    expect(archive.viewingArchived).toBe(false)
  })

  it('moves the next page back when a loaded row is forgotten', () => {
    const list = ref([listed(1, 'One'), listed(2, 'Two')])
    const offset = ref(2)

    forgetPagedChat(list, offset, 1)

    expect(list.value.map((chat) => chat.id)).toEqual([2])
    expect(offset.value).toBe(1)
  })

  it('tells the user when archiving or restoring did not happen', async () => {
    const chats = useChatsStore()
    const archive = useChatArchiveStore()
    const harness = defineComponent({
      setup() {
        return useChatHistory()
      },
      template: '<div />',
    })
    const wrapper = mount(harness)
    const history = wrapper.vm as unknown as ReturnType<typeof useChatHistory>
    chats.chats = [listed(4, 'Open')]
    httpClientMock.mockRejectedValueOnce(new Error('offline'))

    await history.archiveChat(4)

    expect(useNotification().notifications.value.at(-1)).toMatchObject({
      type: 'error',
      message: 'This chat is still open. Nothing was archived. Try again.',
    })
    expect(chats.chats.map((chat) => chat.id)).toEqual([4])

    archive.prependArchived(listed(4, 'Open', { archived: true }))
    chats.chats = []
    httpClientMock.mockRejectedValueOnce(new Error('offline'))

    await history.archiveChat(4)

    expect(useNotification().notifications.value.at(-1)).toMatchObject({
      type: 'error',
      message: 'This chat is still archived. It was not restored. Try again.',
    })
    wrapper.unmount()
  })
})
