import { describe, expect, it } from 'vitest'
import { adoptEffort, effortFields, type MessageEffort } from '@/utils/messageEffort'

describe('effortFields', () => {
  it('keeps the effort and the research run of an assistant row', () => {
    expect(
      effortFields('assistant', {
        effort: 'research',
        effortNotice: 'Budget spent.',
        researchRunId: 'rr_1',
      })
    ).toEqual({
      effort: 'research',
      effortNotice: 'Budget spent.',
      researchRunId: 'rr_1',
    })
  })

  it('fills null for a row without effort and for every user turn', () => {
    expect(effortFields('assistant', {})).toEqual({
      effort: null,
      effortNotice: null,
      researchRunId: null,
    })
    expect(effortFields('user', { effort: 'research', researchRunId: 'rr_1' })).toEqual({
      effort: null,
      effortNotice: null,
      researchRunId: null,
    })
  })
})

describe('adoptEffort', () => {
  it('carries the persisted effort, notice and run onto the local reply', () => {
    const local: MessageEffort = {}
    adoptEffort(local, { effort: 'research', effortNotice: 'Off for now.', researchRunId: 'rr_1' })

    expect(local).toEqual({
      effort: 'research',
      effortNotice: 'Off for now.',
      researchRunId: 'rr_1',
    })
  })

  it('leaves the local reply alone when the persisted one has no effort', () => {
    const local: MessageEffort = { effort: 'lookup', effortNotice: null }
    adoptEffort(local, { effort: null, effortNotice: 'stale' })

    expect(local).toEqual({ effort: 'lookup', effortNotice: null })
  })
})
