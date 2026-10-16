import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import {
  approvalsRouteGuard,
  connectionsRedirect,
  savedTasksRouteGuard,
} from '@/router/featureSurfaceGuards'

const withQuery = (query: Record<string, string>) => ({ query })

describe('featureSurfaceGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('sends tasks and approvals to not-found when the flags are off', () => {
    getConfigSync.mockReturnValue({ features: { savedTasks: false, toolsApprovalsEnabled: false } })

    expect(savedTasksRouteGuard()).toEqual({ name: 'not-found' })
    expect(approvalsRouteGuard()).toEqual({ name: 'not-found' })
  })

  it('allows the routes when the flags are on', () => {
    getConfigSync.mockReturnValue({ features: { savedTasks: true, toolsApprovalsEnabled: true } })

    expect(savedTasksRouteGuard()).toBe(true)
    expect(approvalsRouteGuard()).toBe(true)
  })

  it('lands an OAuth callback on the matching app page with its result', () => {
    expect(connectionsRedirect(withQuery({ m365: 'connected' }))).toEqual({
      path: '/apps/microsoft365',
      query: { m365: 'connected' },
    })
    expect(connectionsRedirect(withQuery({ dropbox: 'error', reason: 'access_denied' }))).toEqual({
      path: '/apps/dropbox',
      query: { dropbox: 'error', reason: 'access_denied' },
    })
    expect(connectionsRedirect(withQuery({}))).toEqual({ path: '/apps/connected' })
  })
})
