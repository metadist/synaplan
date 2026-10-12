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

  it('badges image edits from the billed high price', () => {
    const rows: AIModel[] = [
      image('Flare', 0.01317, 0.05268),
      image('Sunburst', 0.01317, 0.05268),
      image('Lite', 0.0336, 0.0336),
      image('gpt-image-1.5', 0.034, 0.133),
      image('Flash', 0.039, 0.039),
      image('Banana 2', 0.067, 0.067),
      image('Pro', 0.134, 0.134),
    ]
    const tier = (name: string) =>
      getCostTier(
        rows.find((row) => row.name === name)!,
        rows,
        'PIC2PIC'
      ).tier

    expect(tier('Flare')).toBe('mid')
    expect(tier('Sunburst')).toBe('mid')
    expect(tier('Lite')).toBe('mid')
    expect(tier('Flash')).toBe('mid')
    expect(tier('Banana 2')).toBe('mid')
    expect(tier('gpt-image-1.5')).toBe('high')
    expect(tier('Pro')).toBe('high')
  })

  it('uses the text-to-image price when that capability is passed', () => {
    const rows = [image('Flare', 0.01317, 0.05268), image('Pro', 0.134, 0.134)]

    expect(getCostTier(rows[0]!, rows, 'TEXT2PIC').tier).toBe('low')
    expect(getCostTier(rows[1]!, rows, 'TEXT2PIC').tier).toBe('mid')
  })

  it('falls back to the average when an image row has no capability price', () => {
    const priced = image('Flare', 0.01317, 0.05268)
    const bare = model(0, 0.2)
    const peers = [priced, bare]

    expect(getCostTier(bare, peers, 'PIC2PIC').tier).toBe('mid')
    expect(getCostTier(bare, peers, 'PIC2PIC').tier).toBe(getCostTier(bare, peers).tier)
  })
})

function image(name: string, textPrice: number, editPrice: number): AIModel {
  return {
    id: name.length,
    service: 'OpenAI',
    name,
    tag: 'TEXT2PIC',
    providerId: name,
    quality: 1,
    rating: 1,
    priceIn: 0,
    priceOut: textPrice,
    description: null,
    isSystemModel: false,
    features: ['pic2pic'],
    capabilityPrices: { TEXT2PIC: textPrice, PIC2PIC: editPrice },
  }
}
