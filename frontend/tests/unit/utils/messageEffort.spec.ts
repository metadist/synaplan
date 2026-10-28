import { describe, expect, it } from 'vitest'
import { adoptEffort, effortFields, type MessageEffort } from '@/utils/messageEffort'

describe('effortFields', () => {
  it('keeps the effort of an assistant row', () => {
    expect(effortFields('assistant', { effort: 'lookup', effortNotice: 'Budget spent.' })).toEqual({
      effort: 'lookup',
      effortNotice: 'Budget spent.',
    })
  })

  it('fills null for a row without effort and for every user turn', () => {
    expect(effortFields('assistant', {})).toEqual({ effort: null, effortNotice: null })
    expect(effortFields('user', { effort: 'research' })).toEqual({
      effort: null,
      effortNotice: null,
    })
  })
})

describe('adoptEffort', () => {
  it('carries the persisted effort and notice onto the local reply', () => {
    const local: MessageEffort = {}
    adoptEffort(local, { effort: 'research', effortNotice: 'Off for now.' })

    expect(local).toEqual({ effort: 'research', effortNotice: 'Off for now.' })
  })

  it('leaves the local reply alone when the persisted one has no effort', () => {
    const local: MessageEffort = { effort: 'lookup', effortNotice: null }
    adoptEffort(local, { effort: null, effortNotice: 'stale' })

    expect(local).toEqual({ effort: 'lookup', effortNotice: null })
  })
})
