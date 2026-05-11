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
    expect(dedupe[0].id).toBe(1)
    expect(dedupe[0].purposes).toEqual(['SORT', 'CHAT', 'ANALYZE'])
  })

  it('keeps purposes in the canonical PURPOSE_ORDER, not API arrival order', () => {
    const haiku = model(1, 'Claude Haiku 4.5')

    const dedupe = dedupeModelsByPurpose(
      {
        ANALYZE: [haiku],
        CHAT: [haiku],
        SORT: [haiku],
      },
      PURPOSE_ORDER
    )

    expect(dedupe[0].purposes).toEqual(['SORT', 'CHAT', 'ANALYZE'])
  })

  it('returns one row per distinct model when multiple models share purposes', () => {
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
    expect(dedupe.map((m) => m.id)).toEqual([1, 2, 3])
    for (const row of dedupe) {
      expect(row.purposes).toEqual(['SORT', 'CHAT', 'ANALYZE'])
    }
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

    expect(dedupe[0].purposes).toEqual(['SORT'])
  })

  it('preserves model metadata (name, service, quality) on the dedup row', () => {
    const flux = model(99, 'Flux Schnell', 'TheHive')
    flux.quality = 9.5
    flux.description = 'Fast image gen'

    const dedupe = dedupeModelsByPurpose({ TEXT2PIC: [flux] }, PURPOSE_ORDER)

    expect(dedupe[0]).toMatchObject({
      id: 99,
      name: 'Flux Schnell',
      service: 'TheHive',
      quality: 9.5,
      description: 'Fast image gen',
      purposes: ['TEXT2PIC'],
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
    expect(dedupe[0].purposes).toEqual(['SORT'])
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
    expect((haiku as AIModel & { purposes?: Capability[] }).purposes).toBeUndefined()
  })
})
