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
import { refreshAccessToken } from '@/services/api/httpClient'
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
