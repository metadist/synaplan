import { describe, expect, it } from 'vitest'
import { groupProviderTranscript } from '@/utils/providerTranscriptView'

describe('groupProviderTranscript', () => {
  it('puts the last question first in the round and keeps earlier turns as history', () => {
    const rounds = groupProviderTranscript([
      { role: 'instructions', text: 'Be concise.' },
      { role: 'you', text: 'Earlier question' },
      { role: 'assistant', text: 'Earlier answer' },
      { role: 'you', text: 'Latest question' },
    ])

    expect(rounds).toEqual([
      {
        instructions: ['Be concise.'],
        history: [
          { role: 'you', text: 'Earlier question' },
          { role: 'assistant', text: 'Earlier answer' },
        ],
        question: 'Latest question',
        after: [],
      },
    ])
  })

  it('keeps a tool result that was sent after the question', () => {
    const rounds = groupProviderTranscript([
      { role: 'instructions', text: 'Edit the document.' },
      { role: 'you', text: 'Update cell A1' },
      { role: 'assistant', text: 'Cell A1 updated' },
    ])

    expect(rounds[0]?.question).toBe('Update cell A1')
    expect(rounds[0]?.after).toEqual([{ role: 'assistant', text: 'Cell A1 updated' }])
    expect(rounds[0]?.history).toEqual([])
  })

  it('splits a later provider call into its own round and drops a repeated instruction', () => {
    const rounds = groupProviderTranscript([
      { role: 'instructions', text: 'Edit the sheet.' },
      { role: 'instructions', text: 'Edit the sheet.' },
      { role: 'you', text: 'Add a total row.' },
      { role: 'instructions', text: 'Edit the sheet.' },
      { role: 'you', text: 'Add a total row.' },
      { role: 'assistant', text: 'Updated A10' },
    ])

    expect(rounds).toHaveLength(2)
    expect(rounds[0]?.instructions).toEqual(['Edit the sheet.'])
    expect(rounds[0]?.question).toBe('Add a total row.')
    expect(rounds[0]?.after).toEqual([])
    expect(rounds[1]?.question).toBe('Add a total row.')
    expect(rounds[1]?.after).toEqual([{ role: 'assistant', text: 'Updated A10' }])
  })

  it('returns nothing when no text was recorded', () => {
    expect(groupProviderTranscript(null)).toEqual([])
    expect(groupProviderTranscript([])).toEqual([])
    expect(groupProviderTranscript([{ role: 'you', text: '   ' }])).toEqual([])
  })
})
