import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAuthStore } from '@/stores/auth'

const loginApiMock = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost:8000',
  refreshAccessToken: vi.fn().mockResolvedValue({ success: true }),
  getConfigSync: () => ({ realtime: { enabled: false, wsUrl: '' } }),
  beginAuthMutation: vi.fn(),
  endAuthMutation: vi.fn(),
  getInFlightRefresh: vi.fn().mockReturnValue(null),
  isAuthMutationInProgress: vi.fn().mockReturnValue(false),
  awaitAuthMutation: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('@/stores/realtime', () => ({
  useRealtimeStore: () => ({
    disconnect: vi.fn().mockResolvedValue(undefined),
  }),
}))

vi.mock('@/stores/mediaJobs', () => ({
  useMediaJobsStore: () => ({
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
  }),
}))

vi.mock('@/services/authService', async () => {
  const { ref } = await import('vue')
  const userRef = ref<{ id: number; email: string; level: string } | null>(null)

  return {
    authService: {
      getUser: () => userRef,
      getImpersonator: () => ref(null),
      getCurrentUser: vi.fn(),
      getInFlightRefresh: vi.fn().mockReturnValue(null),
      login: (...args: unknown[]) => loginApiMock(...args),
      logout: vi.fn(),
      isAuthenticated: vi.fn().mockReturnValue(false),
    },
  }
})

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    reload: vi.fn().mockResolvedValue(undefined),
    billing: { enabled: false },
  }),
}))

vi.mock('@/stores/guest', () => ({
  useGuestStore: () => ({ $reset: vi.fn() }),
}))

// resetUserScopedClientState() loads these lazily inside login(). The real
// chats store pulls in the generated API schemas, which is slow enough under
// a parallel run to blow the per-test timeout — and nothing here asserts on
// them. Stub them like the other user-scoped stores.
vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({ $reset: vi.fn() }),
}))

vi.mock('@/stores/history', () => ({
  useHistoryStore: () => ({ clear: vi.fn() }),
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

vi.mock('@/services/iapPostAuthRedemption', () => ({
  redeemPendingIapPurchaseAfterAuth: vi.fn(),
}))

describe('useAuthStore — login cookie race', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    loginApiMock.mockReset()
    localStorage.clear()
  })

  it('holds the auth-mutation lock and waits for an in-flight refresh before login', async () => {
    const httpClient = await import('@/services/api/httpClient')
    let resolveRefresh: (value: { success: boolean }) => void = () => undefined
    const refreshPromise = new Promise<{ success: boolean }>((resolve) => {
      resolveRefresh = resolve
    })
    vi.mocked(httpClient.getInFlightRefresh).mockReturnValue(refreshPromise)
    vi.mocked(httpClient.beginAuthMutation).mockClear()
    vi.mocked(httpClient.endAuthMutation).mockClear()

    loginApiMock.mockImplementation(async () => {
      const { authService } = await import('@/services/authService')
      authService.getUser().value = { id: 2, email: 'rs@example.com', level: 'ADMIN' }
      return { success: true }
    })

    const store = useAuthStore()
    const loginPromise = store.login('rs@example.com', 'secret')

    await vi.waitFor(() => {
      expect(httpClient.beginAuthMutation).toHaveBeenCalledTimes(1)
    })
    expect(loginApiMock).not.toHaveBeenCalled()

    resolveRefresh({ success: false })
    const ok = await loginPromise

    expect(ok).toBe(true)
    expect(loginApiMock).toHaveBeenCalledTimes(1)
    expect(httpClient.endAuthMutation).toHaveBeenCalledTimes(1)
    expect(store.user?.email).toBe('rs@example.com')
  })

  it('releases the lock when login is rejected', async () => {
    const httpClient = await import('@/services/api/httpClient')
    vi.mocked(httpClient.getInFlightRefresh).mockReturnValue(null)
    vi.mocked(httpClient.beginAuthMutation).mockClear()
    vi.mocked(httpClient.endAuthMutation).mockClear()

    loginApiMock.mockResolvedValue({ success: false, error: 'Invalid credentials' })

    const store = useAuthStore()
    const ok = await store.login('rs@example.com', 'wrong')

    expect(ok).toBe(false)
    expect(httpClient.beginAuthMutation).toHaveBeenCalledTimes(1)
    expect(httpClient.endAuthMutation).toHaveBeenCalledTimes(1)
  })
})
