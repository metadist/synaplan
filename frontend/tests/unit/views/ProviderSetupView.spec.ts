import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ProviderSetupView from '@/views/ProviderSetupView.vue'

vi.mock('@/services/api/providerKeysApi', () => ({
  listProviderKeys: vi.fn().mockResolvedValue({ providers: [], defaultChatProvider: '' }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    setup: { chatReady: false },
    reload: vi.fn().mockResolvedValue(undefined),
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

async function mountView(path = '/admin/setup') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin/setup', component: { template: '<div />' } },
      { path: '/ai/models', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ProviderSetupView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: true,
        LocalAiDownloadCard: true,
        ProviderKeyCard: true,
        ProviderHelpHint: true,
        ExtractionPlugTab: { template: '<div data-testid="extraction-plug-tab-stub" />' },
        WebSearchPlugTab: { template: '<div data-testid="web-search-plug-tab-stub" />' },
        RerankPlugTab: { template: '<div data-testid="rerank-plug-tab-stub" />' },
        Icon: true,
      },
    },
  })
  return { wrapper, router }
}

describe('ProviderSetupView own-service link', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('links to the Edit models tab on /ai/models', async () => {
    const { wrapper } = await mountView()
    await flushPromises()

    const link = wrapper.get('[data-testid="setup-own-service"]')
    expect(link.text()).toContain('Add your own service')
    expect(link.attributes('href')).toBe('/ai/models?tab=edit')
  })

  it('shows Models, Extraction, Web search and Reranking tabs', async () => {
    const { wrapper } = await mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="admin-setup-tab-models"]').text()).toContain('Models')
    expect(wrapper.get('[data-testid="admin-setup-tab-extraction"]').text()).toContain('Extraction')
    expect(wrapper.get('[data-testid="admin-setup-tab-web-search"]').text()).toContain('Web search')
    expect(wrapper.get('[data-testid="admin-setup-tab-rerank"]').text()).toContain('Reranking')
  })

  it('keeps other query params when switching tabs', async () => {
    const { wrapper, router } = await mountView('/admin/setup?connected=1')
    await flushPromises()

    await wrapper.get('[data-testid="admin-setup-tab-extraction"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1', tab: 'extraction' })

    await wrapper.get('[data-testid="admin-setup-tab-web-search"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1', tab: 'web-search' })

    await wrapper.get('[data-testid="admin-setup-tab-rerank"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1', tab: 'rerank' })
    expect(wrapper.find('[data-testid="rerank-plug-tab-stub"]').exists()).toBe(true)

    await wrapper.get('[data-testid="admin-setup-tab-models"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ connected: '1' })
  })
})
