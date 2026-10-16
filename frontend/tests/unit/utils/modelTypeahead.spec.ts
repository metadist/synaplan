import { describe, expect, it } from 'vitest'
import { nextTypeaheadTarget } from '@/utils/modelTypeahead'

const labels = ['Default', 'Claude Haiku', 'Claude Opus', 'Claude Sonnet', 'GPT-5.4']

describe('nextTypeaheadTarget', () => {
  it('jumps to the first name that starts with the typed letters', () => {
    const first = nextTypeaheadTarget(labels, '', 'c', 0)
    const second = nextTypeaheadTarget(labels, first.query, 'h', first.index)
    const third = nextTypeaheadTarget(labels, second.query, 'a', second.index)

    expect({ first, second, third }).toEqual({
      first: { query: 'c', index: 1 },
      second: { query: 'ch', index: 1 },
      third: { query: 'cha', index: 1 },
    })
  })

  it('matches a later word when nothing starts with the query', () => {
    expect(nextTypeaheadTarget(labels, '', 'o', 0).index).toBe(2)
    expect(nextTypeaheadTarget(labels, 'op', 'u', 2)).toEqual({ query: 'opu', index: 2 })
  })

  it('moves to the next name when the same letter is typed again', () => {
    const first = nextTypeaheadTarget(labels, '', 'c', 0)
    const second = nextTypeaheadTarget(labels, first.query, 'c', first.index)

    expect(first.index).toBe(1)
    expect(second).toEqual({ query: 'c', index: 2 })
  })

  it('keeps the previous letters when the next character matches nothing', () => {
    expect(nextTypeaheadTarget(labels, 'cha', 'z', 1)).toEqual({ query: 'cha', index: -1 })
  })

  it('does not treat a single letter as a match anywhere in the name', () => {
    expect(nextTypeaheadTarget(labels, '', 'p', 0).index).toBe(-1)
  })
})
