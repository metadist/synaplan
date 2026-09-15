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

  it('opens People and redirects ?tab=users even when groups are off', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: false } })

    expect(peopleRouteGuard()).toBe(true)
    expect(groupsRouteGuard(groupsRoute())).toEqual({
      name: 'not-found',
      params: { pathMatch: ['groups'] },
      query: {},
      hash: '',
    })
    expect(adminUsersTabRedirect(routeWithTab('users'))).toEqual({ name: 'admin-people' })
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })

  it('opens People, /groups and redirects ?tab=users when groups are on', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })

    expect(peopleRouteGuard()).toBe(true)
    expect(groupsRouteGuard(groupsRoute())).toBe(true)
    expect(adminUsersTabRedirect(routeWithTab('users'))).toEqual({ name: 'admin-people' })
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })

  it('opens People when platform links are on and groups are off', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: false, platformLinksEnabled: true } })

    expect(peopleRouteGuard()).toBe(true)
    expect(groupsRouteGuard(groupsRoute())).toEqual({
      name: 'not-found',
      params: { pathMatch: ['groups'] },
      query: {},
      hash: '',
    })
    expect(adminUsersTabRedirect(routeWithTab('users'))).toEqual({ name: 'admin-people' })
  })

  it('opens People when policies are on and groups are off', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: false, iamPolicies: true } })

    expect(peopleRouteGuard()).toBe(true)
  })
})
