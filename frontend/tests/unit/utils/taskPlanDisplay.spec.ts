import { describe, it, expect } from 'vitest'
import { markRedundantTaskPlanProse } from '@/utils/taskPlanDisplay'
import type { TaskPlanState } from '@/stores/history'

/**
 * #1229 smart collapse: prose cards whose text is already contained in the
 * final answer body are MARKED redundant (the card collapses to its header);
 * cards with unique content are left untouched. Replaces the old
 * dedupeTaskPlanProse approach of blanking the card text.
 */
const plan = (cards: TaskPlanState['cards']): TaskPlanState => ({
  active: false,
  replyNode: 'n3',
  cards,
})

describe('markRedundantTaskPlanProse', () => {
  it('returns null for a missing plan', () => {
    expect(markRedundantTaskPlanProse(null, ['anything'])).toBeNull()
    expect(markRedundantTaskPlanProse(undefined, ['anything'])).toBeNull()
  })

  it('returns the same plan untouched when the body has no text', () => {
    const p = plan([
      { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: 'Poem' },
    ])
    expect(markRedundantTaskPlanProse(p, [])).toBe(p)
    expect(markRedundantTaskPlanProse(p, ['   '])).toBe(p)
  })

  it('marks a card redundant when its text is duplicated by the body (poem → TTS)', () => {
    const poem = 'Roses are red,\nviolets are blue.'
    const result = markRedundantTaskPlanProse(
      plan([
        { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: poem },
        { nodeId: 'n2', capability: 'text2sound', kind: 'audio', state: 'failed' },
      ]),
      [poem]
    )

    expect(result?.cards[0].redundant).toBe(true)
    // The text itself STAYS (the card collapses; expanding shows it again).
    expect(result?.cards[0].text).toBe(poem)
    expect(result?.cards[1].redundant).toBeUndefined()
  })

  it('uses containment, not equality — a connector sentence around the card text still collapses it', () => {
    const poem = 'Roses are red, violets are blue.'
    const result = markRedundantTaskPlanProse(
      plan([{ nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: poem }]),
      [`Here is your poem:\n\n${poem}\n\nEnjoy!`]
    )
    expect(result?.cards[0].redundant).toBe(true)
  })

  it('tolerates whitespace differences between card and body', () => {
    const result = markRedundantTaskPlanProse(
      plan([
        { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: 'The  answer\n' },
      ]),
      ['The answer']
    )
    expect(result?.cards[0].redundant).toBe(true)
  })

  it('keeps a card with UNIQUE content untouched (short connector reply)', () => {
    const p = plan([
      {
        nodeId: 'n1',
        capability: 'chat',
        kind: 'text',
        state: 'done',
        text: 'A long unique poem that only lives in the card.',
      },
    ])
    const result = markRedundantTaskPlanProse(p, ['Here is the poem and a document for you.'])

    expect(result).toBe(p)
    expect(result?.cards[0].redundant).toBeUndefined()
  })

  it('never downgrades an already-set backend flag', () => {
    const p = plan([
      {
        nodeId: 'n1',
        capability: 'chat',
        kind: 'text',
        state: 'done',
        text: 'Unique text',
        redundant: true,
      },
    ])
    const result = markRedundantTaskPlanProse(p, ['Completely different body'])

    expect(result?.cards[0].redundant).toBe(true)
  })
})
