import { describe, expect, it } from 'vitest'
import { researchStepCopy } from '@/components/research/researchStepCopy'

const t = (key: string, params?: Record<string, unknown>): string =>
  params ? `${key}:${JSON.stringify(params)}` : key

describe('researchStepCopy', () => {
  it('tells what the fact-check left out and who checked', () => {
    const copy = researchStepCopy(
      t,
      {
        kind: 'check',
        text: '35/49',
        status: null,
        ref: null,
        check: { removed: 14, sameProvider: true },
      },
      []
    )

    expect(copy.title).toBe('research.step.check:{"ratio":"35/49"}')
    expect(copy.detail).toBe(
      'research.step.checkRemoved:{"count":14} research.step.checkSameProvider'
    )
  })

  it('names the decision a round ended with', () => {
    const again = researchStepCopy(
      t,
      { kind: 'round', text: '2/4', status: 'round', ref: null },
      []
    )
    const write = researchStepCopy(
      t,
      { kind: 'round', text: '3/4', status: 'write', ref: null },
      []
    )

    expect(again.title).toBe('research.step.roundAgain:{"round":"2/4"}')
    expect(write.title).toBe('research.step.roundWrite:{"round":"3/4"}')
  })

  it('quotes how many results a search returned and why a source could not be read', () => {
    const search = researchStepCopy(
      t,
      { kind: 'search', text: 'japan visa', status: 'web', ref: null, count: 8 },
      []
    )
    const blocked = researchStepCopy(
      t,
      { kind: 'source', text: 'Old forum', status: 'blocked', ref: 's2', note: 'No text.' },
      []
    )
    const skipped = researchStepCopy(
      t,
      { kind: 'search', text: 'japan visa fees', status: 'skipped', ref: null },
      []
    )

    expect(search.detail).toBe('japan visa · research.step.searchResults:{"count":8}')
    expect(blocked.detail).toBe('Old forum · No text.')
    expect(skipped.title).toBe('research.step.searchSkipped')
  })

  it('says nothing more when every statement stayed', () => {
    const copy = researchStepCopy(
      t,
      {
        kind: 'check',
        text: '5/5',
        status: null,
        ref: null,
        check: { removed: 0, sameProvider: false },
      },
      []
    )

    expect(copy.detail).toBe('')
  })
})
