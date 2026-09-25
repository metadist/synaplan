import { describe, expect, it } from 'vitest'
import { continueOutcomeAfterRecheck } from '@/utils/chatAccessError'

describe('continueOutcomeAfterRecheck', () => {
  it('treats a closed chat as already released', () => {
    expect(continueOutcomeAfterRecheck(false, null)).toBe('released')
  })

  it('keeps a still-readable chat open', () => {
    expect(continueOutcomeAfterRecheck(true, 'read')).toBe('read-only')
  })

  it('asks to retry when the chat is still usable', () => {
    expect(continueOutcomeAfterRecheck(true, 'use')).toBe('failed')
    expect(continueOutcomeAfterRecheck(true, 'owner')).toBe('failed')
    expect(continueOutcomeAfterRecheck(true, null)).toBe('failed')
  })
})
