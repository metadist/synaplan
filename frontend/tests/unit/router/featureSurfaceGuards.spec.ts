import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { desktopRouteGuard, savedTasksRouteGuard } from '@/router/featureSurfaceGuards'

describe('featureSurfaceGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('sends saved tasks and desktop to not-found when the flags are off', () => {
    getConfigSync.mockReturnValue({ features: { savedTasks: false, desktopAgentEnabled: false } })

    expect(savedTasksRouteGuard()).toEqual({ name: 'not-found' })
    expect(desktopRouteGuard()).toEqual({ name: 'not-found' })
  })

  it('allows the routes when the flags are on', () => {
    getConfigSync.mockReturnValue({ features: { savedTasks: true, desktopAgentEnabled: true } })

    expect(savedTasksRouteGuard()).toBe(true)
    expect(desktopRouteGuard()).toBe(true)
  })
})
