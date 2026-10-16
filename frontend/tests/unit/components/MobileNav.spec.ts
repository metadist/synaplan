import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import MobileNav from '@/components/MobileNav.vue'
import { useChatsStore } from '@/stores/chats'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn().mockResolvedValue({ chats: [], total: 0 }),
  getApiBaseUrl: () => '',
  getConfigSync: () => ({
    billing: { enabled: false },
    auth: { registrationEnabled: true },
    features: { memoryService: false },
    plugins: [],
    branding: {},
    build: {},
  }),
  getConfig: vi.fn(),
}))

vi.mock('@/services/api/nativeHaptics', () => ({
  triggerHapticImpact: vi.fn(),
}))

vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => false,
  isPurchaseAllowed: () => true,
  openNativeServerOverlay: vi.fn(),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn(), prompt: vi.fn() }),
}))

vi.mock('@/composables/useAuth', () => ({
  useAuth: () => ({ logout: vi.fn(), isImpersonating: false }),
}))

vi.mock('@/services/featuresService', () => ({
  getFeaturesStatus: vi.fn().mockResolvedValue({ features: {} }),
}))

function stubIntersectionObserver() {
  class FakeIntersectionObserver {
    observe() {}
    disconnect() {}
    unobserve() {}
    takeRecords() {
      return []
    }
  }
  vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver)
}

const mountNav = async (path = '/') => {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/channels', component: { template: '<div />' } },
      { path: '/files', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const wrapper = mount(MobileNav, {
    global: {
      plugins: [pinia, router],
      stubs: {
        Icon: true,
        ChatShareModal: true,
        ShareDialog: true,
        GuestHintPopover: true,
        Teleport: true,
        Transition: { template: '<div><slot /></div>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('MobileNav', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    stubIntersectionObserver()
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('lists chats under the primary buttons without a separate History control', async () => {
    const wrapper = await mountNav()

    expect(wrapper.find('[data-testid="btn-mobile-nav-new"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-mobile-nav-history"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-mobile-nav-files"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-mobile-nav-more"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-mobile-history"]').exists()).toBe(true)

    const buttons = wrapper
      .findAll('[data-testid^="btn-mobile-nav-"]')
      .map((btn) => btn.attributes('data-testid'))
    expect(buttons).toEqual(['btn-mobile-nav-new', 'btn-mobile-nav-files', 'btn-mobile-nav-more'])
  })

  it('releases the New Chat lock when create settles and ignores a second click while it runs', async () => {
    const wrapper = await mountNav()
    const chats = useChatsStore()
    let release!: (chat: null) => void
    const pending = new Promise<null>((resolve) => {
      release = resolve
    })
    const create = vi.spyOn(chats, 'findOrCreateEmptyChat').mockReturnValue(pending)

    const button = wrapper.get('[data-testid="btn-mobile-nav-new"]')
    await button.trigger('click')
    expect(button.attributes('disabled')).toBe('')
    expect(create).toHaveBeenCalledTimes(1)

    await button.trigger('click')
    expect(create).toHaveBeenCalledTimes(1)

    release(null)
    await flushPromises()
    expect(button.attributes('disabled')).toBeUndefined()
  })
})
