import { describe, it, expect } from 'vitest'
import { findModelIdByString, resolveModelIdForSave } from '@/utils/aiModelDefaults'
import type { AIModel, Capability } from '@/types/ai-models'

function model(id: number, name: string, service: string): AIModel {
  return {
    id,
    name,
    service,
    tag: name.toLowerCase(),
    providerId: `${service.toLowerCase()}/${name.toLowerCase()}`,
    quality: 8,
    rating: 4,
    priceIn: 0,
    priceOut: 0,
    description: null,
    isSystemModel: false,
    features: [],
  }
}

const models: Partial<Record<Capability, AIModel[]>> = {
  CHAT: [model(9, 'gpt-oss-120b', 'Groq')],
}

describe('resolveModelIdForSave', () => {
  it('resolves a model string that is present in the list', () => {
    expect(resolveModelIdForSave(models, 'gpt-oss-120b (Groq)', 249)).toBe(9)
  })

  /**
   * /config/models hides models whose provider is unavailable, so a pinned
   * model can be absent from the picker. Saving must not clear the pin.
   */
  it('keeps the stored id when the model string cannot be resolved', () => {
    expect(findModelIdByString(models, 'Claude Sonnet 5 (Anthropic)')).toBe(-1)
    expect(resolveModelIdForSave(models, 'Claude Sonnet 5 (Anthropic)', 249)).toBe(249)
  })

  it('falls back to the unresolved sentinel when nothing was pinned', () => {
    expect(resolveModelIdForSave(models, 'Claude Sonnet 5 (Anthropic)', 0)).toBe(-1)
    expect(resolveModelIdForSave(models, 'Claude Sonnet 5 (Anthropic)', undefined)).toBe(-1)
  })
})
