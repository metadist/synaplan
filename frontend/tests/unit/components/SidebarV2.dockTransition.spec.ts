import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SidebarV2 from '@/components/SidebarV2.vue'
import SidebarPanel from '@/components/sidebar/SidebarPanel.vue'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(async (url: unknown) =>
    typeof url === 'string' && url.startsWith('/api/v1/chats')
      ? { success: true, chats: [], total: 0, offset: 0, limit: 30, hasMore: false }
      : { success: true }
  ),
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
vi.mock('@/services/api/nativeHaptics', () => ({ triggerHapticImpact: vi.fn() }))
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
vi.mock('@/services/authService', () => ({ authService: { isAuthenticated: () => true } }))

/** A 1280px window: at/above the dock width, so the panel docks beside the chat. */
const wideWindow = (query: string) =>
  ({
    matches: query.includes('min-width: 1024px'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }) as unknown as MediaQueryList

describe('SidebarV2 at/above the dock width', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>'
    localStorage.clear()
    vi.spyOn(window, 'matchMedia').mockImplementation(wideWindow)
  })

  it('docks the panel without overlay styling or transition', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = { id: 1, email: 'user@example.com', level: 'NEW' } as NonNullable<
      ReturnType<typeof useAuthStore>['user']
    >
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: { template: '<div />' } }],
    })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(SidebarV2, {
      global: {
        plugins: [pinia, router],
        stubs: {
          Icon: true,
          ChatShareModal: true,
          ShareDialog: true,
          GuestHintPopover: true,
          SidebarNavFlyout: true,
          ChatKindFilter: true,
          ChatKindPill: true,
          ChatGrantPills: true,
        },
      },
      attachTo: document.body,
    })
    await flushPromises()

    // The overlay derivation follows the stable viewport mode, so a closing
    // docked panel keeps its dock layout (and dock leave animation) until it
    // is removed instead of collapsing into the rail mid-transition.
    const panel = wrapper.findComponent(SidebarPanel)
    expect(panel.exists()).toBe(true)
    expect(panel.props('overlay')).toBe(false)
    expect(panel.classes()).not.toContain('v2-sidebar-panel--overlay')
    expect(document.querySelector('[data-testid="btn-sidebar-v2-scrim"]')).toBeNull()
    wrapper.unmount()
  })
})
