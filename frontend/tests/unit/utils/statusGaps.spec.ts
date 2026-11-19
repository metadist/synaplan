import { describe, expect, it } from 'vitest'
import type { Feature, ServiceReadiness } from '@/services/featuresService'
import { statusGaps } from '@/utils/statusGaps'

function feature(partial: Partial<Feature> & Pick<Feature, 'id' | 'status'>): Feature {
  return {
    category: 'AI Providers',
    name: partial.id,
    enabled: partial.status !== 'disabled',
    message: '',
    setup_required: partial.status === 'disabled',
    env_vars: undefined,
    ...partial,
  }
}

function service(id: string, state: ServiceReadiness['state']): ServiceReadiness {
  return {
    id: id as ServiceReadiness['id'],
    name: id,
    state,
    unlocks: '',
    effect: null,
    recovery: null,
    since: null,
    percent: null,
    model: null,
    minutes_left: null,
    profile: null,
    docs_anchor: null,
  }
}

describe('statusGaps', () => {
  it('names a failed check as critical and a switched-off provider as optional', () => {
    const gaps = statusGaps(
      {
        database: feature({
          id: 'database',
          name: 'MariaDB',
          status: 'unhealthy',
          category: 'Infrastructure',
        }),
        anthropic: feature({ id: 'anthropic', name: 'Anthropic', status: 'disabled' }),
        openai: feature({ id: 'openai', name: 'OpenAI', status: 'active' }),
      },
      []
    )

    expect(gaps.map((gap) => [gap.name, gap.kind, gap.anchor])).toEqual([
      ['MariaDB', 'critical', 'feature-database'],
      ['Anthropic', 'optional', 'feature-anthropic'],
    ])
  })

  it('does not repeat a feature the service list already explains', () => {
    const gaps = statusGaps(
      {
        'memory-service': feature({
          id: 'memory-service',
          name: 'Qdrant Vector Database',
          status: 'unhealthy',
          category: 'Processing Services',
        }),
      },
      [service('qdrant', 'unavailable')]
    )

    expect(gaps.map((gap) => gap.anchor)).toEqual(['service-qdrant'])
    expect(gaps[0]?.kind).toBe('critical')
  })

  it('lists a switched-off service as optional and skips its feature row', () => {
    const gaps = statusGaps(
      {
        ollama: feature({
          id: 'ollama',
          name: 'Ollama',
          status: 'disabled',
          category: 'AI Providers',
        }),
      },
      [service('ollama', 'off')]
    )

    expect(gaps.map((gap) => [gap.kind, gap.anchor])).toEqual([['optional', 'service-ollama']])
  })
})
