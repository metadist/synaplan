import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchVisitorConnectionToken, fetchSubscriptionToken } from '@/services/realtime/tokenApi'
import { ApiError } from '@/services/api/httpClient'

/**
 * `tokenApi`'s anonymous visitor flow deliberately bypasses `httpClient` (see
 * the module docblock) and hand-rolls `fetch`. These tests guard the one
 * property that matters for #1381/#1451: a non-2xx response must throw an
 * `ApiError` carrying the real HTTP status, not a plain `Error` — otherwise
 * `RealtimeClient`'s terminal-failure detection can never fire and a
 * genuinely unrecoverable 403/404 retries forever.
 */
describe('tokenApi (anonymous visitor flow)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('fetchVisitorConnectionToken', () => {
    it('resolves the Zod-validated payload on success', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: true,
          json: async () => ({ token: 't', expiresIn: 60, subject: 'widget:wdg_1:sid_1' }),
        })
      )

      const result = await fetchVisitorConnectionToken('wdg_1', 'sid_1', 'https://api.example.test')
      expect(result).toEqual({ token: 't', expiresIn: 60, subject: 'widget:wdg_1:sid_1' })
    })

    it('throws an ApiError carrying the HTTP status on a 404 (unknown widget/session pair)', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 404 }))

      const error = await fetchVisitorConnectionToken(
        'wdg_1',
        'sid_1',
        'https://api.example.test'
      ).catch((e: unknown) => e)
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(404)
    })

    it('throws an ApiError carrying the HTTP status on a 403 (disallowed origin)', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }))

      const error = await fetchVisitorConnectionToken(
        'wdg_1',
        'sid_1',
        'https://api.example.test'
      ).catch((e: unknown) => e)
      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(403)
    })
  })

  describe('fetchSubscriptionToken (anonymous)', () => {
    it('throws an ApiError carrying the HTTP status on failure', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }))

      const error = await fetchSubscriptionToken('widget:session.wdg_1.sid_1', {
        anonymous: true,
        widgetId: 'wdg_1',
        sessionId: 'sid_1',
        apiBaseUrl: 'https://api.example.test',
      }).catch((e: unknown) => e)

      expect(error).toBeInstanceOf(ApiError)
      expect((error as ApiError).status).toBe(403)
    })

    it('resolves the Zod-validated payload on success', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: true,
          json: async () => ({ token: 't', channel: 'widget:session.wdg_1.sid_1', expiresIn: 60 }),
        })
      )

      const result = await fetchSubscriptionToken('widget:session.wdg_1.sid_1', {
        anonymous: true,
        widgetId: 'wdg_1',
        sessionId: 'sid_1',
        apiBaseUrl: 'https://api.example.test',
      })
      expect(result).toEqual({ token: 't', channel: 'widget:session.wdg_1.sid_1', expiresIn: 60 })
    })
  })
})
