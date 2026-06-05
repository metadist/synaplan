import { describe, it, expect } from 'vitest'
import { dedupeModelsByPurpose } from '@/utils/aiModelDedupe'
import type { AIModel, Capability } from '@/types/ai-models'

const PURPOSE_ORDER: readonly Capability[] = [
  'SORT',
  'CHAT',
  'MEM',
  'ANALYZE',
  'VECTORIZE',
  'PIC2TEXT',
  'TEXT2PIC',
  'PIC2PIC',
  'TEXT2VID',
  'SOUND2TEXT',
  'TEXT2SOUND',
]

function model(id: number, name: string, service = 'Anthropic'): AIModel {
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

describe('dedupeModelsByPurpose (issue #261)', () => {
  it('collapses a model that appears under several purposes into a single row', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    const dedupe = dedupeModelsByPurpose(
      {
        SORT: [haiku],
        CHAT: [haiku],
        ANALYZE: [haiku],
      },
      PURPOSE_ORDER
    )

    expect(dedupe).toHaveLength(1)
    expect(dedupe[0].purposes).toEqual([
      { purpose: 'SORT', modelId: 1 },
      { purpose: 'CHAT', modelId: 1 },
      { purpose: 'ANALYZE', modelId: 1 },
    ])
  })

  it('keeps chips in the canonical PURPOSE_ORDER, not API arrival order', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    const dedupe = dedupeModelsByPurpose(
      {
        ANALYZE: [haiku],
        CHAT: [haiku],
        SORT: [haiku],
      },
      PURPOSE_ORDER
    )

    expect(dedupe[0].purposes.map((c) => c.purpose)).toEqual(['SORT', 'CHAT', 'ANALYZE'])
  })

  it('returns one row per distinct (service, name) when multiple models share purposes', () => {
    const haiku = model(1, 'Claude Haiku 4.5')
    const opus = model(2, 'Claude Opus 4.6')
    const sonnet = model(3, 'Claude Sonnet 4.6')

    const dedupe = dedupeModelsByPurpose(
      {
        SORT: [haiku, opus, sonnet],
        CHAT: [haiku, opus, sonnet],
        ANALYZE: [haiku, opus, sonnet],
      },
      PURPOSE_ORDER
    )

    expect(dedupe).toHaveLength(3)
    expect(dedupe.map((m) => m.name)).toEqual([
      'Claude Haiku 4.5',
      'Claude Opus 4.6',
      'Claude Sonnet 4.6',
    ])
    for (const row of dedupe) {
      expect(row.purposes.map((c) => c.purpose)).toEqual(['SORT', 'CHAT', 'ANALYZE'])
    }
  })

  it('merges (service, name) duplicates that have different backend ids, routing each chip to its own id', () => {
    // Real-world scenario from the BMODELS catalogue: "Claude Opus 4.6"
    // exists as two rows — one for general chat (BTAG=chat) and one
    // dedicated to memory extraction (BTAG=mem). They share name +
    // service but have distinct ids, and selecting CHAT vs MEM must
    // persist the correct id.
    const opusChat = model(160, 'Claude Opus 4.6')
    const opusMem = model(222, 'Claude Opus 4.6')

    const dedupe = dedupeModelsByPurpose(
      {
        SORT: [opusChat],
        CHAT: [opusChat],
        MEM: [opusMem],
        ANALYZE: [opusChat],
      },
      PURPOSE_ORDER
    )

    expect(dedupe).toHaveLength(1)
    expect(dedupe[0].purposes).toEqual([
      { purpose: 'SORT', modelId: 160 },
      { purpose: 'CHAT', modelId: 160 },
      { purpose: 'MEM', modelId: 222 },
      { purpose: 'ANALYZE', modelId: 160 },
    ])
  })

  it('treats same name across different services as different models', () => {
    // gpt-oss-120b is registered under both Groq and Ollama. Despite
    // the identical model name, these are different runtimes and must
    // not collapse into one row.
    const groq = model(76, 'gpt-oss-120b', 'Groq')
    const ollama = model(79, 'gpt-oss-120b', 'Ollama')

    const dedupe = dedupeModelsByPurpose(
      {
        CHAT: [groq, ollama],
      },
      PURPOSE_ORDER
    )

    expect(dedupe).toHaveLength(2)
    expect(dedupe.map((m) => `${m.service}/${m.name}`)).toEqual([
      'Groq/gpt-oss-120b',
      'Ollama/gpt-oss-120b',
    ])
  })

  it('does not list the same purpose twice for one model', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    const dedupe = dedupeModelsByPurpose(
      {
        // Pathological backend response: same purpose holds the same model
        // twice. Should never happen in production, but the dedupe must
        // not let it bubble through as duplicate chips.
        SORT: [haiku, haiku],
      },
      PURPOSE_ORDER
    )

    expect(dedupe[0].purposes).toEqual([{ purpose: 'SORT', modelId: 1 }])
  })

  it('preserves model metadata (name, service, quality) on the dedup row', () => {
    const flux = model(99, 'Flux Schnell', 'TheHive')
    flux.quality = 9.5
    flux.description = 'Fast image gen'

    const dedupe = dedupeModelsByPurpose({ TEXT2PIC: [flux] }, PURPOSE_ORDER)

    expect(dedupe[0]).toMatchObject({
      name: 'Flux Schnell',
      service: 'TheHive',
      quality: 9.5,
      description: 'Fast image gen',
      purposes: [{ purpose: 'TEXT2PIC', modelId: 99 }],
    })
  })

  it('returns an empty list for an empty input', () => {
    expect(dedupeModelsByPurpose({}, PURPOSE_ORDER)).toEqual([])
  })

  it('skips purposes that are not in the supplied order', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    // Caller only cares about SORT — CHAT entries must be dropped, NOT
    // accidentally added to the haiku row.
    const dedupe = dedupeModelsByPurpose(
      {
        SORT: [haiku],
        CHAT: [haiku],
      },
      ['SORT']
    )

    expect(dedupe).toHaveLength(1)
    expect(dedupe[0].purposes).toEqual([{ purpose: 'SORT', modelId: 1 }])
  })

  it('does not mutate the model objects from the input map', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    dedupeModelsByPurpose(
      {
        SORT: [haiku],
        CHAT: [haiku],
      },
      PURPOSE_ORDER
    )

    // The shared input object must NOT have a `purposes` field grafted
    // on, otherwise re-rendering would compound the list across renders.
    expect((haiku as AIModel & { purposes?: unknown }).purposes).toBeUndefined()
  })
})
