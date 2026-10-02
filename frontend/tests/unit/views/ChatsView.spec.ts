import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import ChatsView from '@/views/ChatsView.vue'

async function mountView(path: string) {
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'chat', component: { template: '<div />' } },
      { path: '/chats', name: 'chats', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ChatsView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div data-testid="page-chats"><slot /></div>' },
        PageHeader: { template: '<div><slot /></div>' },
        ChatBrowser: { template: '<div data-testid="comp-chat-browser" />' },
        Teleport: true,
      },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('ChatsView', () => {
  beforeEach(() => {
    getConfigSync.mockReturnValue({ features: {} })
  })

  it('shows every chat on /chats and has no Incoming page', async () => {
    const { wrapper } = await mountView('/chats')

    expect(wrapper.find('[data-testid="comp-chat-browser"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-chats-incoming"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="page-chats-incoming"]').exists()).toBe(false)
  })

  it('stays the chat list when sharing is on', async () => {
    getConfigSync.mockReturnValue({ features: { iamSharing: true } })
    const { wrapper } = await mountView('/chats')

    expect(wrapper.find('[data-testid="comp-chat-browser"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-chats-incoming"]').exists()).toBe(false)
  })
})
