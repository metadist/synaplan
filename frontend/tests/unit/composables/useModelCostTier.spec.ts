import { describe, expect, it } from 'vitest'
import { getCostTier } from '@/composables/useModelCostTier'
import type { AIModel } from '@/types/ai-models'

function model(priceIn: number, priceOut: number, priceKnown?: boolean): AIModel {
  return {
    id: 1,
    service: 'OpenAICompatible',
    name: 'Test',
    tag: 'CHAT',
    providerId: 'test',
    quality: 1,
    rating: 1,
    priceIn,
    priceOut,
    description: null,
    isSystemModel: false,
    features: [],
    priceKnown,
  }
}

describe('getCostTier', () => {
  const peers = [model(2, 2), model(10, 10)]

  it('labels a published zero as free', () => {
    expect(getCostTier(model(0, 0, true), peers).tier).toBe('free')
  })

  it('labels a missing published price as unknown, not free', () => {
    expect(getCostTier(model(0, 0, false), peers).tier).toBe('unknown')
  })

  it('keeps a positive price on a cost tier', () => {
    expect(getCostTier(model(0.15, 0.6, true), peers).tier).not.toBe('free')
    expect(getCostTier(model(0.15, 0.6, true), peers).tier).not.toBe('unknown')
  })

  it('treats a missing flag as a known price, so Ollama stays free', () => {
    expect(getCostTier(model(0, 0), peers).tier).toBe('free')
  })
})
