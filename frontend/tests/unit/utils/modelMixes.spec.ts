import { describe, it, expect } from 'vitest'
import type { AIModel, Capability } from '@/types/ai-models'
import { isModelMixId, resolveModelMix, resolveModelMixes, MODEL_MIXES } from '@/utils/modelMixes'

// The resolver turns provider-family presets into concrete model BIDs for
// THIS installation. Everything user-visible hangs off it: the subtitle under
// each mix name, the grayed-out state of a mix the server cannot serve, and
// the exact defaults written when a mix is applied. BIDs are install-local,
// so all matching goes through service + providerId.

const model = (
  overrides: Partial<AIModel> & Pick<AIModel, 'id' | 'service' | 'name'>
): AIModel => ({
  tag: 'chat',
  providerId: '',
  quality: 1,
  rating: 1,
  priceIn: 0,
  priceOut: 0,
  description: null,
  isSystemModel: false,
  features: [],
  ...overrides,
})

const mixDefinition = (id: 'openai' | 'anthropic' | 'google' | 'xai' | 'europe' | 'default') => {
  const definition = MODEL_MIXES.find((mix) => mix.id === id)
  if (!definition) throw new Error(`mix ${id} missing`)
  return definition
}

describe('resolveModelMix', () => {
  it('picks the first candidate the installation serves', () => {
    const models: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({ id: 251, service: 'OpenAI', providerId: 'gpt-5.6-sol', name: 'GPT-5.6 Sol' }),
        model({ id: 204, service: 'OpenAI', providerId: 'gpt-5.5', name: 'GPT-5.5' }),
      ],
    }

    const resolved = resolveModelMix(mixDefinition('openai'), models)

    expect(resolved.defaults.CHAT).toBe(251)
    expect(resolved.available).toBe(true)
  })

  it('falls back to a later candidate when the first is not offered', () => {
    const models: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [model({ id: 204, service: 'OpenAI', providerId: 'gpt-5.5', name: 'GPT-5.5' })],
    }

    const resolved = resolveModelMix(mixDefinition('openai'), models)

    expect(resolved.defaults.CHAT).toBe(204)
  })

  it('never resolves a model the backend marked unavailable', () => {
    const models: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({
          id: 251,
          service: 'OpenAI',
          providerId: 'gpt-5.6-sol',
          name: 'GPT-5.6 Sol',
          available: false,
        }),
        model({ id: 204, service: 'OpenAI', providerId: 'gpt-5.5', name: 'GPT-5.5' }),
      ],
    }

    const resolved = resolveModelMix(mixDefinition('openai'), models)

    expect(resolved.defaults.CHAT).toBe(204)
  })

  it('leaves capabilities the provider family cannot cover unset', () => {
    // Anthropic has no image/video/sound models — the mix must not write
    // those slots, so the user keeps whatever was configured before.
    const models: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({
          id: 240,
          service: 'Anthropic',
          providerId: 'claude-fable-5',
          name: 'Claude Fable 5',
        }),
      ],
      TEXT2PIC: [
        model({
          id: 190,
          service: 'Google',
          providerId: 'gemini-3.1-flash-image-preview',
          name: 'Nano Banana 2',
        }),
      ],
    }

    const resolved = resolveModelMix(mixDefinition('anthropic'), models)

    expect(resolved.defaults.CHAT).toBe(240)
    expect(resolved.defaults.TEXT2PIC).toBeUndefined()
  })

  it('marks a mix without a resolvable chat model as unavailable', () => {
    const resolved = resolveModelMix(mixDefinition('europe'), {
      CHAT: [model({ id: 251, service: 'OpenAI', providerId: 'gpt-5.6-sol', name: 'GPT-5.6 Sol' })],
    })

    expect(resolved.available).toBe(false)
    expect(resolved.defaults.CHAT).toBeUndefined()
  })

  it('keeps the default mix available with no defaults of its own', () => {
    const resolved = resolveModelMix(mixDefinition('default'), {})

    expect(resolved.available).toBe(true)
    expect(resolved.resetsToRecommended).toBe(true)
    expect(resolved.defaults).toEqual({})
  })

  it('folds vision twins into the base model name in the subtitle', () => {
    const models: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({
          id: 240,
          service: 'Anthropic',
          providerId: 'claude-fable-5',
          name: 'Claude Fable 5',
        }),
      ],
      PIC2TEXT: [
        model({
          id: 241,
          service: 'Anthropic',
          providerId: 'claude-fable-5',
          name: 'Claude Fable 5 (Vision)',
          tag: 'pic2text',
        }),
      ],
    }

    const resolved = resolveModelMix(mixDefinition('anthropic'), models)

    expect(resolved.modelNames).toEqual(['Claude Fable 5'])
  })

  it('moves sorting to a local gpt-oss only when Ollama actually serves it', () => {
    // The routing prompts are tuned for gpt-oss-120b; the Europe mix may only
    // repoint SORT when the same weights are pulled locally.
    const withOllama: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({ id: 79, service: 'Ollama', providerId: 'gpt-oss:120b', name: 'gpt-oss:120b' }),
      ],
      SORT: [
        model({ id: 79, service: 'Ollama', providerId: 'gpt-oss:120b', name: 'gpt-oss:120b' }),
      ],
    }
    const withoutOllama: Partial<Record<Capability, AIModel[]>> = {
      CHAT: [
        model({
          id: 245,
          service: 'Mistral',
          providerId: 'mistral-large-latest',
          name: 'Mistral Large 3',
        }),
      ],
      SORT: [
        model({
          id: 245,
          service: 'Mistral',
          providerId: 'mistral-large-latest',
          name: 'Mistral Large 3',
        }),
      ],
    }

    expect(resolveModelMix(mixDefinition('europe'), withOllama).defaults.SORT).toBe(79)
    expect(resolveModelMix(mixDefinition('europe'), withoutOllama).defaults.SORT).toBeUndefined()
  })
})

describe('resolveModelMixes', () => {
  it('returns one entry per defined mix, in definition order', () => {
    const resolved = resolveModelMixes({})

    expect(resolved.map((mix) => mix.id)).toEqual([
      'default',
      'openai',
      'anthropic',
      'google',
      'xai',
      'europe',
    ])
  })
})

describe('isModelMixId', () => {
  it('accepts every defined mix id and nothing else', () => {
    for (const mix of MODEL_MIXES) {
      expect(isModelMixId(mix.id)).toBe(true)
    }
    expect(isModelMixId('groq')).toBe(false)
    expect(isModelMixId(null)).toBe(false)
    expect(isModelMixId(42)).toBe(false)
  })
})
