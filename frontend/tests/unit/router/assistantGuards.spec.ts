import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { assistantsRouteGuard, instructionsRouteGuard } from '@/router/assistantGuards'

describe('assistantGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('leaves instructions and hides assistants when the flag is off', () => {
    getConfigSync.mockReturnValue({ features: { agentsEnabled: false } })

    expect(instructionsRouteGuard()).toBe(true)
    expect(assistantsRouteGuard()).toEqual({ name: 'not-found' })
  })

  it('redirects instructions to the gallery when the flag is on', () => {
    getConfigSync.mockReturnValue({ features: { agentsEnabled: true } })

    expect(instructionsRouteGuard()).toEqual({ path: '/ai/assistants' })
    expect(assistantsRouteGuard()).toBe(true)
  })
})
