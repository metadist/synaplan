import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuth } from '@/composables/useAuth'
import { useAuthStore } from '@/stores/auth'

describe('useAuth', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // Reset fetch mock
    vi.restoreAllMocks()
  })

  it('should initialize with no auth', () => {
    const { isAuthenticated, user } = useAuth()
    expect(isAuthenticated.value).toBe(false)
    expect(user.value).toBe(null)
  })

  it('should login successfully', async () => {
    // Mock successful login response (cookie-based - no token in response body)
    global.fetch = vi.fn(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            user: { id: 1, email: 'test@example.com', level: 'PRO' },
          }),
      })
    ) as any

    const { login, isAuthenticated, user } = useAuth()
    const result = await login('test@example.com', 'password')

    expect(result).toBe(true)
    expect(isAuthenticated.value).toBe(true)
    expect(user.value?.email).toBe('test@example.com')
    // Note: Tokens are now stored in HttpOnly cookies, not localStorage
  })

  it('should logout and clear state', async () => {
    // Set up initial auth state via store
    const authStore = useAuthStore()
    authStore.user = { id: 1, email: 'test@example.com', level: 'PRO' }

    // Mock logout response
    global.fetch = vi.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({}),
      })
    ) as any

    const { logout, isAuthenticated, user } = useAuth()

    // Verify initial state
    expect(isAuthenticated.value).toBe(true)

    await logout()

    expect(isAuthenticated.value).toBe(false)
    expect(user.value).toBe(null)
    // Note: Cookies are cleared by backend, not frontend
  })

  it('should expose auth state from store', () => {
    // Set up auth state via store directly
    const authStore = useAuthStore()
    authStore.user = { id: 1, email: 'test@example.com', level: 'PRO' }

    const { isAuthenticated, user, userLevel, isPro } = useAuth()

    expect(isAuthenticated.value).toBe(true)
    expect(user.value?.email).toBe('test@example.com')
    expect(userLevel.value).toBe('PRO')
    expect(isPro.value).toBe(true)
  })

  it('should handle login failure', async () => {
    // Mock failed login response
    global.fetch = vi.fn(() =>
      Promise.resolve({
        ok: false,
        status: 401,
        json: () => Promise.resolve({ error: 'Invalid credentials' }),
      })
    ) as any

    const { login, isAuthenticated } = useAuth()
    const result = await login('test@example.com', 'wrongpassword')

    expect(result).toBe(false)
    expect(isAuthenticated.value).toBe(false)
  })
})
