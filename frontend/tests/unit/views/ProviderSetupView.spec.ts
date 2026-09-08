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

function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin/setup', component: { template: '<div />' } },
      { path: '/ai/models', component: { template: '<div />' } },
    ],
  })
  return mount(ProviderSetupView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: true,
        LocalAiDownloadCard: true,
        ProviderKeyCard: true,
        ProviderHelpHint: true,
        ExtractionPlugTab: { template: '<div data-testid="extraction-plug-tab-stub" />' },
        Icon: true,
      },
    },
  })
}

describe('ProviderSetupView own-service link', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('links to the Edit models tab on /ai/models', async () => {
    const wrapper = mountView()
    await flushPromises()

    const link = wrapper.get('[data-testid="setup-own-service"]')
    expect(link.text()).toContain('Add your own service')
    expect(link.attributes('href')).toBe('/ai/models?tab=edit')
  })

  it('shows Models and Extraction tabs and hides later plug tabs', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="admin-setup-tab-models"]').text()).toContain('Models')
    expect(wrapper.get('[data-testid="admin-setup-tab-extraction"]').text()).toContain('Extraction')
    expect(wrapper.find('[data-testid="admin-setup-tab-web-search"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="admin-setup-tab-rerank"]').exists()).toBe(false)
  })
})
