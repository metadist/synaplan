/**
 * A 401 -> refresh chain that overlaps a login must not tear the new session
 * down. login() holds the auth-mutation lock while it swaps cookies; while the
 * lock is held a failed refresh must keep the session hint and must not call
 * logout() (which would clear the just-set user).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { beginAuthMutation, endAuthMutation, httpClient } from '@/services/api/httpClient'
import { hasSessionHint, setSessionHint } from '@/services/sessionHint'

const logoutMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const routerPushMock = vi.hoisted(() => vi.fn())

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ logout: logoutMock }),
  authReady: Promise.resolve(),
}))

vi.mock('@/router', () => ({
  default: { push: routerPushMock },
}))

vi.mock('@/router/setupGate', () => ({
  ensureWizardRequired: vi.fn().mockResolvedValue(false),
}))

const mockResponse = (status: number, body: unknown): Response =>
  ({
    ok: status >= 200 && status < 300,
    status,
    statusText: status === 401 ? 'Unauthorized' : '',
    json: async () => body,
    text: async () => JSON.stringify(body),
  }) as unknown as Response

const alwaysUnauthorized = (url: RequestInfo | URL): Promise<Response> => {
  if (String(url).includes('/api/v1/auth/refresh')) {
    return Promise.resolve(mockResponse(401, { error: 'Invalid or expired refresh token' }))
  }
  return Promise.resolve(mockResponse(401, { error: 'Authentication failed' }))
}

const setLocation = (pathname: string): void => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: {
      pathname,
      href: `http://localhost:5173${pathname}`,
      origin: 'http://localhost:5173',
    },
  })
}

describe('httpClient 401 during login', () => {
  let originalFetch: typeof fetch
  // httpClient's auth-failure loop detector is module state (2 failures in
  // 5 s); move the clock past the window for every test.
  let clock = 1_800_000_000_000

  beforeEach(() => {
    clock += 60_000
    vi.spyOn(Date, 'now').mockReturnValue(clock)
    originalFetch = globalThis.fetch
    localStorage.clear()
    logoutMock.mockClear()
    routerPushMock.mockClear()
    setSessionHint()
    setLocation('/login')
  })

  afterEach(() => {
    endAuthMutation()
    globalThis.fetch = originalFetch
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('keeps the hint and does not logout while the auth-mutation lock is held', async () => {
    // refreshAccessToken() waits on the lock before fetching, so the request
    // is started first and the lock is taken while its refresh is pending.
    let releaseRefresh: ((r: Response) => void) | null = null
    globalThis.fetch = vi.fn().mockImplementation((url: RequestInfo | URL) => {
      if (String(url).includes('/api/v1/auth/refresh')) {
        return new Promise<Response>((resolve) => {
          releaseRefresh = resolve
        })
      }
      return Promise.resolve(mockResponse(401, { error: 'Authentication failed' }))
    })

    const pending = httpClient('/api/v1/chats')
    await vi.waitFor(() => {
      expect(releaseRefresh).not.toBeNull()
    })

    beginAuthMutation()
    releaseRefresh!(mockResponse(401, { error: 'Invalid or expired refresh token' }))

    await expect(pending).rejects.toThrow('Authentication required')
    expect(hasSessionHint()).toBe(true)
    expect(logoutMock).not.toHaveBeenCalled()
    expect(routerPushMock).not.toHaveBeenCalled()
  })

  it('still clears the dead session on the login page when no login is running', async () => {
    globalThis.fetch = vi.fn().mockImplementation(alwaysUnauthorized)

    await expect(httpClient('/api/v1/chats')).rejects.toThrow('Authentication required')
    expect(hasSessionHint()).toBe(false)
    expect(logoutMock).toHaveBeenCalledTimes(1)
    // Already on a public auth page: no redirect loop.
    expect(routerPushMock).not.toHaveBeenCalled()
  })

  it('redirects to login with session_expired from an app page', async () => {
    setLocation('/chat')
    globalThis.fetch = vi.fn().mockImplementation(alwaysUnauthorized)

    await expect(httpClient('/api/v1/chats')).rejects.toThrow('Authentication required')
    expect(logoutMock).toHaveBeenCalledTimes(1)
    expect(routerPushMock).toHaveBeenCalledWith({
      name: 'login',
      query: { reason: 'session_expired' },
    })
  })
})
