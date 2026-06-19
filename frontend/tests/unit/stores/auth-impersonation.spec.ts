import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'

const ACTIVE_CHAT_STORAGE_KEY = 'synaplan_active_chat_id'

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost:8000',
  refreshAccessToken: vi.fn().mockResolvedValue(true),
  getConfigSync: () => ({ realtime: { enabled: false, wsUrl: '' } }),
}))

// auth.logout() dynamically imports the realtime store so it can disconnect
// the Centrifuge client. Mock it here so we can assert it is invoked
// without pulling in the full realtime stack (centrifuge-js, tokenApi, …).
const realtimeDisconnectMock = vi.fn().mockResolvedValue(undefined)
vi.mock('@/stores/realtime', () => ({
  useRealtimeStore: () => ({
    disconnect: realtimeDisconnectMock,
  }),
}))

const refreshUserMock = vi.fn()
vi.mock('@/services/authService', async () => {
  const { ref } = await import('vue')
  const userRef = ref<{
    id: number
    email: string
    level: string
    isAdmin?: boolean
  } | null>(null)
  const impersonatorRef = ref<{ id: number; email: string; level: string } | null>(null)

  return {
    authService: {
      getUser: () => userRef,
      getImpersonator: () => impersonatorRef,
      // The store re-fetches /me through this hook on impersonation start/stop.
      // We script its return value per-test via __setNextUser / __setNextImpersonator.
      getCurrentUser: vi.fn().mockImplementation(async () => {
        refreshUserMock()
        return userRef.value
      }),
      login: vi.fn(),
      logout: vi.fn(),
      revokeAllSessions: vi.fn(),
      handleOAuthCallback: vi.fn(),
      // chatsStore / historyStore guard their network methods behind this
      // helper; even though our tests only touch synchronous setters, leave
      // the door open so a future test doesn't trip a redirect-to-login.
      isAuthenticated: vi.fn().mockReturnValue(true),
    },
    /** Test-only handles to drive the mocked refs from the spec. */
    __setUser: (next: { id: number; email: string; level: string; isAdmin?: boolean } | null) => {
      userRef.value = next
    },
    __setImpersonator: (next: { id: number; email: string; level: string } | null) => {
      impersonatorRef.value = next
    },
  }
})

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    reload: vi.fn().mockResolvedValue(undefined),
    billing: { enabled: false },
  }),
}))

const startApiMock = vi.fn()
const stopApiMock = vi.fn()
vi.mock('@/services/api/impersonationApi', () => ({
  impersonationApi: {
    start: (...args: unknown[]) => startApiMock(...args),
    stop: (...args: unknown[]) => stopApiMock(...args),
  },
}))

vi.mock('@/services/api/chatApi', () => ({
  clearSseToken: vi.fn(),
}))

vi.mock('@/stores/userMemories', () => ({
  useMemoriesStore: () => ({ $reset: vi.fn() }),
}))

vi.mock('@/stores/userFeedback', () => ({
  useFeedbackStore: () => ({ $reset: vi.fn() }),
}))

