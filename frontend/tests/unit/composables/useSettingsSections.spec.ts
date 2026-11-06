import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { LocationQuery, RouteParams } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  settingsLocationFor,
  settingsSectionLinks,
  shouldShowSettingsHub,
} from '@/composables/useSettingsSections'

const nativeControl = vi.fn(() => false)
const purchaseAllowed = vi.fn(() => true)
const billingEnabled = vi.fn(() => false)
const voiceLiveEnabled = vi.fn(() => false)

vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => nativeControl(),
  isPurchaseAllowed: () => purchaseAllowed(),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    billing: {
      get enabled() {
        return billingEnabled()
      },
    },
    voiceLive: {
      get enabled() {
        return voiceLiveEnabled()
      },
    },
  }),
}))

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

function slugs(profileFailed: boolean, billing: boolean, nativeApp: boolean): string[] {
  return settingsSectionLinks(profileFailed, billing, nativeApp).map((item) => item.slug)
}

describe('settings hub route', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    nativeControl.mockReturnValue(false)
    purchaseAllowed.mockReturnValue(true)
    billingEnabled.mockReturnValue(false)
    voiceLiveEnabled.mockReturnValue(false)
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

describe('which settings pages exist (desktop vs native app)', () => {
  it('keeps the same personal pages on the web and the phone browser', () => {
    expect(slugs(false, false, false)).toEqual([
      'profile',
      'appearance',
      'chat',
      'data',
      'security',
      'legal',
      'delete',
    ])
  })

  it('adds App only when the native server control is present', () => {
    expect(slugs(false, false, true)).toContain('app')
    expect(slugs(false, false, false)).not.toContain('app')
  })

  it('adds Billing only when a purchase path is allowed', () => {
    expect(slugs(false, true, false)).toContain('billing')
    expect(slugs(false, false, false)).not.toContain('billing')
    expect(slugs(false, true, true)).toEqual([
      'profile',
      'appearance',
      'chat',
      'data',
      'billing',
      'security',
      'app',
      'legal',
      'delete',
    ])
  })
})

describe('settingsLocationFor keeps bookmarks on the page that shows them', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    nativeControl.mockReturnValue(false)
    purchaseAllowed.mockReturnValue(true)
    billingEnabled.mockReturnValue(false)
    voiceLiveEnabled.mockReturnValue(false)
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
  })

  it.each(['profile', 'appearance', 'chat', 'data', 'security', 'legal', 'delete'] as const)(
    'keeps /settings/%s',
    (section) => {
      expect(settingsLocationFor(location({ section }))).toEqual({
        path: `/settings/${section}`,
        query: {},
      })
    }
  )

  it('opens memories on Chat, the old app hash on App, and a plan tab on Billing', () => {
    expect(settingsLocationFor(location({ hash: '#memories' }))).toEqual({
      path: '/settings/chat',
      query: { highlight: 'memories' },
    })
    expect(settingsLocationFor(location({ query: { highlight: 'memories' } }))).toEqual({
      path: '/settings/chat',
      query: { highlight: 'memories' },
    })

    billingEnabled.mockReturnValue(true)
    expect(settingsLocationFor(location({ query: { tab: 'subscription' } }))).toEqual({
      path: '/settings/billing',
      query: {},
    })

    nativeControl.mockReturnValue(true)
    expect(settingsLocationFor(location({ hash: '#app' }))).toEqual({
      path: '/settings/app',
      query: {},
    })
    expect(settingsLocationFor(location({ section: 'app-server' }))).toEqual({
      path: '/settings/app',
      query: {},
    })
  })

  it('sends live-voice captions to AI settings', () => {
    voiceLiveEnabled.mockReturnValue(true)
    expect(settingsLocationFor(location({ section: 'voice' }))).toEqual({
      path: '/ai/models',
      query: { highlight: 'VOICE_LIVE' },
    })
  })

  it('does not open App or Billing when that surface is off', () => {
    expect(settingsLocationFor(location({ section: 'app' }))).toEqual({
      path: '/settings/profile',
      query: {},
    })
    expect(settingsLocationFor(location({ section: 'billing' }))).toEqual({
      path: '/settings/profile',
      query: {},
    })
    expect(settingsLocationFor(location({ section: 'nope' }))).toEqual({
      path: '/settings/profile',
      query: {},
    })
  })

  it('keeps a guest on Appearance even when the URL names Profile', () => {
    useAuthStore().user = null
    expect(settingsLocationFor(location({ section: 'profile' }))).toEqual({
      path: '/settings/appearance',
      query: {},
    })
  })

  it('opens App on the native shell and Billing when purchasing is allowed', () => {
    nativeControl.mockReturnValue(true)
    expect(settingsLocationFor(location({ section: 'app' }))).toEqual({
      path: '/settings/app',
      query: {},
    })

    billingEnabled.mockReturnValue(true)
    purchaseAllowed.mockReturnValue(true)
    expect(settingsLocationFor(location({ section: 'billing' }))).toEqual({
      path: '/settings/billing',
      query: {},
    })

    purchaseAllowed.mockReturnValue(false)
    expect(settingsLocationFor(location({ section: 'billing' }))).toEqual({
      path: '/settings/profile',
      query: {},
    })
  })
})
