import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { aiAccountsRouteGuard, resetAiAccountsGatewayCache } from '@/composables/useAiAccounts'

const getConfigSync = vi.fn()
const getMessagesGatewayStatus = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

vi.mock('@/services/api/messagesGatewayApi', () => ({
  getMessagesGatewayStatus: () => getMessagesGatewayStatus(),
}))

vi.mock('@/components/config/HiggsfieldConnection.vue', () => ({
  default: { template: '<div data-testid="stub-higgsfield" />' },
}))

vi.mock('@/components/config/AnthropicByokSection.vue', () => ({
  default: { template: '<div data-testid="stub-anthropic" />' },
}))

import AiAccountsView from '@/views/AiAccountsView.vue'

async function mountView(path = '/ai/providers') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/ai/providers', name: 'ai-accounts', component: { template: '<div />' } },
      { path: '/not-found', name: 'not-found', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  return mount(AiAccountsView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div />' },
      },
    },
  })
}

describe('AiAccountsView', () => {
  beforeEach(() => {
    resetAiAccountsGatewayCache()
    getConfigSync.mockReset()
    getConfigSync.mockReturnValue({ features: {}, modules: {} })
    getMessagesGatewayStatus.mockReset()
    getMessagesGatewayStatus.mockResolvedValue({ enabled: true })
  })

  it('shows both sections when Higgsfield is configured and the gateway is on', async () => {
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="section-higgsfield"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-anthropic"]').exists()).toBe(true)
  })

  it('hides Higgsfield when the module is not configured', async () => {
    getConfigSync.mockReturnValue({
      features: {},
      modules: { higgsfield: { configured: false } },
    })
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="section-higgsfield"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-anthropic"]').exists()).toBe(true)
  })

  it('returns not-found when both sections are off', async () => {
    getConfigSync.mockReturnValue({
      features: {},
      modules: { higgsfield: { configured: false } },
    })
    getMessagesGatewayStatus.mockResolvedValue({ enabled: false })
    resetAiAccountsGatewayCache()
    await expect(aiAccountsRouteGuard()).resolves.toEqual({ name: 'not-found' })
  })

  it('opens the Higgsfield section from ?section=higgsfield', async () => {
    const wrapper = await mountView('/ai/providers?section=higgsfield')
    await flushPromises()

    expect(wrapper.find('#section-higgsfield').exists()).toBe(true)
  })
})
