import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import { nextTick, ref } from 'vue'

const connectOutlook = vi.fn()
const getPublicInstance = vi.fn()
const createLinkCode = vi.fn()
const getConfigSync = vi.fn()
const isAuthenticated = ref(true)
const userEmail = ref('demo@synaplan.com')
const logout = vi.fn()

vi.mock('@/services/api/platformLinksApi', () => ({
  platformLinksApi: {
    connectOutlook: (...args: unknown[]) => connectOutlook(...args),
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
const messageParent = vi.fn()
const openerPostMessage = vi.fn()

const PAYLOAD = {
  state: 'nonce1',
  apiKey: 'sk_test',
  keyId: 9,
  email: 'demo@synaplan.com',
  baseUrl: 'https://web.synaplan.com',
}

type OfficeStub = {
  onReady: (cb: () => void) => void
  context?: { ui: { messageParent: (data: string) => void } }
}

function installOffice(withMessageParent: boolean): void {
  const office: OfficeStub = { onReady: (cb) => cb() }
  if (withMessageParent) {
    office.context = { ui: { messageParent } }
  }
  ;(window as unknown as { Office?: OfficeStub }).Office = office
}

function messages() {
  return {
    platformConnect: en.platformConnect,
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
    connectOutlook.mockReset()
    getPublicInstance.mockReset()
    createLinkCode.mockReset()
    logout.mockReset()
    assign.mockReset()
    messageParent.mockReset()
    openerPostMessage.mockReset()
    vi.stubGlobal('location', { ...window.location, assign, origin: 'https://web.synaplan.com' })
    Object.defineProperty(window, 'opener', {
      configurable: true,
      value: { closed: false, postMessage: openerPostMessage },
    })
    installOffice(true)
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
    expect(connectOutlook).not.toHaveBeenCalled()
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

  it('follows the server-built relay redirect for Outlook', async () => {
    const relay = 'https://localhost:3000/src/dialog/auth-relay.html'
    connectOutlook.mockResolvedValue({
      success: true,
      redirect: `${relay}#payload=abc`,
      payload: PAYLOAD,
    })
    const { wrapper } = await mountAt(
      `/connect/platform?client=outlook&state=nonce1&redirect=${encodeURIComponent(relay)}`
    )
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()

    expect(connectOutlook).toHaveBeenCalledWith({
      state: 'nonce1',
      redirectUri: relay,
      baseUrl: 'https://web.synaplan.com',
    })
    expect(assign).toHaveBeenCalledWith(`${relay}#payload=abc`)
    expect(messageParent).not.toHaveBeenCalled()
    expect(openerPostMessage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-success"]').exists()).toBe(true)
  })

  it('falls back to Office messageParent when the server rejects the relay', async () => {
    connectOutlook.mockResolvedValue({ success: true, redirect: null, payload: PAYLOAD })
    const { wrapper } = await mountAt(
      '/connect/platform?client=outlook&state=nonce1&redirect=https://evil.example/relay'
    )
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()

    expect(assign).not.toHaveBeenCalled()
    expect(messageParent).toHaveBeenCalledTimes(1)
    expect(JSON.parse(String(messageParent.mock.calls[0][0]))).toEqual(PAYLOAD)
    expect(openerPostMessage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-success"]').exists()).toBe(true)
  })

  it('refuses to mint a key when there is neither a relay nor an Office channel', async () => {
    installOffice(false)
    const { wrapper } = await mountAt('/connect/platform?client=outlook&state=nonce1')
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()

    expect(connectOutlook).not.toHaveBeenCalled()
    expect(assign).not.toHaveBeenCalled()
    expect(openerPostMessage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-error"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(en.platformConnect.errorOfficeNotReady)
  })

  it('never posts the key to window.opener when the relay is rejected and Office is missing', async () => {
    installOffice(false)
    connectOutlook.mockResolvedValue({ success: true, redirect: null, payload: PAYLOAD })
    const { wrapper } = await mountAt(
      '/connect/platform?client=outlook&state=nonce1&redirect=https://evil.example/relay'
    )
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()

    expect(assign).not.toHaveBeenCalled()
    expect(openerPostMessage).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-error"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Open this page from inside Outlook')
  })
})
