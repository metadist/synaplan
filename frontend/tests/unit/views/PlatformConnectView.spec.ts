import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import { nextTick, ref } from 'vue'

const createApiKey = vi.fn()
const getPublicInstance = vi.fn()
const createLinkCode = vi.fn()
const getConfigSync = vi.fn()
const isAuthenticated = ref(true)
const userEmail = ref('demo@synaplan.com')
const logout = vi.fn()

vi.mock('@/services/api/apiKeysApi', () => ({
  createApiKey: (...args: unknown[]) => createApiKey(...args),
}))

vi.mock('@/services/api/platformLinksApi', () => ({
  platformLinksApi: {
    getPublicInstance: (...args: unknown[]) => getPublicInstance(...args),
    createLinkCode: (...args: unknown[]) => createLinkCode(...args),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

vi.mock('@/stores/auth', () => ({
  authReady: Promise.resolve(),
  useAuthStore: () => ({
    get isAuthenticated() {
      return isAuthenticated.value
    },
    get user() {
      return { email: userEmail.value }
    },
    logout,
  }),
}))

vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({ theme: { value: 'light' } }),
}))

vi.mock('@/utils/pendingAuthRedirect', () => ({
  setPendingRedirect: vi.fn(),
}))

import PlatformConnectView from '@/views/PlatformConnectView.vue'
import en from '@/i18n/en.json'

const assign = vi.fn()

function messages() {
  return {
    platformConnect: en.platformConnect,
    addinConnect: en.addinConnect,
  }
}

async function mountAt(path: string) {
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connect/platform', name: 'platform-connect', component: PlatformConnectView },
      { path: '/login', name: 'login', component: { template: '<div data-testid="login" />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: messages() } })
  const wrapper = mount(PlatformConnectView, {
    global: {
      plugins: [router, i18n],
    },
  })
  await flushPromises()
  await nextTick()
  return { wrapper, router }
}

describe('PlatformConnectView', () => {
  beforeEach(() => {
    isAuthenticated.value = true
    userEmail.value = 'demo@synaplan.com'
    getConfigSync.mockReturnValue({ features: { platformLinksEnabled: false } })
    createApiKey.mockReset()
    getPublicInstance.mockReset()
    createLinkCode.mockReset()
    logout.mockReset()
    assign.mockReset()
    vi.stubGlobal('location', { ...window.location, assign, origin: 'https://web.synaplan.com' })
    ;(window as unknown as { Office?: { onReady: (cb: () => void) => void } }).Office = {
      onReady: (cb) => cb(),
    }
  })

  it('shows the Outlook confirm card when platformLinksEnabled is false', async () => {
    const { wrapper } = await mountAt(
      '/connect/platform?client=outlook&state=abc&label=Outlook&redirect=https://localhost/relay'
    )
    expect(wrapper.find('[data-testid="section-ready"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-error"]').exists()).toBe(false)
    expect(getPublicInstance).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Outlook')
  })

  it('does not call the network for an unknown client', async () => {
    const { wrapper } = await mountAt('/connect/platform?client=evil&state=abc')
    expect(wrapper.find('[data-testid="section-error"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('not recognised')
    expect(getPublicInstance).not.toHaveBeenCalled()
    expect(createApiKey).not.toHaveBeenCalled()
  })

  it('redirects unauthenticated Outlook users with the full path', async () => {
    isAuthenticated.value = false
    const { router } = await mountAt('/connect/platform?client=outlook&state=a&label=b&redirect=c')
    expect(router.currentRoute.value.path).toBe('/login')
    const redirect = String(router.currentRoute.value.query.redirect ?? '')
    expect(redirect).toContain('/connect/platform')
    expect(redirect).toContain('client=outlook')
    expect(redirect).toContain('state=a')
    expect(redirect).toContain('label=b')
    expect(redirect).toContain('redirect=c')
  })

  it('assigns the relay with a payload fragment for Outlook', async () => {
    createApiKey.mockResolvedValue({
      success: true,
      api_key: { id: 9, key: 'sk_test', name: 'Outlook', scopes: [] },
    })
    const { wrapper } = await mountAt(
      '/connect/platform?client=outlook&state=nonce1&redirect=https://localhost/src/dialog/auth-relay.html'
    )
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()
    expect(createApiKey).toHaveBeenCalled()
    expect(assign).toHaveBeenCalled()
    const url = String(assign.mock.calls[0][0])
    expect(url).toContain('https://localhost/src/dialog/auth-relay.html')
    expect(url).toContain('#payload=')
  })

  it('ignores an unsafe relay host', async () => {
    createApiKey.mockResolvedValue({
      success: true,
      api_key: { id: 9, key: 'sk_test', name: 'Outlook', scopes: [] },
    })
    const { wrapper } = await mountAt(
      '/connect/platform?client=outlook&state=nonce1&redirect=https://evil.example/relay'
    )
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()
    expect(assign).not.toHaveBeenCalled()
  })
})
