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
    expect(blocked.detail).toBe('Old forum')
    expect(blocked.aside).toBe('No text.')
    expect(skipped.title).toBe('research.step.searchSkipped')
  })

  it('shows the model’s own words: why a source was picked and what it gave', () => {
    const picked = researchStepCopy(
      t,
      { kind: 'source', text: 'Embassy', status: 'found', ref: 's1', note: 'Official fee table.' },
      []
    )
    const read = researchStepCopy(
      t,
      { kind: 'source', text: 'Embassy', status: 'read', ref: 's1', count: 2 },
      []
    )
    const empty = researchStepCopy(
      t,
      { kind: 'source', text: 'Blog', status: 'read', ref: 's2', count: 0 },
      []
    )

    expect(picked.aside).toBe('Official fee table.')
    expect(read.detail).toBe('Embassy · research.step.sourceFindings:{"count":2}')
    expect(empty.detail).toBe('Blog · research.step.sourceNothingNew')
  })

  it('reads a thought as the sentence itself and a finding with its quote and source', () => {
    const thought = researchStepCopy(
      t,
      { kind: 'thought', text: 'Two shops agree on the price.', status: null, ref: null },
      []
    )
    const finding = researchStepCopy(
      t,
      {
        kind: 'finding',
        text: 'The fee is 30 EUR.',
        status: 'high',
        ref: 'p1',
        quote: 'Fee: 30 EUR',
        source: 's1',
      },
      [],
      { s1: 'Embassy' }
    )

    expect(thought).toEqual({ title: 'Two shops agree on the price.', detail: '', aside: '' })
    expect(finding.title).toBe('research.step.finding')
    expect(finding.detail).toBe('The fee is 30 EUR.')
    expect(finding.aside).toBe('“Fee: 30 EUR” — Embassy')
  })

  it('puts the fact-check’s verdicts and a round’s reason into words', () => {
    const softened = researchStepCopy(
      t,
      {
        kind: 'verdict',
        text: 'The fee is 30 EUR.',
        status: 'partial',
        ref: null,
        note: 'Only for adults.',
      },
      []
    )
    const removed = researchStepCopy(
      t,
      {
        kind: 'verdict',
        text: 'The fee is 50 EUR.',
        status: 'removed',
        ref: null,
        note: 'uncited',
      },
      []
    )
    const round = researchStepCopy(
      t,
      { kind: 'round', text: '2/4', status: 'write', ref: null, note: 'covered' },
      []
    )
    const lead = researchStepCopy(
      t,
      { kind: 'round', text: '2/4', status: 'write', ref: null, note: 'Both points are answered.' },
      []
    )

    expect(softened.title).toBe('research.step.verdict.partial')
    expect(softened.aside).toBe('Only for adults.')
    expect(removed.aside).toBe('research.step.verdictReason.uncited')
    expect(round.aside).toBe('research.step.roundReason.covered')
    expect(lead.aside).toBe('Both points are answered.')
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
