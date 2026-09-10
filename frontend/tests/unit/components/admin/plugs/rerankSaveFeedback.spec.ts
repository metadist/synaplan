import { describe, expect, it } from 'vitest'
import { rerankSaveFeedback } from '@/components/admin/plugs/rerankSaveFeedback'

describe('rerankSaveFeedback', () => {
  it('reports off when reranking is disabled', () => {
    expect(
      rerankSaveFeedback({
        enabled: false,
        adapters: [{ health: { available: true } }],
      })
    ).toBe('off')
  })

  it('reports inactive when enabled but no adapter is available', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        adapters: [{ health: { available: false } }, { health: { available: false } }],
      })
    ).toBe('inactive')
  })

  it('reports active only when enabled and an adapter is available', () => {
    expect(
      rerankSaveFeedback({
        enabled: true,
        adapters: [{ health: { available: false } }, { health: { available: true } }],
      })
    ).toBe('active')
  })
})