describe('useAuthStore — impersonation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    refreshUserMock.mockClear()
    startApiMock.mockReset()
    stopApiMock.mockReset()
    localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('starts in non-impersonating state', () => {
    const store = useAuthStore()

    expect(store.impersonator).toBeNull()
    expect(store.isImpersonating).toBe(false)
  })

  it('startImpersonation calls the API, refreshes auth, and reflects the new state', async () => {
    const authServiceModule = (await import('@/services/authService')) as unknown as {
      __setUser: (u: unknown) => void
      __setImpersonator: (i: unknown) => void
    }

    startApiMock.mockResolvedValueOnce({ success: true })

    // After /me re-fetch the principal flips to the target user and the
    // impersonator is now populated.
    authServiceModule.__setUser({ id: 99, email: 'target@example.com', level: 'PRO' })
    authServiceModule.__setImpersonator({ id: 1, email: 'admin@example.com', level: 'ADMIN' })

    const store = useAuthStore()
    const result = await store.startImpersonation(99)

    expect(startApiMock).toHaveBeenCalledWith(99)
    expect(result.success).toBe(true)
    expect(store.user?.email).toBe('target@example.com')
    expect(store.impersonator?.email).toBe('admin@example.com')
    expect(store.isImpersonating).toBe(true)
    // /auth/me must have been called as part of the post-swap refresh.
    expect(refreshUserMock).toHaveBeenCalled()
  })

  it('startImpersonation surfaces a server error verbatim and leaves state untouched', async () => {
    // Use a refusal that is still actually thrown by the backend — self
    // impersonation is the one inline guard the UI also enforces. The point
    // here is that the server message is propagated verbatim and the store
    // is not mutated on failure, regardless of which rule fired.
    startApiMock.mockResolvedValueOnce({
      success: false,
      error: 'You cannot impersonate yourself.',
    })

    const store = useAuthStore()
    const result = await store.startImpersonation(2)

    expect(result.success).toBe(false)
    expect(result.error).toBe('You cannot impersonate yourself.')
    expect(store.isImpersonating).toBe(false)
    expect(refreshUserMock).not.toHaveBeenCalled()
  })

  it('stopImpersonation calls the API, refreshes auth, and clears the impersonator', async () => {
    const authServiceModule = (await import('@/services/authService')) as unknown as {
      __setUser: (u: unknown) => void
      __setImpersonator: (i: unknown) => void
    }

    // Pre-condition: we're already impersonating.
    authServiceModule.__setUser({ id: 99, email: 'target@example.com', level: 'PRO' })
    authServiceModule.__setImpersonator({ id: 1, email: 'admin@example.com', level: 'ADMIN' })

    const store = useAuthStore()
    // Hydrate the store directly from the mocked refs (mirrors what login did).
    await store.refreshUser()
    expect(store.isImpersonating).toBe(true)

    stopApiMock.mockResolvedValueOnce({ success: true })

    // After /me re-fetch the admin is back as the principal, no impersonator.
    authServiceModule.__setUser({
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
      isAdmin: true,
    })
    authServiceModule.__setImpersonator(null)

    const result = await store.stopImpersonation()

    expect(stopApiMock).toHaveBeenCalled()
    expect(result.success).toBe(true)
    expect(store.user?.email).toBe('admin@example.com')
    expect(store.user?.isAdmin).toBe(true)
    expect(store.impersonator).toBeNull()
    expect(store.isImpersonating).toBe(false)
  })

  // Issue #999: an admin's `activeChatId` (persisted in localStorage) must
  // not leak into the impersonated user's session. The store has to wipe
  // chats + history state on every principal swap so the ChatView mounts
  // with a clean slate instead of 404-ing on a foreign chat id.
  it('startImpersonation clears the persisted activeChatId and in-memory chat/history state', async () => {
    const authServiceModule = (await import('@/services/authService')) as unknown as {
      __setUser: (u: unknown) => void
      __setImpersonator: (i: unknown) => void
    }

    // Seed the admin's chat state exactly the way ChatView would have:
    // active chat selected, persisted in localStorage, messages loaded.
    const chatsStore = useChatsStore()
    chatsStore.chats = [
      {
        id: 42,
        title: 'Admin chat',
        createdAt: '2026-01-01T00:00:00.000Z',
        updatedAt: '2026-01-01T00:00:00.000Z',
      },
    ]
    chatsStore.setActiveChat(42)
    expect(localStorage.getItem(ACTIVE_CHAT_STORAGE_KEY)).toBe('42')

    const historyStore = useHistoryStore()
    historyStore.addMessage('user', [{ partId: 'p1', type: 'text', content: 'admin secret' }])
    expect(historyStore.messages.length).toBe(1)

    startApiMock.mockResolvedValueOnce({ success: true })
    authServiceModule.__setUser({ id: 99, email: 'target@example.com', level: 'PRO' })
    authServiceModule.__setImpersonator({ id: 1, email: 'admin@example.com', level: 'ADMIN' })

    const store = useAuthStore()
    const result = await store.startImpersonation(99)

    expect(result.success).toBe(true)
    // The persisted handle is what triggered the original 404 — verify it's
    // gone end-to-end, not just the in-memory copy.
    expect(localStorage.getItem(ACTIVE_CHAT_STORAGE_KEY)).toBeNull()
    expect(chatsStore.activeChatId).toBeNull()
    expect(chatsStore.chats).toEqual([])
    expect(historyStore.messages).toEqual([])
  })

  it('stopImpersonation clears the persisted activeChatId and in-memory chat/history state', async () => {
    const authServiceModule = (await import('@/services/authService')) as unknown as {
      __setUser: (u: unknown) => void
      __setImpersonator: (i: unknown) => void
    }

    // Pre-condition: we're already impersonating and the impersonated user
    // has their own chat open.
    authServiceModule.__setUser({ id: 99, email: 'target@example.com', level: 'PRO' })
    authServiceModule.__setImpersonator({ id: 1, email: 'admin@example.com', level: 'ADMIN' })
    const store = useAuthStore()
    await store.refreshUser()

    const chatsStore = useChatsStore()
    chatsStore.chats = [
      {
        id: 7,
        title: 'Impersonated chat',
        createdAt: '2026-01-01T00:00:00.000Z',
        updatedAt: '2026-01-01T00:00:00.000Z',
      },
    ]
    chatsStore.setActiveChat(7)
    expect(localStorage.getItem(ACTIVE_CHAT_STORAGE_KEY)).toBe('7')

    const historyStore = useHistoryStore()
    historyStore.addMessage('user', [{ partId: 'p1', type: 'text', content: 'target message' }])
    expect(historyStore.messages.length).toBe(1)

    stopApiMock.mockResolvedValueOnce({ success: true })
    authServiceModule.__setUser({
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
      isAdmin: true,
    })
    authServiceModule.__setImpersonator(null)

    const result = await store.stopImpersonation()

    expect(result.success).toBe(true)
    expect(localStorage.getItem(ACTIVE_CHAT_STORAGE_KEY)).toBeNull()
    expect(chatsStore.activeChatId).toBeNull()
    expect(chatsStore.chats).toEqual([])
    expect(historyStore.messages).toEqual([])
  })

  it('logout wipes both user and impersonator state', async () => {
    const authServiceModule = (await import('@/services/authService')) as unknown as {
      __setUser: (u: unknown) => void
      __setImpersonator: (i: unknown) => void
    }

    authServiceModule.__setUser({ id: 99, email: 'target@example.com', level: 'PRO' })
    authServiceModule.__setImpersonator({ id: 1, email: 'admin@example.com', level: 'ADMIN' })

    const store = useAuthStore()
    await store.refreshUser()
    expect(store.isImpersonating).toBe(true)

    await store.logout()

    expect(store.user).toBeNull()
    expect(store.impersonator).toBeNull()
    expect(store.isImpersonating).toBe(false)
    // The realtime client must be torn down before the auth cookie is
    // cleared so it cannot keep retrying with stale credentials.
    expect(realtimeDisconnectMock).toHaveBeenCalledOnce()
  })
})
