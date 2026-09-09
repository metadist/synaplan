import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import RerankPlugTab from '@/components/admin/plugs/RerankPlugTab.vue'

const getRerankStatus = vi.fn()
const saveRerank = vi.fn()
const testRerank = vi.fn()
const savePlugKey = vi.fn()

vi.mock('@/services/api/adminPlugsApi', () => ({
  getRerankStatus: (...args: unknown[]) => getRerankStatus(...args),
  saveRerank: (...args: unknown[]) => saveRerank(...args),
  testRerank: (...args: unknown[]) => testRerank(...args),
  savePlugKey: (...args: unknown[]) => savePlugKey(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

const keyStatus = {
  configured: false,
  source: 'none',
  origin: null,
  maskedKey: '',
}

describe('RerankPlugTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getRerankStatus.mockResolvedValue({
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
    })
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
})
