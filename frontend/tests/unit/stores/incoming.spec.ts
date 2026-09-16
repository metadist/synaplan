import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const authed = ref(false)
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAuthenticated() {
      return authed.value
    },
  }),
}))

const sharingEnabled = ref(true)
vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => sharingEnabled.value,
  isIamGroupsEnabled: () => true,
}))

const { listSharedWithMe, countUnseenShared, markSharedSeen } = vi.hoisted(() => ({
  listSharedWithMe: vi.fn(),
  countUnseenShared: vi.fn(),
  markSharedSeen: vi.fn(),
}))
vi.mock('@/services/api/iamApi', () => ({
  iamApi: { listSharedWithMe, countUnseenShared, markSharedSeen },
}))

import { useIncomingStore, INCOMING_KIND } from '@/stores/incoming'

const sharedChat = (id: string, isNew: boolean) => ({
  id,
  name: `Chat ${id}`,
  icon: 'chat',
  permission: 'use',
  ownerId: 1,
  ownerName: 'Alice',
  sharedVia: { type: 'group', name: 'Sales' },
  sharedAt: 1_788_721_849,
  isNew,
})

describe('incoming store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    authed.value = false
    sharingEnabled.value = true
    listSharedWithMe.mockResolvedValue([sharedChat('13', true), sharedChat('14', false)])
    countUnseenShared.mockResolvedValue(1)
    markSharedSeen.mockResolvedValue(undefined)
  })

  // The store watches the shared `authed` ref; stop each instance so a
  // previous test's store cannot react (and consume mocks) in the next one.
  afterEach(() => {
    useIncomingStore().$dispose()
  })

  it('stays empty and never calls the API while signed out', async () => {
    const store = useIncomingStore()
    await flushPromises()

    expect(store.chats).toEqual([])
    expect(store.hasNew).toBe(false)
    expect(listSharedWithMe).not.toHaveBeenCalled()
  })

  it('loads conversations and the unseen counter once signed in', async () => {
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()

    expect(listSharedWithMe).toHaveBeenCalledWith(INCOMING_KIND)
    expect(countUnseenShared).toHaveBeenCalledWith(INCOMING_KIND)
    expect(store.chats.map((c) => c.id)).toEqual(['13', '14'])
    expect(store.unseenCount).toBe(1)
    expect(store.hasNew).toBe(true)
    expect(store.newChats.map((c) => c.id)).toEqual(['13'])
    expect(store.loaded).toBe(true)
  })

  it('does nothing with the sharing flag off, even when signed in', async () => {
    sharingEnabled.value = false
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()

    expect(listSharedWithMe).not.toHaveBeenCalled()
    expect(store.chats).toEqual([])
    expect(store.isOpenable(13)).toBe(false)
  })

  it('fails closed when the API errors', async () => {
    listSharedWithMe.mockRejectedValueOnce(new Error('boom'))
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()

    expect(store.chats).toEqual([])
    expect(store.unseenCount).toBe(0)
    expect(store.loaded).toBe(true)
  })

  it('de-duplicates concurrent loads', async () => {
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()
    listSharedWithMe.mockClear()

    await Promise.all([store.load(), store.load(), store.load()])

    expect(listSharedWithMe).toHaveBeenCalledTimes(1)
  })

  it('markSeen clears the dot immediately but keeps the row markers until the next load', async () => {
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()

    await store.markSeen()

    expect(markSharedSeen).toHaveBeenCalledWith(INCOMING_KIND)
    expect(store.unseenCount).toBe(0)
    expect(store.hasNew).toBe(false)
    expect(store.chats.find((c) => c.id === '13')?.isNew).toBe(true)
  })

  it('isOpenable accepts incoming ids, and any id while the first load is pending', async () => {
    let resolveList: (value: unknown) => void = () => {}
    listSharedWithMe.mockReturnValueOnce(
      new Promise((resolve) => {
        resolveList = resolve
      })
    )
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()

    expect(store.loaded).toBe(false)
    expect(store.isOpenable(999)).toBe(true)

    resolveList([sharedChat('13', false)])
    await flushPromises()

    expect(store.loaded).toBe(true)
    expect(store.isOpenable(13)).toBe(true)
    expect(store.isOpenable(999)).toBe(false)
  })

  it('resets when the user signs out', async () => {
    const store = useIncomingStore()
    authed.value = true
    await flushPromises()
    expect(store.chats).toHaveLength(2)

    authed.value = false
    await flushPromises()

    expect(store.chats).toEqual([])
    expect(store.unseenCount).toBe(0)
    expect(store.loaded).toBe(false)
  })
})
