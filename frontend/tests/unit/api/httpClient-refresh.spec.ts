/**
 * Regression tests for issue #204: token refresh must NOT hit the network
 * when no client-side session hint exists. A fresh visit (incognito,
 * cleared storage) should never produce the 401 cascade
 *
 *   POST /api/v1/auth/refresh → 401 → "Token refresh failed, logging out"
 *
 * even though no user has ever logged in on this browser.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { refreshAccessToken, beginAuthMutation, endAuthMutation } from '@/services/api/httpClient'
import { setSessionHint, clearSessionHint } from '@/services/sessionHint'

describe('httpClient.refreshAccessToken — session hint guard (#204)', () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
    fetchSpy = vi.spyOn(globalThis, 'fetch')
  })

  afterEach(() => {
    fetchSpy.mockRestore()
  })

  function refreshCalls(): unknown[][] {
    return (fetchSpy.mock.calls as unknown as unknown[][]).filter((call) =>
      String(call[0]).includes('/api/v1/auth/refresh')
    )
  }

  it('short-circuits without a network call when no session hint is set', async () => {
    clearSessionHint()

    const result = await refreshAccessToken()

    expect(result).toEqual({ success: false })
    expect(refreshCalls()).toHaveLength(0)
  })

  it('does perform the refresh once a session hint is present', async () => {
    setSessionHint()

    fetchSpy.mockImplementation(((url: RequestInfo | URL) => {
      if (String(url).includes('/api/v1/auth/refresh')) {
        return Promise.resolve(
          new Response(JSON.stringify({ success: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          })
        )
      }
      return Promise.resolve(new Response('', { status: 404 }))
    }) as typeof fetch)

    const result = await refreshAccessToken()

    expect(result.success).toBe(true)
    expect(refreshCalls()).toHaveLength(1)
  })

  it('clears the session hint when the server rejects the refresh', async () => {
    setSessionHint()

    fetchSpy.mockImplementation(((url: RequestInfo | URL) => {
      if (String(url).includes('/api/v1/auth/refresh')) {
        return Promise.resolve(
          new Response(JSON.stringify({ error: 'expired' }), {
            status: 401,
            headers: { 'Content-Type': 'application/json' },
          })
        )
      }
      return Promise.resolve(new Response('', { status: 404 }))
    }) as typeof fetch)

    await refreshAccessToken()

    // Subsequent call must short-circuit without hitting the network again.
    fetchSpy.mockClear()
    const secondResult = await refreshAccessToken()

    expect(secondResult).toEqual({ success: false })
    expect(refreshCalls()).toHaveLength(0)
  })
})

describe('httpClient.refreshAccessToken — auth-mutation lock (impersonation swap)', () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
    fetchSpy = vi.spyOn(globalThis, 'fetch')
    setSessionHint()
  })

  afterEach(() => {
    // Safety net: never leak an open lock into the next test.
    endAuthMutation()
    fetchSpy.mockRestore()
    clearSessionHint()
  })

  function refreshCalls(): unknown[][] {
    return (fetchSpy.mock.calls as unknown as unknown[][]).filter((call) =>
      String(call[0]).includes('/api/v1/auth/refresh')
    )
  }

  function mockRefresh(status: number): void {
    fetchSpy.mockImplementation(((url: RequestInfo | URL) => {
      if (String(url).includes('/api/v1/auth/refresh')) {
        return Promise.resolve(
          new Response(JSON.stringify(status === 200 ? { success: true } : { error: 'expired' }), {
            status,
            headers: { 'Content-Type': 'application/json' },
          })
        )
      }
      return Promise.resolve(new Response('', { status: 404 }))
    }) as typeof fetch)
  }

  it('parks a concurrent refresh while a swap is in flight, then performs a REAL refresh', async () => {
    mockRefresh(200)
    beginAuthMutation()

    const pending = refreshAccessToken()
    // While the lock is held the refresh must be parked, not fired.
    await Promise.resolve()
    expect(refreshCalls()).toHaveLength(0)

    endAuthMutation()
    const result = await pending

    expect(result.success).toBe(true)
    // A genuine network refresh happened against the now-stable post-swap cookie
    // (not a synthetic success).
    expect(refreshCalls()).toHaveLength(1)
  })

  it('propagates a post-swap refresh failure instead of faking success (prevents spurious logout)', async () => {
    mockRefresh(401)
    beginAuthMutation()

    const pending = refreshAccessToken()
    endAuthMutation()
    const result = await pending

    // Crucially NOT { success: true }: the caller must learn the refresh failed
    // so it can decide, rather than retrying straight into handleAuthFailure().
    expect(result.success).toBe(false)
    expect(refreshCalls()).toHaveLength(1)
  })

  it('bypassMutationLock refreshes immediately while the lock is held (no deadlock)', async () => {
    mockRefresh(200)
    beginAuthMutation()
    try {
      const result = await refreshAccessToken({ bypassMutationLock: true })
      expect(result.success).toBe(true)
      expect(refreshCalls()).toHaveLength(1)
    } finally {
      endAuthMutation()
    }
  })
})
