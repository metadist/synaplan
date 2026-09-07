import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import { nextTick } from 'vue'

vi.mock('@/stores/auth', () => ({
  authReady: Promise.resolve(),
  useAuthStore: () => ({
    isAuthenticated: false,
    user: null,
    logout: vi.fn(),
  }),
}))

vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({ theme: { value: 'light' } }),
}))

vi.mock('@/utils/pendingAuthRedirect', () => ({
  setPendingRedirect: vi.fn(),
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: { platformLinksEnabled: false } }),
}))

import PlatformConnectView from '@/views/PlatformConnectView.vue'
import en from '@/i18n/en.json'

describe('addin/connect redirect', () => {
  it('rewrites /addin/connect to /connect/platform with client=outlook and keeps query', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: '/addin/connect',
          name: 'addin-connect',
          redirect: (to) => ({
            path: '/connect/platform',
            query: { ...to.query, client: 'outlook' },
          }),
        },
        {
          path: '/connect/platform',
          name: 'platform-connect',
          component: PlatformConnectView,
        },
        { path: '/login', name: 'login', component: { template: '<div />' } },
      ],
    })

    await router.push('/addin/connect?state=a&label=b&redirect=c')
    await router.isReady()

    expect(router.currentRoute.value.path).toBe('/connect/platform')
    expect(router.currentRoute.value.query.client).toBe('outlook')
    expect(router.currentRoute.value.query.state).toBe('a')
    expect(router.currentRoute.value.query.label).toBe('b')
    expect(router.currentRoute.value.query.redirect).toBe('c')

    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en: { platformConnect: en.platformConnect } },
    })
    mount({ template: '<router-view />' }, { global: { plugins: [router, i18n] } })
    await flushPromises()
    await nextTick()

    expect(router.currentRoute.value.path).toBe('/login')
    const redirect = String(router.currentRoute.value.query.redirect ?? '')
    const decoded = decodeURIComponent(redirect)
    expect(decoded.startsWith('/connect/platform')).toBe(true)
    expect(decoded).toContain('client=outlook')
    expect(decoded).toContain('state=a')
    expect(decoded).toContain('label=b')
    expect(decoded).toContain('redirect=c')
  })
})
