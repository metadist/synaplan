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

  it('returns not-found when both sections are off', () => {
    getConfigSync.mockReturnValue({
      features: {},
      modules: { higgsfield: { configured: false } },
    })
    getMessagesGatewayStatus.mockResolvedValue({ enabled: false })
    resetAiAccountsGatewayCache()
    expect(aiAccountsRouteGuard()).toEqual({ name: 'not-found' })
  })

  it('lets the legacy Higgsfield bookmark through while the gateway status loads', () => {
    getConfigSync.mockReturnValue({
      features: {},
      modules: { higgsfield: { configured: false } },
    })
    resetAiAccountsGatewayCache()
    expect(
      aiAccountsRouteGuard({
        query: { section: 'higgsfield' },
      } as never)
    ).toBe(true)
  })

  it('opens the Higgsfield section from ?section=higgsfield', async () => {
    const wrapper = await mountView('/ai/providers?section=higgsfield')
    await flushPromises()

    expect(wrapper.find('#section-higgsfield').exists()).toBe(true)
    expect(wrapper.get('#section-higgsfield').attributes('data-open')).toBe('true')
  })

  it('folds later sections and opens them from the header or jump nav', async () => {
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.get('#section-higgsfield').attributes('data-open')).toBe('false')
    expect(wrapper.get('#section-anthropic').attributes('data-open')).toBe('false')
    expect(wrapper.find('[data-testid="btn-jump-section-anthropic"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-ai-accounts-anthropic"]').trigger('click')
    expect(wrapper.get('#section-higgsfield').attributes('data-open')).toBe('false')
    expect(wrapper.get('#section-anthropic').attributes('data-open')).toBe('true')

    await wrapper.get('[data-testid="btn-ai-accounts-accordion-toggle-all"]').trigger('click')
    expect(wrapper.get('#section-higgsfield').attributes('data-open')).toBe('true')
    expect(wrapper.get('#section-anthropic').attributes('data-open')).toBe('true')

    await wrapper.get('[data-testid="btn-ai-accounts-accordion-toggle-all"]').trigger('click')
    expect(wrapper.get('#section-higgsfield').attributes('data-open')).toBe('false')
    expect(wrapper.get('#section-anthropic').attributes('data-open')).toBe('false')
  })
})
