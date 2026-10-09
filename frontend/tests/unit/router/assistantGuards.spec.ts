import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { assistantsRouteGuard } from '@/router/assistantGuards'

describe('assistantGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('hides assistants when the flag is off', () => {
    getConfigSync.mockReturnValue({ features: { agentsEnabled: false } })
    expect(assistantsRouteGuard()).toEqual({ name: 'not-found' })
  })

  it('opens assistants when the flag is on', () => {
    getConfigSync.mockReturnValue({ features: { agentsEnabled: true } })
    expect(assistantsRouteGuard()).toBe(true)
  })
})
