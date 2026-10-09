import { beforeEach, describe, expect, it, vi } from 'vitest'

const auth = vi.hoisted(() => ({ isAdmin: false }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => auth,
}))

import { aiModelsTabRedirect, routingRedirect, topicsRedirect } from '@/router/aiSettingsRedirects'

describe('aiSettingsRedirects', () => {
  beforeEach(() => {
    auth.isAdmin = false
  })

  it('sends instructions bookmarks to the Topics tab and keeps the topic', () => {
    expect(topicsRedirect({ query: { topic: 'mail' } })).toEqual({
      path: '/ai/models',
      query: { topic: 'mail', tab: 'topics' },
    })
  })

  it('sends routing to Admin for admins and to AI settings for everyone else', () => {
    expect(routingRedirect()).toEqual({ path: '/ai/models' })
    auth.isAdmin = true
    expect(routingRedirect()).toEqual({ path: '/admin/setup', query: { tab: 'behavior' } })
  })

  it('moves the old catalog tabs to Admin › AI › Model catalog', () => {
    expect(aiModelsTabRedirect({ query: { tab: 'list' } })).toBe(true)
    expect(aiModelsTabRedirect({ query: { tab: 'edit', x: '1' } })).toEqual({
      path: '/ai/models',
      query: { x: '1' },
    })
    auth.isAdmin = true
    expect(aiModelsTabRedirect({ query: { tab: 'runs' } })).toEqual({
      path: '/admin/setup',
      query: { tab: 'catalog' },
    })
  })
})
