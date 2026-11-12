import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SidebarPanelChats from '@/components/sidebar/SidebarPanelChats.vue'
import { useAuthStore } from '@/stores/auth'
import { useChatArchiveStore } from '@/stores/chatArchive'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn().mockResolvedValue({
    success: true,
    chats: [],
    total: 0,
    offset: 0,
    limit: 30,
    hasMore: false,
    activeRunChatIds: [],
  }),
  getConfigSync: () => ({ features: { iamSharing: true }, auth: {} }),
  getApiBaseUrl: () => '',
}))

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
    getUser: () => ({ id: 1 }),
  },
}))

vi.mock('@/composables/useTour', () => ({
  useTour: () => ({ startTour: vi.fn() }),
}))

describe('SidebarPanelChats archived view', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows an empty archive and hides incoming chats', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = { id: 1, email: 'user@example.com', level: 'NEW' } as NonNullable<
      ReturnType<typeof useAuthStore>['user']
    >
    useChatArchiveStore().viewingArchived = true

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/chats', component: { template: '<div />' } },
        { path: '/groups', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(SidebarPanelChats, {
      global: {
        plugins: [pinia, router],
        stubs: {
          Icon: true,
          ChatHistoryList: { template: '<div><slot name="empty" /></div>' },
        },
      },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="btn-sidebar-v2-chats-toggle"]').text()).toContain('Archived')
    expect(wrapper.get('[data-testid="text-sidebar-v2-archived-empty"]').text()).toBe(
      'No archived chats.'
    )
    expect(wrapper.find('[data-testid="section-sidebar-incoming"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-chat-v2-show-all"]').exists()).toBe(false)

    useChatArchiveStore().loading = true
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="text-sidebar-v2-archived-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-sidebar-v2-chats-empty"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
