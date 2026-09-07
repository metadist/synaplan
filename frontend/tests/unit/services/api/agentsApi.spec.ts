import { beforeEach, describe, expect, it, vi } from 'vitest'
import { httpClient } from '@/services/api/httpClient'
import { agentsApi } from '@/services/api/agentsApi'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      message: string,
      public code?: string,
      public details?: Record<string, unknown>
    ) {
      super(message)
    }
  },
}))

describe('agentsApi', () => {
  beforeEach(() => {
    vi.mocked(httpClient).mockReset()
  })

  it('surfaces a schema parse failure as an error', async () => {
    vi.mocked(httpClient).mockRejectedValue(
      new Error('Invalid API response format: cards: Required')
    )

    await expect(agentsApi.gallery()).rejects.toThrow('Invalid API response format')
  })
})
