import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAuthStore } from '@/stores/auth'

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost:8000',
  refreshAccessToken: vi.fn().mockResolvedValue(true),
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

describe('useAuthStore — impersonation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    refreshUserMock.mockClear()
    startApiMock.mockReset()
    stopApiMock.mockReset()
  })

  afterEach(() => {
    vi.restoreAllMocks()
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
    startApiMock.mockResolvedValueOnce({
      success: false,
      error: 'You cannot impersonate another administrator.',
    })

    const store = useAuthStore()
    const result = await store.startImpersonation(2)

    expect(result.success).toBe(false)
    expect(result.error).toBe('You cannot impersonate another administrator.')
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
  })
})
