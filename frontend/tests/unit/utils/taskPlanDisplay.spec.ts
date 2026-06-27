import { describe, it, expect } from 'vitest'
import { dedupeTaskPlanProse } from '@/utils/taskPlanDisplay'
import type { TaskPlanState } from '@/stores/history'

const plan = (cards: TaskPlanState['cards']): TaskPlanState => ({
  active: false,
  replyNode: 'n3',
  cards,
})

describe('dedupeTaskPlanProse', () => {
  it('returns null for a missing plan', () => {
    expect(dedupeTaskPlanProse(null, ['anything'])).toBeNull()
    expect(dedupeTaskPlanProse(undefined, ['anything'])).toBeNull()
  })

  it('returns the same plan untouched when the body has no text', () => {
    const p = plan([
      { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: 'Poem' },
    ])
    expect(dedupeTaskPlanProse(p, [])).toBe(p)
    expect(dedupeTaskPlanProse(p, ['   '])).toBe(p)
  })

  it('clears a card whose text is duplicated by the body answer (poem → TTS)', () => {
    const poem = 'Roses are red,\nviolets are blue.'
    const result = dedupeTaskPlanProse(
      plan([
        { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: poem },
        { nodeId: 'n2', capability: 'text2sound', kind: 'audio', state: 'failed' },
      ]),
      [poem]
    )

    expect(result?.cards[0].text).toBe('')
    // The audio card is untouched (no prose to dedupe).
    expect(result?.cards[1].capability).toBe('text2sound')
  })

  it('tolerates trailing-whitespace differences between card and body', () => {
    const result = dedupeTaskPlanProse(
      plan([
        { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: 'The answer\n' },
      ]),
      ['The answer']
    )
    expect(result?.cards[0].text).toBe('')
  })

  it('keeps an intermediate card whose text is not the final answer (summarize → translate)', () => {
    // Reply node passes the translation through; the summary only lives on its
    // own card and must stay visible.
    const summary = 'Short summary of the note.'
    const translation = 'Kurze Zusammenfassung der Notiz.'
    const result = dedupeTaskPlanProse(
      plan([
        { nodeId: 'n1', capability: 'summarize', kind: 'text', state: 'done', text: summary },
        { nodeId: 'n2', capability: 'translate', kind: 'text', state: 'done', text: translation },
      ]),
      [translation]
    )

    expect(result?.cards[0].text).toBe(summary)
    expect(result?.cards[1].text).toBe('')
  })

  it('returns the same reference when nothing matches', () => {
    const p = plan([
      { nodeId: 'n1', capability: 'chat', kind: 'text', state: 'done', text: 'Something else' },
    ])
    expect(dedupeTaskPlanProse(p, ['A different body answer'])).toBe(p)
  })

  it('returns the same plan when it has no cards', () => {
    const p = plan([])
    expect(dedupeTaskPlanProse(p, ['body'])).toBe(p)
  })
})
