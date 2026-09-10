import { describe, expect, it } from 'vitest'
import { rerankSaveFeedback } from '@/components/admin/plugs/rerankSaveFeedback'

describe('rerankSaveFeedback', () => {
  it('reports off when reranking is disabled', () => {
    expect(
      rerankSaveFeedback({
        enabled: false,
        modelKey: 'cohere:rerank-v3.5:rerank',
        adapters: [
          { key: 'http', health: { available: true } },
          { key: 'llm', health: { available: true } },
        ],
      })
    ).toBe('off')
  })

  it('reports inactive when a bound model has an unusable HTTP adapter', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        modelKey: 'cohere:rerank-v3.5:rerank',
        llmFallback: true,
        adapters: [
          { key: 'http', health: { available: false } },
          { key: 'llm', health: { available: true } },
        ],
      })
    ).toBe('inactive')
  })

  it('reports active when the bound HTTP adapter is available', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        modelKey: 'jina:jina-reranker-v2-base-multilingual:rerank',
        adapters: [
          { key: 'http', health: { available: true } },
          { key: 'llm', health: { available: false } },
        ],
      })
    ).toBe('active')
  })

  it('reports active for LLM fallback only when no model is bound', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        modelKey: null,
        llmFallback: true,
        adapters: [
          { key: 'http', health: { available: false } },
          { key: 'llm', health: { available: true } },
        ],
      })
    ).toBe('active')
  })

  it('reports inactive when enabled with neither a usable bound model nor LLM fallback', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        modelKey: null,
        llmFallback: false,
        adapters: [
          { key: 'http', health: { available: true } },
          { key: 'llm', health: { available: true } },
        ],
      })
    ).toBe('inactive')
  })
})
