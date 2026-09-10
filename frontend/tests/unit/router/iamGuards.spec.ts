import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { adminUsersTabRedirect, peopleRouteGuard } from '@/router/iamGuards'

function routeWithTab(tab?: string): RouteLocationNormalized {
  return {
    path: '/admin/people',
    query: tab === undefined ? {} : { tab },
    hash: '',
  } as RouteLocationNormalized
}

describe('iamGuards', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('hides People and keeps /admin?tab=users on Admin when groups are off', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: false } })

    expect(peopleRouteGuard(routeWithTab())).toEqual({
      name: 'not-found',
      params: { pathMatch: ['admin', 'people'] },
      query: {},
      hash: '',
    })
    expect(adminUsersTabRedirect(routeWithTab('users'))).toBe(true)
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })

  it('opens People and redirects ?tab=users when groups are on', () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })

    expect(peopleRouteGuard()).toBe(true)
    expect(adminUsersTabRedirect(routeWithTab('users'))).toEqual({ name: 'admin-people' })
    expect(adminUsersTabRedirect(routeWithTab())).toBe(true)
  })
})
