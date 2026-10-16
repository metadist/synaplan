/**
 * Regression guard: the legacy apiService cookie-refresh pool used to skip
 * the httpClient auth-mutation lock. A 401 from files/RAG during impersonation
 * could then Set-Cookie an admin token after the swap and unmount the banner.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { beginAuthMutation, endAuthMutation } from '@/services/api/httpClient'
import { api, getInFlightRefresh } from '@/services/apiService'
import { setSessionHint, clearSessionHint } from '@/services/sessionHint'

const flush = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 0))
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('apiService refresh — auth-mutation lock (impersonation swap)', () => {
  let fetchSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
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
      return Promise.resolve(new Response('unauthorized', { status: 401 }))
    }) as typeof fetch)
  })

  afterEach(() => {
    endAuthMutation()
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

    const pending = api.get('/api/v1/files').catch(() => undefined)
    await flush()

    expect(refreshCalls()).toHaveLength(0)
    expect(getInFlightRefresh()).toBeNull()

    endAuthMutation()
    await pending
  })

  it('fires /auth/refresh once the swap releases the lock', async () => {
    beginAuthMutation()

    const pending = api.get('/api/v1/files').catch(() => undefined)
    await flush()
    expect(refreshCalls()).toHaveLength(0)

    endAuthMutation()
    await pending
    await flush()

    expect(refreshCalls()).toHaveLength(1)
  })
})
