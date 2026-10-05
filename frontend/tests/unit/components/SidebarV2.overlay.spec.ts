import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SidebarV2 from '@/components/SidebarV2.vue'
import { useAuthStore } from '@/stores/auth'

const chats = [
  {
    id: 1,
    title: 'Trip plan',
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
    messageCount: 2,
    isShared: false,
  },
]

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(async (url: unknown) =>
    typeof url === 'string' && url.startsWith('/api/v1/chats')
      ? { success: true, chats, total: 1, offset: 0, limit: 30, hasMore: false }
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

/** A 900px window: below the dock width, so the panel floats over the chat. */
const narrowWindow = (query: string) =>
  ({
    matches: !query.includes('min-width: 1024px'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }) as unknown as MediaQueryList

const mountSidebar = async () => {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = { id: 1, email: 'user@example.com', level: 'NEW' } as NonNullable<
    ReturnType<typeof useAuthStore>['user']
  >
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/chats', component: { template: '<div />' } },
      { path: '/files', component: { template: '<div />' } },
    ],
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
  return wrapper
}

describe('SidebarV2 below the dock width', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>'
    localStorage.clear()
    vi.spyOn(window, 'matchMedia').mockImplementation(narrowWindow)
  })

  it('shows only the rail, and the panel opens over the chat', async () => {
    const wrapper = await mountSidebar()
    expect(wrapper.find('[data-testid="section-sidebar-panel"]').exists()).toBe(false)

    await wrapper.get('[data-testid="btn-sidebar-v2-expand"]').trigger('click')
    await flushPromises()

    const panel = wrapper.get('[data-testid="section-sidebar-panel"]')
    expect(panel.classes()).toContain('v2-sidebar-panel--overlay')
    expect(document.querySelector('[data-testid="btn-sidebar-v2-scrim"]')).not.toBeNull()
    // The overlay is never remembered as the docked preference.
    expect(localStorage.getItem('sidebar-panel-collapsed')).toBeNull()
    wrapper.unmount()
  })

  it('closes after a chat is chosen, on the scrim, and on Escape', async () => {
    const wrapper = await mountSidebar()
    const open = async () => {
      await wrapper.get('[data-testid="btn-sidebar-v2-expand"]').trigger('click')
      await flushPromises()
    }
    const panelOpen = () => wrapper.find('[data-testid="section-sidebar-panel"]').exists()

    await open()
    await wrapper.get('[data-testid="row-chat-v2"] .chat-row-btn').trigger('click')
    await flushPromises()
    expect(panelOpen()).toBe(false)

    await open()
    document.querySelector<HTMLElement>('[data-testid="btn-sidebar-v2-scrim"]')?.click()
    await flushPromises()
    expect(panelOpen()).toBe(false)

    await open()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(panelOpen()).toBe(false)
    expect(document.activeElement).toBe(
      wrapper.get('[data-testid="btn-sidebar-v2-expand"]').element
    )
    wrapper.unmount()
  })
})
