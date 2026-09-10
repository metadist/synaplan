import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RerankPlugTab from '@/components/admin/plugs/RerankPlugTab.vue'

const getRerankStatus = vi.fn()
const saveRerank = vi.fn()
const testRerank = vi.fn()
const savePlugKey = vi.fn()
const success = vi.fn()
const info = vi.fn()
const warning = vi.fn()

vi.mock('@/services/api/adminPlugsApi', () => ({
  getRerankStatus: (...args: unknown[]) => getRerankStatus(...args),
  saveRerank: (...args: unknown[]) => saveRerank(...args),
  testRerank: (...args: unknown[]) => testRerank(...args),
  savePlugKey: (...args: unknown[]) => savePlugKey(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success, info, warning }),
}))

const keyStatus = {
  configured: false,
  source: 'none',
  origin: null,
  maskedKey: '',
}

const inactiveStatus = {
  enabled: false,
  modelKey: null,
  multiplier: 4,
  budgetMs: 800,
  llmFallback: false,
  adapters: [
    { key: 'http', label: 'HTTP rerank', health: { available: false, reason: 'no model' } },
    { key: 'llm', label: 'Chat model', health: { available: false, reason: 'off' } },
  ],
  models: [
    {
      key: 'jina:jina-reranker-v2-base-multilingual:rerank',
      label: 'Jina reranker',
      available: false,
      reason: 'no key',
    },
  ],
  keys: { jina: keyStatus, cohere: keyStatus, voyage: keyStatus },
  lastEval: null,
}

describe('RerankPlugTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    success.mockReset()
    info.mockReset()
    warning.mockReset()
    getRerankStatus.mockResolvedValue(inactiveStatus)
  })

  it('shows the lead sentence, health, keys and Test order results', async () => {
    testRerank.mockResolvedValue({
      ordered: [
        { index: 0, score: 0.91 },
        { index: 1, score: 0.12 },
      ],
      provider: 'http:jina',
      ms: 18,
      error: null,
    })

    const wrapper = mount(RerankPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('After search finds many snippets')
    expect(wrapper.get('[data-testid="rerank-health-http"]').text()).toContain('no model')
    expect(wrapper.find('[data-testid="rerank-enabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="rerank-key-jina"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="rerank-test-button"]').text()).toContain('Test order')

    await wrapper.get('[data-testid="rerank-test-query"]').setValue('invoice total')
    await wrapper
      .get('[data-testid="rerank-test-documents"]')
      .setValue('The invoice total is 120 euro.\nOffice hours are 9 to 5.')
    await wrapper.get('[data-testid="rerank-test-button"]').trigger('click')
    await flushPromises()

    expect(testRerank).toHaveBeenCalledWith(
      'invoice total',
      ['The invoice total is 120 euro.', 'Office hours are 9 to 5.'],
      null
    )
    expect(wrapper.get('[data-testid="rerank-test-results"]').text()).toContain('#1')
    expect(wrapper.get('[data-testid="rerank-test-results"]').text()).toContain('0.91')
  })

  it('does not confirm an inactive save as in effect on the next chat', async () => {
    saveRerank.mockResolvedValue(inactiveStatus)

    const wrapper = mount(RerankPlugTab, {
      global: { stubs: { Icon: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="rerank-save"]').trigger('click')
    await flushPromises()

    expect(info).toHaveBeenCalledWith(
      'Reranking settings saved. Document search keeps the original vector order.'
    )
    expect(success).not.toHaveBeenCalled()
    expect(warning).not.toHaveBeenCalled()
  })

  it('warns when settings are stored but no adapter is available', async () => {
    saveRerank.mockResolvedValue({
      ...inactiveStatus,
      enabled: true,
      modelKey: 'jina:jina-reranker-v2-base-multilingual:rerank',
    })

    const wrapper = mount(RerankPlugTab, {
      global: { stubs: { Icon: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="rerank-enabled"]').setValue(true)
    await wrapper.get('[data-testid="rerank-save"]').trigger('click')
    await flushPromises()

    expect(warning).toHaveBeenCalledWith(
      'Reranking settings saved. They stay inactive until a rerank model or the chat-model fallback is available.'
    )
    expect(success).not.toHaveBeenCalled()
  })

  it('confirms activation only when an adapter is available', async () => {
    saveRerank.mockResolvedValue({
      ...inactiveStatus,
      enabled: true,
      modelKey: 'jina:jina-reranker-v2-base-multilingual:rerank',
      adapters: [
        { key: 'http', label: 'HTTP rerank', health: { available: true, reason: null } },
        { key: 'llm', label: 'Chat model', health: { available: false, reason: 'off' } },
      ],
    })

    const wrapper = mount(RerankPlugTab, {
      global: { stubs: { Icon: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="rerank-save"]').trigger('click')
    await flushPromises()

    expect(success).toHaveBeenCalledWith(
      'Reranking settings saved. The next document search uses them.'
    )
    expect(success.mock.calls[0][0]).not.toContain('chat')
  })
})
