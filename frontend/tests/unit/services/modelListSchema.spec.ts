import { describe, expect, it } from 'vitest'
import { GetApiConfigModelsListResponseSchema } from '@/generated/api-schemas'

const row = {
  id: 1,
  service: 'Groq',
  name: 'Llama',
  providerId: 'llama',
  quality: 8,
  rating: 1,
  tag: 'chat',
  isSystemModel: false,
  features: [],
  available: true,
  priceIn: 0,
  priceOut: 0,
  priceKnown: true,
}

describe('model list schema', () => {
  it('accepts a model row without an endpoint', () => {
    const result = GetApiConfigModelsListResponseSchema.safeParse({ models: { CHAT: [row] } })
    expect(result.success).toBe(true)
  })

  it('keeps the endpoint of an OpenAI-compatible model', () => {
    const parsed = GetApiConfigModelsListResponseSchema.parse({
      models: { CHAT: [{ ...row, id: 400, endpoint: 'synaplan-demo' }] },
    })
    expect(parsed.models?.CHAT?.[0]?.endpoint).toBe('synaplan-demo')
  })
})
