import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { adminUsersTabRedirect, groupsRouteGuard, peopleRouteGuard } from '@/router/iamGuards'

function routeWithTab(tab?: string): RouteLocationNormalized {
  return {
    path: '/admin/people',
    query: tab === undefined ? {} : { tab },
    hash: '',
  } as RouteLocationNormalized
}

function groupsRoute(): RouteLocationNormalized {
  return {
    path: '/groups',
    query: {},
    hash: '',
  } as RouteLocationNormalized
}

describe('iamGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('sends People to the Operate user list and blocks /groups when groups are off', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: false } })

    expect(peopleRouteGuard()).toEqual({ name: 'admin', query: { tab: 'users' } })
    expect(groupsRouteGuard(groupsRoute())).toEqual({
      name: 'not-found',
      params: { pathMatch: ['groups'] },
      query: {},
      hash: '',
    })
    expect(adminUsersTabRedirect(routeWithTab('users'))).toBe(true)
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })

  it('opens People, /groups and redirects ?tab=users when groups are on', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })

    expect(peopleRouteGuard()).toBe(true)
    expect(groupsRouteGuard(groupsRoute())).toBe(true)
    expect(adminUsersTabRedirect(routeWithTab('users'))).toEqual({ name: 'admin-people' })
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })
})
