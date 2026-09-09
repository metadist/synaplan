import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModelImportDialog from '@/components/admin/plugs/ModelImportDialog.vue'

const importEndpointPreview = vi.fn()
const importEndpointApply = vi.fn()

vi.mock('@/services/api/adminModelsApi', () => ({
  adminModelsApi: {
    importEndpointPreview: (...args: unknown[]) => importEndpointPreview(...args),
    importEndpointApply: (...args: unknown[]) => importEndpointApply(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

function previewResponse() {
  return {
    source: 'openai_compatible:vllm',
    endpointOk: true,
    error: null,
    probeCostNote: null,
    rows: [
      {
        providerId: 'Qwen/Qwen3-32B',
        name: 'Qwen3 32B',
        guessedTags: ['chat'],
        exists: false,
        sizeBytes: null,
        family: null,
        probe: null,
      },
      {
        providerId: 'BAAI/bge-m3',
        name: 'bge m3',
        guessedTags: ['vectorize'],
        exists: true,
        sizeBytes: null,
        family: null,
        probe: null,
      },
    ],
  }
}

function mountDialog() {
  return mount(ModelImportDialog, {
    props: { source: 'openai_compatible:vllm', label: 'vLLM lab' },
    global: { stubs: { Icon: true } },
  })
}

describe('ModelImportDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    importEndpointPreview.mockReset()
    importEndpointApply.mockReset()
    importEndpointPreview.mockResolvedValue(previewResponse())
  })

  it('previews without probing by default and preselects only new models', async () => {
    const wrapper = mountDialog()
    await flushPromises()

    expect(importEndpointPreview).toHaveBeenCalledWith('openai_compatible:vllm', false)
    const probe = wrapper.get('[data-testid="model-import-probe"]').element as HTMLInputElement
    expect(probe.checked).toBe(false)

    const newRow = wrapper.get('[data-testid="model-import-select-Qwen/Qwen3-32B"]')
      .element as HTMLInputElement
    const existingRow = wrapper.get('[data-testid="model-import-select-BAAI/bge-m3"]')
      .element as HTMLInputElement
    expect(newRow.checked).toBe(true)
    expect(existingRow.checked).toBe(false)
  })

  it('re-previews with probing when the probe box is toggled', async () => {
    const wrapper = mountDialog()
    await flushPromises()

    await wrapper.get('[data-testid="model-import-probe"]').setValue(true)
    await flushPromises()

    expect(importEndpointPreview).toHaveBeenLastCalledWith('openai_compatible:vllm', true)
  })

  it('applies the edited tags for the selected rows only', async () => {
    importEndpointApply.mockResolvedValue({ created: 2, skipped: 0, rows: [] })
    const wrapper = mountDialog()
    await flushPromises()

    // Edit the new row's tags; an unknown tag must be dropped by the parser.
    await wrapper.get('[data-testid="model-import-tags-Qwen/Qwen3-32B"]').setValue('chat, pic2text, bogus')
    await wrapper.get('[data-testid="model-import-apply"]').trigger('click')
    await flushPromises()

    expect(importEndpointApply).toHaveBeenCalledWith('openai_compatible:vllm', [
      { providerId: 'Qwen/Qwen3-32B', name: 'Qwen3 32B', tags: ['chat', 'pic2text'] },
    ])
    expect(wrapper.emitted('applied')).toBeTruthy()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows an unreachable state when the endpoint did not answer', async () => {
    importEndpointPreview.mockResolvedValue({
      source: 'openai_compatible:vllm',
      endpointOk: false,
      error: 'connection refused',
      probeCostNote: null,
      rows: [],
    })
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.find('[data-testid="model-import-unreachable"]').exists()).toBe(true)
  })
})
