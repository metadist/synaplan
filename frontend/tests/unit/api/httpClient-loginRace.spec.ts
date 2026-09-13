/**
 * A 401 that started while the login form was open must not call logout().
 * logout() clears the session hint; getCurrentUser() then skips /auth/me and
 * a login that just set cookies looks logged out.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { httpClient } from '@/services/api/httpClient'
import { setSessionHint } from '@/services/sessionHint'

const logoutMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ logout: logoutMock }),
  authReady: Promise.resolve(),
}))

const mockResponse = (status: number, body: unknown): Response =>
  ({
    ok: status >= 200 && status < 300,
    status,
    statusText: status === 401 ? 'Unauthorized' : '',
    json: async () => body,
    text: async () => JSON.stringify(body),
  }) as unknown as Response

describe('httpClient 401 during login', () => {
  let originalFetch: typeof fetch

  beforeEach(() => {
    originalFetch = globalThis.fetch
    localStorage.clear()
    logoutMock.mockClear()
    setSessionHint()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: {
        pathname: '/login',
        href: 'http://localhost:5173/login',
        origin: 'http://localhost:5173',
      },
    })
  })

  afterEach(() => {
    globalThis.fetch = originalFetch
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('does not logout when refresh fails on the login page', async () => {
    globalThis.fetch = vi.fn().mockImplementation((url: RequestInfo | URL) => {
      if (String(url).includes('/api/v1/auth/refresh')) {
        return Promise.resolve(mockResponse(401, { error: 'Invalid or expired refresh token' }))
      }
      return Promise.resolve(mockResponse(401, { error: 'Authentication failed' }))
    })

    await expect(httpClient('/api/v1/chats')).rejects.toThrow('Authentication required')
    expect(logoutMock).not.toHaveBeenCalled()
  })
})
