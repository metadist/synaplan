import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const mockListProviderKeys = vi.fn()
const mockImportEndpointPreview = vi.fn()

vi.mock('@/services/api/providerKeysApi', () => ({
  listProviderKeys: (...args: unknown[]) => mockListProviderKeys(...args),
}))

vi.mock('@/services/api/adminModelsApi', () => ({
  adminModelsApi: {
    importEndpointPreview: (...args: unknown[]) => mockImportEndpointPreview(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import ModelsAndKeysTab from '@/components/admin/plugs/ModelsAndKeysTab.vue'

function mountTab() {
  return mount(ModelsAndKeysTab, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        ProviderHelpHint: { template: '<span />' },
        ProviderKeyCard: { template: '<div />' },
        ModelImportDialog: { template: '<div />' },
        LocalAiDownloadCard: { template: '<div />' },
      },
    },
  })
}

describe('ModelsAndKeysTab local AI', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockListProviderKeys.mockResolvedValue({ providers: [], defaultChatProvider: '' })
    mockImportEndpointPreview.mockResolvedValue({ endpointOk: true, models: [] })
  })

  it('keeps the Ollama import live when a server answers', async () => {
    const wrapper = mountTab()
    await flushPromises()

    expect(mockImportEndpointPreview).toHaveBeenCalledWith('ollama', false)
    const button = wrapper.get('[data-testid="ollama-import-models"]')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid="ollama-not-running"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('disables the import and names the recovery when Ollama is not running', async () => {
    mockImportEndpointPreview.mockResolvedValue({
      endpointOk: false,
      error: 'Ollama server is unreachable',
      models: [],
    })
    const wrapper = mountTab()
    await flushPromises()

    expect(wrapper.get('[data-testid="ollama-import-models"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="ollama-not-running"]').text()).toContain(
      'COMPOSE_PROFILES=local-ai'
    )

    // Starting Ollama later re-enables the import without a page reload.
    mockImportEndpointPreview.mockResolvedValue({ endpointOk: true, models: [] })
    await wrapper.get('[data-testid="ollama-recheck"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="ollama-not-running"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-testid="ollama-import-models"]').attributes('disabled')
    ).toBeUndefined()
    wrapper.unmount()
  })

  it('fails open when the pre-flight itself errors', async () => {
    mockImportEndpointPreview.mockRejectedValue(new Error('network down'))
    const wrapper = mountTab()
    await flushPromises()

    // The dialog reports reachability with its own Retry; the tab stays usable.
    expect(
      wrapper.get('[data-testid="ollama-import-models"]').attributes('disabled')
    ).toBeUndefined()
    expect(wrapper.find('[data-testid="ollama-not-running"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
