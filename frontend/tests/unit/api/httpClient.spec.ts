/**
 * Tests for `httpClient`'s structured error path.
 *
 * Issue #883: a non-premium user picking a premium embedding model on
 * `/config/ai-models` got a generic "Failed to save model configuration"
 * toast even though the backend already returned the correct human-readable
 * reason in `{ message: 'Switching the embedding model requires an active
 * paid subscription. Current level: NEW.', error: 'requires_premium', ... }`.
 *
 * The fix is two-piece:
 *   1. `httpClient` throws an `ApiError` that carries `status`, `code`
 *      (the backend's machine-readable `error` field) and `details`
 *      (the full body).
 *   2. `ApiError.message` prefers the backend's `message` field over the
 *      legacy `error` code so generic consumers immediately get a useful
 *      string with no extra plumbing.
 *
 * The frontend view is then free to inspect `code === 'requires_premium'`
 * and wrap the message in a richer i18n string with an upgrade CTA.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { ApiError, httpClient } from '@/services/api/httpClient'

type MockResponseInit = {
  ok: boolean
  status: number
  statusText?: string
  body: unknown
}

const mockResponse = ({ ok, status, statusText, body }: MockResponseInit): Response => {
  return {
    ok,
    status,
    statusText: statusText ?? '',
    json: async () => body,
    text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
    blob: async () => new Blob([JSON.stringify(body)]),
  } as unknown as Response
}

describe('httpClient ApiError shape (issue #883)', () => {
  let originalFetch: typeof fetch

  beforeEach(() => {
    originalFetch = globalThis.fetch
  })

  afterEach(() => {
    globalThis.fetch = originalFetch
    vi.restoreAllMocks()
  })

  it('throws ApiError with the backend message preferred over the error code', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      mockResponse({
        ok: false,
        status: 403,
        body: {
          error: 'requires_premium',
          message:
            'Switching the embedding model requires an active paid subscription. Current level: NEW.',
          capability: 'VECTORIZE',
          currentLevel: 'NEW',
        },
      })
    )

    await expect(
      httpClient('/api/v1/config/models/defaults', { method: 'POST' })
    ).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError)
      const apiError = err as ApiError
      // Generic consumers get the human-readable text via .message — they
      // don't have to know about the code/details split.
      expect(apiError.message).toBe(
        'Switching the embedding model requires an active paid subscription. Current level: NEW.'
      )
      // Premium-aware consumers can branch on .code without parsing strings.
      expect(apiError.status).toBe(403)
      expect(apiError.code).toBe('requires_premium')
      expect(apiError.details).toMatchObject({
        capability: 'VECTORIZE',
        currentLevel: 'NEW',
      })
      return true
    })
  })

  it('falls back to the bare `error` field when no `message` is present', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      mockResponse({
        ok: false,
        status: 400,
        body: { error: 'Invalid data' },
      })
    )

    await expect(
      httpClient('/api/v1/config/models/defaults', { method: 'POST' })
    ).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError)
      const apiError = err as ApiError
      expect(apiError.message).toBe('Invalid data')
      expect(apiError.status).toBe(400)
      expect(apiError.code).toBe('Invalid data')
      return true
    })
  })

  it('includes admin debug info in the message when present', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      mockResponse({
        ok: false,
        status: 503,
        body: {
          error: 'Memory service temporarily unavailable',
          debug: 'Qdrant connection refused at qdrant:6333',
        },
      })
    )

    await expect(httpClient('/api/v1/user/memories', {})).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError)
      const apiError = err as ApiError
      expect(apiError.status).toBe(503)
      expect(apiError.debug).toBe('Qdrant connection refused at qdrant:6333')
      // The legacy "[Debug] " annotation is preserved in .message so existing
      // toasts in admin views keep showing the operator-only diagnostic.
      expect(apiError.message).toContain('Memory service temporarily unavailable')
      expect(apiError.message).toContain('[Debug] Qdrant connection refused at qdrant:6333')
      return true
    })
  })

  it('falls back to a synthetic HTTP message when the body is not JSON', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 502,
      statusText: 'Bad Gateway',
      json: async () => {
        throw new SyntaxError('Unexpected token <')
      },
    } as unknown as Response)

    await expect(
      httpClient('/api/v1/config/models/defaults', { method: 'POST' })
    ).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError)
      const apiError = err as ApiError
      expect(apiError.status).toBe(502)
      expect(apiError.message).toBe('HTTP 502: Bad Gateway')
      expect(apiError.code).toBeUndefined()
      return true
    })
  })
})
