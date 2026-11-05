import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { LocationQuery, RouteParams } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { settingsLocationFor, shouldShowSettingsHub } from '@/composables/useSettingsSections'

function location(
  overrides: {
    hash?: string
    query?: LocationQuery
    section?: string
  } = {}
): { hash: string; query: LocationQuery; params: RouteParams } {
  const params: RouteParams = {}
  if (overrides.section) params.section = overrides.section
  return {
    hash: overrides.hash ?? '',
    query: overrides.query ?? {},
    params,
  }
}

describe('settings hub route', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('keeps a bare /settings on the phone for a signed-in user', () => {
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    expect(shouldShowSettingsHub(location(), true)).toBe(true)
    expect(shouldShowSettingsHub(location({ hash: '#' }), true)).toBe(true)
  })

  it('sends desktop, guests, and named bookmarks to one section', () => {
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    expect(shouldShowSettingsHub(location(), false)).toBe(false)
    expect(shouldShowSettingsHub(location({ hash: '#memories' }), true)).toBe(false)
    expect(shouldShowSettingsHub(location({ query: { highlight: 'memories' } }), true)).toBe(false)
    expect(shouldShowSettingsHub(location({ query: { tab: 'subscription' } }), true)).toBe(false)
    expect(shouldShowSettingsHub(location({ section: 'appearance' }), true)).toBe(false)

    useAuthStore().user = null
    expect(shouldShowSettingsHub(location(), true)).toBe(false)
  })

  it('still resolves an empty settings URL to profile or appearance', () => {
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    expect(settingsLocationFor(location())).toEqual({ path: '/settings/profile', query: {} })

    useAuthStore().user = null
    expect(settingsLocationFor(location())).toEqual({ path: '/settings/appearance', query: {} })
  })
})
