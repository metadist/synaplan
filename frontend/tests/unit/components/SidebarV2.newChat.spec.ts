import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SidebarV2 from '@/components/SidebarV2.vue'
import { useChatsStore } from '@/stores/chats'
import { useSidebarStore } from '@/stores/sidebar'

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

const mountSidebar = async () => {
  const pinia = createPinia()
  setActivePinia(pinia)

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

  it('releases the history-sheet New Chat button when create settles', async () => {
    const wrapper = await mountSidebar()
    const chats = useChatsStore()
    const pending = pendingCreate()
    vi.spyOn(chats, 'findOrCreateEmptyChat').mockReturnValue(pending.promise)
    useSidebarStore().chatSheetOpen = true
    await flushPromises()

    const button = document.querySelector<HTMLButtonElement>('[data-testid="btn-chat-modal-new"]')
    expect(button).not.toBeNull()
    button!.click()
    await flushPromises()
    expect(button!.disabled).toBe(true)

    pending.release(null)
    await flushPromises()
    expect(document.querySelector('[data-testid="btn-chat-modal-new"]')).toBeNull()
    wrapper.unmount()
  })
})
