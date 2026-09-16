import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { agentsApi } from '@/services/api/agentsApi'

describe('agentsApi.remove against a real 204', () => {
  let originalFetch: typeof fetch

  beforeEach(() => {
    originalFetch = globalThis.fetch
    localStorage.clear()
  })

  afterEach(() => {
    globalThis.fetch = originalFetch
    vi.restoreAllMocks()
  })

  it('resolves when DELETE answers 204 No Content', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 204,
      statusText: 'No Content',
      headers: { get: () => null },
      json: async () => {
        throw new SyntaxError('Unexpected end of JSON input')
      },
    } as unknown as Response)

    await expect(agentsApi.remove(12)).resolves.toBeUndefined()
    expect(globalThis.fetch).toHaveBeenCalled()
    const url = String(vi.mocked(globalThis.fetch).mock.calls[0]?.[0] ?? '')
    expect(url).toContain('/api/v1/agents/12')
  })
})
