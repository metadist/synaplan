import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import MobileNav from '@/components/MobileNav.vue'

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
        GuestHintPopover: true,
        Teleport: true,
        Transition: false,
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

  it('places a History link with the primary buttons, matching the desktop rail', async () => {
    const wrapper = await mountNav()

    const newChat = wrapper.find('[data-testid="btn-mobile-nav-new"]')
    const history = wrapper.find('[data-testid="btn-mobile-nav-history"]')
    const files = wrapper.find('[data-testid="btn-mobile-nav-files"]')
    const more = wrapper.find('[data-testid="btn-mobile-nav-more"]')

    expect(newChat.exists()).toBe(true)
    expect(history.exists()).toBe(true)
    expect(files.exists()).toBe(true)
    expect(more.exists()).toBe(true)
    expect(history.text()).toContain('History')

    const buttons = wrapper
      .findAll('[data-testid^="btn-mobile-nav-"]')
      .map((btn) => btn.attributes('data-testid'))
    expect(buttons).toEqual([
      'btn-mobile-nav-new',
      'btn-mobile-nav-history',
      'btn-mobile-nav-files',
      'btn-mobile-nav-more',
    ])
  })

  it('jumps to the in-drawer history list and collapses More', async () => {
    const wrapper = await mountNav('/channels')
    await flushPromises()

    const moreSheet = wrapper.find('[data-testid="sheet-mobile-more"]')
    expect(moreSheet.isVisible()).toBe(true)

    await wrapper.find('[data-testid="btn-mobile-nav-history"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="sheet-mobile-more"]').isVisible()).toBe(false)
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-mobile-history"]').exists()).toBe(true)
  })
})
