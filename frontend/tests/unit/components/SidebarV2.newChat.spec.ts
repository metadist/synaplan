import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SidebarV2 from '@/components/SidebarV2.vue'
import { httpClient } from '@/services/api/httpClient'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn().mockResolvedValue({ chats: [], total: 0, success: true }),
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

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
  },
}))

const mountSidebar = async ({ guest = false } = {}) => {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (!guest) {
    useAuthStore().user = { id: 1, email: 'user@example.com', level: 'NEW' } as NonNullable<
      ReturnType<typeof useAuthStore>['user']
    >
  }

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/chats', component: { template: '<div />' } },
      { path: '/files', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
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

function pendingCreate() {
  let release!: (chat: null) => void
  const promise = new Promise<null>((resolve) => {
    release = resolve
  })
  return { promise, release }
}

describe('SidebarV2 New Chat lock', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = '<div id="app"></div>'
    vi.mocked(httpClient).mockClear()
  })

  it('shows guests a sign-up action instead of loading a chat list', async () => {
    const wrapper = await mountSidebar({ guest: true })

    expect(wrapper.find('[data-testid="section-sidebar-chats-guest"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-sidebar-v2-new-chat"]').exists()).toBe(false)
    expect(vi.mocked(httpClient).mock.calls.some(([url]) => url === '/api/v1/chats')).toBe(false)
    wrapper.unmount()
  })

  it('releases the rail button when create settles and ignores a second click', async () => {
    const wrapper = await mountSidebar()
    const chats = useChatsStore()
    const pending = pendingCreate()
    const create = vi.spyOn(chats, 'findOrCreateEmptyChat').mockReturnValue(pending.promise)

    const button = wrapper.get('[data-testid="btn-sidebar-v2-new-chat"]')
    await button.trigger('click')
    expect(button.attributes('disabled')).toBe('')
    expect(create).toHaveBeenCalledTimes(1)

    await button.trigger('click')
    expect(create).toHaveBeenCalledTimes(1)

    pending.release(null)
    await flushPromises()
    expect(button.attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('keeps search and profile fixed and pages the chat list by 30', async () => {
    const chats = Array.from({ length: 45 }, (_, index) => ({
      id: index + 1,
      title: `Topic ${index + 1}`,
      createdAt: '2026-01-01T00:00:00Z',
      updatedAt: new Date(Date.UTC(2026, 0, 1, 0, 45 - index)).toISOString(),
      messageCount: 2,
      pinned: false,
    }))
    vi.mocked(httpClient).mockImplementation(async (url: unknown) => {
      if (typeof url === 'string' && url.startsWith('/api/v1/chats')) {
        return { chats, success: true, activeRunChatIds: [] }
      }
      return { success: true }
    })

    const wrapper = await mountSidebar()
    const rows = () => wrapper.findAll('[data-testid="row-chat-v2"]')
    expect(rows()).toHaveLength(30)

    const scroll = wrapper.get('[data-testid="section-sidebar-scroll"]')
    const footer = wrapper.get('[data-testid="section-sidebar-footer"]')
    expect(scroll.element.contains(footer.element)).toBe(false)
    expect(wrapper.get('[data-testid="btn-sidebar-v2-search"]').isVisible()).toBe(true)
    expect(wrapper.get('[data-testid="btn-sidebar-v2-user"]').isVisible()).toBe(true)

    await scroll.trigger('scroll')
    expect(rows()).toHaveLength(45)

    vi.mocked(httpClient).mockResolvedValue({ chats: [], total: 0, success: true })
    wrapper.unmount()
  })
})
