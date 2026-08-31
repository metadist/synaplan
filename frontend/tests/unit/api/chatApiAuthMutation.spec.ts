/**
 * Regression guard: chatApi's SSE-token warmer refreshes on its own pool,
 * invisible to the httpClient auth-mutation lock. It must wait for an
 * in-progress impersonation swap to settle before refreshing, else it fires
 * with pre-swap cookies and clobbers the new session.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { beginAuthMutation, endAuthMutation } from '@/services/api/httpClient'
import { prefetchSseToken, clearSseToken } from '@/services/api/chatApi'
import { setSessionHint, clearSessionHint } from '@/services/sessionHint'

const flush = async (): Promise<void> => {
  // Let the fire-and-forget warmer chain (refresh → then → getSseToken) run.
  await new Promise((resolve) => setTimeout(resolve, 0))
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('chatApi SSE-token warmer — auth-mutation lock (impersonation swap)', () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
    clearSseToken()
    setSessionHint()
    fetchSpy = vi.spyOn(globalThis, 'fetch')
    fetchSpy.mockImplementation(((url: RequestInfo | URL) => {
      const target = String(url)
      if (target.includes('/api/v1/auth/refresh')) {
        return Promise.resolve(
          new Response(JSON.stringify({ success: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          })
        )
      }
      if (target.includes('/api/v1/auth/token')) {
        return Promise.resolve(
          new Response(JSON.stringify({ token: 'sse-token' }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          })
        )
      }
      return Promise.resolve(new Response('', { status: 404 }))
    }) as typeof fetch)
  })

  afterEach(async () => {
    // Release the lock, then DRAIN any parked warmer so its fire-and-forget
    // chain settles against THIS spy instead of leaking a stray fetch into the
    // next test. Only then restore the spy.
    endAuthMutation()
    await flush()
    clearSseToken()
    fetchSpy.mockRestore()
    clearSessionHint()
  })

  function refreshCalls(): unknown[][] {
    return (fetchSpy.mock.calls as unknown as unknown[][]).filter((call) =>
      String(call[0]).includes('/api/v1/auth/refresh')
    )
  }

  it('does NOT fire /auth/refresh while a principal swap holds the lock', async () => {
    beginAuthMutation()

    prefetchSseToken()
    await flush()

    // The warmer must be parked behind the swap, not racing it with pre-swap cookies.
    expect(refreshCalls()).toHaveLength(0)
  })

  it('fires /auth/refresh once the swap releases the lock', async () => {
    beginAuthMutation()

    prefetchSseToken()
    await flush()
    expect(refreshCalls()).toHaveLength(0)

    endAuthMutation()
    await flush()

    // Now the refresh runs — against the stable post-swap cookies.
    expect(refreshCalls()).toHaveLength(1)
  })

  it('fires /auth/refresh immediately when no swap is in progress', async () => {
    prefetchSseToken()
    await flush()

    expect(refreshCalls()).toHaveLength(1)
  })
})
